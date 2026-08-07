<?php

namespace Tests\Feature;

use App\Models\KunciGemini;
use App\Models\User;
use App\Services\Ai\RotasiKunciGemini;
use Carbon\CarbonInterface;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\TestCase;

/**
 * Rotasi kunci API Gemini saat kuota habis.
 *
 * Yang diuji di sini kegagalan yang tidak menimbulkan galat apa pun sampai
 * tagihan atau kuota habis: kunci limit yang terus dicoba ulang, waktu pulih
 * yang ditebak alih-alih dibaca dari jawaban Google, dan kunci kedua yang
 * tidak pernah benar-benar terpakai karena provider-nya sudah tersimpan di
 * memori dengan kunci lama.
 */
class RotasiKunciGeminiTest extends TestCase
{
    use RefreshDatabase;

    private RotasiKunciGemini $rotasi;

    protected function setUp(): void
    {
        parent::setUp();

        // Kolom timestamp Postgres di sini berpresisi detik, jadi waktu beku
        // yang masih membawa mikrodetik tidak akan pernah sama dengan yang
        // dibaca kembali dari database.
        Carbon::setTestNow(now()->startOfSecond());

        // Hitungan jatah per kunci disimpan di cache, dan store array bertahan
        // selama proses PHPUnit hidup. Tanpa ini sisa hitungan dari satu tes
        // ikut terbawa ke tes berikutnya.
        Cache::flush();

        $this->rotasi = app(RotasiKunciGemini::class);
    }

    public function test_kunci_yang_kena_limit_dilewati_dan_kunci_berikutnya_dipakai(): void
    {
        $pertama = $this->kunci('Kunci A');
        $kedua = $this->kunci('Kunci B');

        $dipakai = [];

        $hasil = $this->rotasi->jalankan(function () use (&$dipakai) {
            $dipakai[] = config('ai.providers.gemini.key');

            if (count($dipakai) === 1) {
                throw $this->limit('GenerateRequestsPerMinutePerProjectPerModel-FreeTier');
            }

            return 'selesai';
        });

        $this->assertSame('selesai', $hasil);
        $this->assertSame(['kunci-kunci-a', 'kunci-kunci-b'], $dipakai);
        $this->assertTrue($pertama->fresh()->sedangLimit());
        $this->assertFalse($kedua->fresh()->sedangLimit());
    }

    /**
     * Kunci yang sudah ditandai tidak boleh dicoba lagi sebelum waktunya.
     *
     * Tanpa ini, rotasi hanya memindahkan masalah: setiap artikel berikutnya
     * tetap membayar satu permintaan gagal ke kunci yang sudah pasti menolak.
     */
    public function test_kunci_yang_masih_limit_tidak_dipanggil_lagi(): void
    {
        $this->kunci('Kunci A', limitSampai: now()->addMinutes(10));
        $this->kunci('Kunci B');

        $dipakai = [];

        $this->rotasi->jalankan(function () use (&$dipakai) {
            $dipakai[] = config('ai.providers.gemini.key');

            return 'selesai';
        });

        $this->assertSame(['kunci-kunci-b'], $dipakai);
    }

    /** Limit harian pulih pada tengah malam waktu Pasifik, bukan 24 jam setelah pemakaian. */
    public function test_limit_harian_ditandai_sampai_tengah_malam_pasifik(): void
    {
        $kunci = $this->kunci('Kunci A');

        try {
            $this->rotasi->jalankan(fn () => throw $this->limit('GenerateRequestsPerDayPerProjectPerModel-FreeTier'));
        } catch (RateLimitedException) {
            // Kunci tunggal yang habis memang berakhir sebagai galat.
        }

        $kunci->refresh();

        $this->assertSame('kuota_harian', $kunci->alasan_limit);
        $this->assertTrue(
            $kunci->limit_sampai->equalTo(Carbon::tomorrow('America/Los_Angeles')),
            'Waktu pulih harus tengah malam berikutnya di zona kuota Google.',
        );
    }

    /** `retryDelay` dari Google dipakai apa adanya, selama tidak lebih pendek dari batas bawah. */
    public function test_jeda_dari_google_dipakai_untuk_limit_per_menit(): void
    {
        $kunci = $this->kunci('Kunci A');

        try {
            $this->rotasi->jalankan(fn () => throw $this->limit(
                'GenerateRequestsPerMinutePerProjectPerModel-FreeTier',
                retryDelay: '90s',
            ));
        } catch (RateLimitedException) {
            // Sama seperti di atas.
        }

        $this->assertSame('retry_delay', $kunci->fresh()->alasan_limit);
        $this->assertTrue($kunci->fresh()->limit_sampai->equalTo(now()->addSeconds(90)));
    }

    public function test_semua_kunci_habis_menyebut_kapan_kuotanya_terbuka(): void
    {
        $this->kunci('Kunci A', limitSampai: now()->addHours(3));

        $this->expectException(RateLimitedException::class);
        $this->expectExceptionMessageMatches('/Semua kunci API Gemini sedang kena limit/');

        $this->rotasi->jalankan(fn () => 'tidak boleh sampai sini');
    }

    /**
     * Tanpa kunci, galatnya menyebutkan cara memperbaikinya.
     *
     * Sejak `.env` tidak lagi memuat GEMINI_API_KEY, tabel kosong berarti
     * sistem memang belum bisa memanggil Gemini. Membiarkan panggilan berjalan
     * tanpa kunci hanya menukar galat yang bisa ditindaklanjuti dengan 401 dari
     * Google yang tidak menyebutkan apa pun tentang halaman Pengaturan.
     */
    public function test_tanpa_kunci_di_database_panggilan_ditolak(): void
    {
        $tersentuh = false;

        try {
            $this->rotasi->jalankan(function () use (&$tersentuh) {
                $tersentuh = true;

                return 'selesai';
            });
            $this->fail('Panggilan tanpa kunci seharusnya dilempar sebagai galat.');
        } catch (RateLimitedException $galat) {
            $this->assertStringContainsString('Tidak ada kunci API Gemini', $galat->getMessage());
        }

        $this->assertFalse($tersentuh, 'Gemini tidak boleh dipanggil tanpa kunci.');
    }

    /**
     * Batas per menit ditahan sendiri, tanpa menunggu 429 dari Google.
     *
     * Permintaan yang ditolak tetap dihitung Google sebagai permintaan. Kalau
     * batasnya baru diketahui dari jawaban 429, klasifikasi manual yang ditekan
     * berturut-turut membakar kuota harian tanpa satu pun hasil.
     */
    public function test_batas_per_menit_ditahan_sebelum_permintaan_dikirim(): void
    {
        config(['ai.batas_kunci.rpm' => 3]);

        $kunci = $this->kunci('Kunci A');

        $jumlah = 0;

        for ($i = 0; $i < 3; $i++) {
            $this->rotasi->jalankan(function () use (&$jumlah) {
                $jumlah++;

                return 'selesai';
            });
        }

        try {
            $this->rotasi->jalankan(fn () => $jumlah++);
            $this->fail('Permintaan keempat seharusnya ditahan.');
        } catch (RateLimitedException) {
            // Kunci tunggal yang jatahnya habis memang berakhir sebagai galat.
        }

        $this->assertSame(3, $jumlah, 'Gemini tidak boleh dipanggil melewati batas per menit.');
        $this->assertSame('kuota_menit_lokal', $kunci->fresh()->alasan_limit);

        // Ditahan sampai slot tertua kedaluwarsa, yaitu 60 detik setelah ia
        // dipakai, bukan sampai jarum menit berikutnya. Menunggu jarum menit
        // berarti kunci yang penuh pada detik ke-59 dilepas sedetik kemudian
        // dengan jendela yang sebenarnya masih penuh.
        $this->assertEqualsWithDelta(
            60,
            now()->diffInSeconds($kunci->fresh()->limit_sampai),
            2,
        );
    }

    /**
     * Jendela geser, bukan menit kalender.
     *
     * Dengan menit kalender, lima belas permintaan pada detik ke-59 lalu lima
     * belas lagi pada detik ke-01 semuanya sah menurut hitungan lokal, padahal
     * Google melihat tiga puluh permintaan dalam dua detik. Uji jalan antrean
     * pertama mengirim 109 permintaan untuk 19 artikel gara-gara celah ini.
     */
    public function test_jendela_menit_tidak_ikut_pindah_saat_jarum_menit_berganti(): void
    {
        config(['ai.batas_kunci.rpm' => 2]);

        $this->kunci('Kunci A');

        $jumlah = 0;
        $panggil = function () use (&$jumlah) {
            $jumlah++;

            return 'selesai';
        };

        $this->rotasi->jalankan($panggil);
        $this->rotasi->jalankan($panggil);

        // Jarum menit berganti, tetapi 60 detik sesungguhnya belum lewat.
        Carbon::setTestNow(now()->addMinute()->startOfMinute());

        try {
            $this->rotasi->jalankan($panggil);
            $this->fail('Jendela tidak boleh ikut kosong hanya karena jarum menit berganti.');
        } catch (RateLimitedException) {
            // Kunci tunggal yang jendelanya penuh memang berakhir sebagai galat.
        }

        $this->assertSame(2, $jumlah);
    }

    /** Jatah dihitung per kunci, jadi kunci kedua tetap terpakai saat yang pertama penuh. */
    public function test_kunci_berikutnya_dipakai_saat_jatah_kunci_pertama_habis(): void
    {
        config(['ai.batas_kunci.rpm' => 1]);

        $this->kunci('Kunci A');
        $this->kunci('Kunci B');

        $dipakai = [];

        $panggil = function () use (&$dipakai) {
            $dipakai[] = config('ai.providers.gemini.key');

            return 'selesai';
        };

        $this->rotasi->jalankan($panggil);
        $this->rotasi->jalankan($panggil);

        $this->assertSame(['kunci-kunci-a', 'kunci-kunci-b'], $dipakai);
    }

    /** Batas harian menahan sampai tengah malam Pasifik, bukan sampai menit berikutnya. */
    public function test_batas_harian_menahan_sampai_tengah_malam_pasifik(): void
    {
        config(['ai.batas_kunci.rpd' => 1]);

        $kunci = $this->kunci('Kunci A');

        $this->rotasi->jalankan(fn () => 'selesai');

        try {
            $this->rotasi->jalankan(fn () => $this->fail('Tidak boleh sampai sini.'));
        } catch (RateLimitedException) {
            // Sama seperti di atas.
        }

        $kunci->refresh();

        $this->assertSame('kuota_harian_lokal', $kunci->alasan_limit);
        $this->assertTrue($kunci->limit_sampai->equalTo(Carbon::tomorrow('America/Los_Angeles')));
    }

    /**
     * Uji kunci memakai kuota, jadi pemakaiannya harus ikut tercatat.
     *
     * Satu ketukan adalah satu permintaan yang dihitung Google sama seperti
     * permintaan penilaian. Penjaga kuota yang tidak menghitungnya akan
     * mengizinkan satu permintaan berlebih untuk setiap kali tombol ditekan,
     * dan tepat pada hari kuota mepet itulah tombol ini paling sering dipakai.
     */
    public function test_uji_kunci_ikut_menghabiskan_jatah_per_menit(): void
    {
        config(['ai.batas_kunci.rpm' => 1]);

        $kunci = $this->kunci('Kunci A');

        // Panggilan ke Gemini memang gagal di lingkungan tes karena kuncinya
        // karangan. Yang diuji di sini bukan jawabannya, melainkan bahwa slotnya
        // sudah dipakai sebelum permintaan dikirim.
        app(RotasiKunciGemini::class)->uji($kunci, 'gemini-3.5-flash-lite');

        $terpakai = app(RotasiKunciGemini::class)->pemakaianMenit();

        $this->assertSame(1, $terpakai[$kunci->id] ?? 0);
    }

    /** Kunci yang dimatikan ditolak tanpa membuang satu permintaan pun. */
    public function test_uji_kunci_yang_dimatikan_tidak_memanggil_gemini(): void
    {
        $kunci = KunciGemini::create([
            'label' => 'Kunci mati',
            'kunci' => 'kunci-mati',
            'aktif' => false,
        ]);

        $hasil = app(RotasiKunciGemini::class)->uji($kunci, 'gemini-3.5-flash-lite');

        $this->assertFalse($hasil['berhasil']);
        $this->assertStringContainsString('dimatikan', $hasil['galat']);
        $this->assertSame([], app(RotasiKunciGemini::class)->pemakaianMenit());
    }

    /**
     * Galat menempel pada kunci yang menolak, bukan hanya di log aplikasi.
     *
     * Dengan tiga kunci, satu kunci yang salah ketik hanya terbaca sebagai
     * "klasifikasi kadang gagal". Log tidak menyebutkan kunci mana, jadi tidak
     * ada yang tahu kunci mana yang harus diganti.
     */
    public function test_galat_dicatat_pada_kunci_yang_menolak(): void
    {
        $pertama = $this->kunci('Kunci A');
        $kedua = $this->kunci('Kunci B');

        $dipakai = 0;

        $this->rotasi->jalankan(function () use (&$dipakai) {
            $dipakai++;

            if ($dipakai === 1) {
                throw $this->limit('GenerateRequestsPerMinutePerProjectPerModel-FreeTier');
            }

            return 'selesai';
        });

        $this->assertNotNull($pertama->fresh()->galat_terakhir);
        $this->assertNotNull($pertama->fresh()->galat_at);
        $this->assertNull($kedua->fresh()->galat_terakhir, 'Kunci yang berhasil tidak boleh ikut ditandai.');
    }

    /**
     * Peringatan galat hilang sendiri begitu kuncinya berhasil dipakai lagi.
     *
     * Peringatan yang menempel selamanya pada kunci yang sudah lama pulih
     * membuat admin mengabaikan seluruh peringatan di halaman itu.
     */
    public function test_galat_lama_tercabut_saat_kunci_berhasil_dipakai(): void
    {
        $kunci = $this->kunci('Kunci A');

        $kunci->forceFill([
            'galat_terakhir' => 'API key not valid. Please pass a valid API key.',
            'galat_at' => now()->subHour(),
        ])->saveQuietly();

        $this->rotasi->jalankan(fn () => 'selesai');

        $this->assertNull($kunci->fresh()->galat_terakhir);
        $this->assertNull($kunci->fresh()->galat_at);
    }

    /**
     * Uji kunci dibatasi 15 detik, ditegakkan di server.
     *
     * Tombol yang diredupkan bukan aturan: permintaannya tetap bisa dikirim
     * langsung, dan satu ketukan adalah satu permintaan yang dihitung Google
     * sama seperti permintaan penilaian artikel.
     */
    public function test_uji_kunci_menolak_ketukan_kedua_dalam_15_detik(): void
    {
        $kunci = $this->kunci('Kunci A');

        app(RotasiKunciGemini::class)->uji($kunci, 'gemini-3.5-flash-lite');

        $kedua = app(RotasiKunciGemini::class)->uji($kunci, 'gemini-3.5-flash-lite');

        $this->assertFalse($kedua['berhasil']);
        $this->assertStringContainsString('Tunggu', $kedua['galat']);
        $this->assertGreaterThan(0, app(RotasiKunciGemini::class)->sisaJedaUji($kunci));

        // Jatah per menit tidak ikut terpakai, karena permintaannya memang
        // tidak pernah dikirim.
        $this->assertSame(1, app(RotasiKunciGemini::class)->pemakaianMenit()[$kunci->id] ?? 0);
    }

    /**
     * Hitungan harian bertahan meski cache dibersihkan.
     *
     * Angkanya dulu hidup di Redis, dan `cache:clear` saat deploy atau Redis
     * yang dijatuhkan mengembalikannya ke nol. Sejak detik itu layar melaporkan
     * kuota utuh untuk kunci yang tinggal beberapa permintaan lagi, dan penjaga
     * kuota membaca angka yang sama lalu melepas permintaan yang sudah pasti
     * dijawab 429.
     */
    public function test_hitungan_harian_selamat_dari_cache_yang_dibersihkan(): void
    {
        $kunci = $this->kunci('Kunci A');

        $this->rotasi->jalankan(fn () => 'selesai');
        $this->rotasi->jalankan(fn () => 'selesai');

        Cache::flush();

        $this->assertSame(2, app(RotasiKunciGemini::class)->terpakaiHarian($kunci->fresh()));
    }

    /** Hitungan kemarin tidak boleh ikut terbaca sebagai hitungan hari ini. */
    public function test_hitungan_hari_kemarin_tidak_ikut_terbawa(): void
    {
        $kunci = $this->kunci('Kunci A');

        // Baris sisa kemarin, persis seperti yang ditinggalkan crawl semalam.
        $kunci->forceFill([
            'rpd_terpakai' => 400,
            'rpd_hari' => Carbon::yesterday('America/Los_Angeles')->toDateString(),
        ])->save();

        $this->assertSame(0, $this->rotasi->terpakaiHarian($kunci));

        $this->rotasi->jalankan(fn () => 'selesai');

        // Ditimpa, bukan ditambahkan ke 400.
        $this->assertSame(1, $this->rotasi->terpakaiHarian($kunci->fresh()));
    }

    /**
     * Halaman Pengaturan terbuka untuk kunci yang batas hariannya sudah
     * disebutkan Google.
     *
     * `rpd_google_at` sempat tidak terdaftar di casts model, jadi Eloquent
     * memulangkan string mentah dan halamannya mati dengan
     * "Call to a member function toIso8601String() on string". Tidak ketahuan
     * karena kolomnya null sampai kunci pertama kehabisan kuota harian, dan
     * `?->` melewati string kosong itu tanpa keluhan.
     */
    public function test_halaman_pengaturan_terbuka_setelah_google_menyebut_batas_hariannya(): void
    {
        $kunci = $this->kunci('Kunci A');
        $kunci->forceFill(['rpd_google' => 250, 'rpd_google_at' => now()])->save();

        $this->actingAs(User::factory()->create())
            ->get('/admin/pengaturan')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('kunci.0.rpd_google', 250));
    }

    private function kunci(string $label, ?CarbonInterface $limitSampai = null): KunciGemini
    {
        return KunciGemini::create([
            'label' => $label,
            'kunci' => str($label)->slug()->prepend('kunci-')->value(),
            'aktif' => true,
            'limit_sampai' => $limitSampai,
        ]);
    }

    /** Galat 429 dengan badan seperti yang benar-benar dikirim Google. */
    private function limit(string $quotaId, ?string $retryDelay = null): RateLimitedException
    {
        $rincian = [[
            '@type' => 'type.googleapis.com/google.rpc.QuotaFailure',
            'violations' => [['quotaId' => $quotaId, 'quotaMetric' => 'generativelanguage.googleapis.com/generate_content_free_tier_requests']],
        ]];

        if ($retryDelay !== null) {
            $rincian[] = ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $retryDelay];
        }

        $tanggapan = new Response(new PsrResponse(429, ['Content-Type' => 'application/json'], json_encode([
            'error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'details' => $rincian],
        ])));

        return RateLimitedException::forProvider('gemini', 429, new RequestException($tanggapan));
    }
}
