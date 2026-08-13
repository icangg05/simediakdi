<?php

namespace Tests\Feature;

use App\Jobs\KlasifikasiGemini;
use App\Models\AnalisisSentimen;
use App\Models\AntreanGemini;
use App\Models\Artikel;
use App\Models\KunciGemini;
use App\Models\Media;
use App\Models\PengaturanAi;
use App\Models\User;
use App\Services\Ai\KlasifikasiArtikel;
use App\Services\Ai\RotasiKunciGemini;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Antrean klasifikasi Gemini otomatis.
 *
 * Yang diuji di sini kegagalan yang tidak menimbulkan galat apa pun sampai
 * kuota sebulan habis: artikel yang mengantre dua kali, prioritas yang tidak
 * dipatuhi, dan artikel yang sudah dinilai Gemini ikut tertarik masuk lagi.
 */
class AntreanGeminiTest extends TestCase
{
    use RefreshDatabase;

    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->media = Media::create([
            'nama' => 'Media Contoh',
            'slug' => 'media-contoh',
            'domain' => 'contoh.id',
            'aktif' => true,
        ]);
    }

    /** Ketiga kelompok masuk antrean dengan nomor prioritas yang benar. */
    public function test_pengisi_antrean_menandai_prioritas_sesuai_kelompoknya(): void
    {
        $baru = $this->artikel('Belum pernah dinilai');

        $lamaRelevan = $this->artikel('Relevan dari pipeline lama');
        $this->analisis($lamaRelevan, relevan: true, label: 'positif', provider: null);

        $lamaTidak = $this->artikel('Tidak relevan dari pipeline lama', status: 'tidak_relevan');
        $this->analisis($lamaTidak, relevan: false, label: null, provider: null);

        $this->artisan('gemini:antre', ['--isi' => true, '--batas' => 0]);

        $this->assertSame(1, AntreanGemini::where('artikel_id', $baru->id)->value('prioritas'));
        $this->assertSame(2, AntreanGemini::where('artikel_id', $lamaRelevan->id)->value('prioritas'));
        $this->assertSame(3, AntreanGemini::where('artikel_id', $lamaTidak->id)->value('prioritas'));
    }

    /**
     * Artikel yang sudah dinilai Gemini tidak ditarik masuk lagi.
     *
     * Tanpa penjaga ini antrean menilai ulang seluruh arsip setiap jam, dan
     * kuota harian habis untuk menghasilkan jawaban yang sudah ada.
     */
    public function test_artikel_yang_sudah_dinilai_gemini_tidak_masuk_antrean(): void
    {
        $sudah = $this->artikel('Sudah dinilai Gemini');
        $this->analisis($sudah, relevan: true, label: 'netral', provider: 'gemini');

        $this->artisan('gemini:antre', ['--isi' => true, '--batas' => 0]);

        $this->assertDatabaseCount('antrean_gemini', 0);
    }

    /**
     * Artikel yang menunggu keputusan manusia tidak diambil alih mesin.
     *
     * Di tahap itulah penilainya menolak memutuskan. Yang ditunggu tombol
     * Relevan atau Tidak dari admin, bukan percobaan kedua dari mesin yang sama
     * dengan prompt yang sama.
     */
    public function test_artikel_menunggu_review_dikecualikan(): void
    {
        $ragu = $this->artikel('Menunggu keputusan manusia', status: 'perlu_review');
        $this->analisis($ragu, relevan: false, label: null, provider: null);

        $this->artisan('gemini:antre', ['--isi' => true, '--batas' => 0]);

        $this->assertDatabaseCount('antrean_gemini', 0);
    }

    /**
     * Menjalankan pengisi dua kali tidak menggandakan barisnya.
     *
     * Perintah ini dijadwalkan tiap jam dan selalu menemukan kandidat yang sama
     * selama pekerjaannya belum jalan. Tanpa kunci unik pada `artikel_id`, satu
     * artikel mengantre sekali per jam sampai antreannya sempat tersentuh.
     */
    public function test_pengisi_yang_berjalan_dua_kali_tidak_menggandakan_antrean(): void
    {
        $this->artikel('Belum pernah dinilai');

        $this->artisan('gemini:antre', ['--isi' => true, '--batas' => 0]);
        $this->artisan('gemini:antre', ['--isi' => true, '--batas' => 0]);

        $this->assertDatabaseCount('antrean_gemini', 1);
    }

    /** Prioritas kecil dilepas lebih dulu, dan hanya sebanyak batasnya. */
    public function test_pelepasan_mengikuti_prioritas_dan_menghormati_batas(): void
    {
        Queue::fake();

        $tidakRelevan = $this->artikel('Prioritas tiga', status: 'tidak_relevan');
        $this->analisis($tidakRelevan, relevan: false, label: null, provider: null);

        $belum = $this->artikel('Prioritas satu');

        $this->artisan('gemini:antre', ['--isi' => true, '--batas' => 1]);

        Queue::assertPushed(KlasifikasiGemini::class, 1);

        $this->assertNotNull(AntreanGemini::where('artikel_id', $belum->id)->value('dijadwalkan_at'));
        $this->assertNull(AntreanGemini::where('artikel_id', $tidakRelevan->id)->value('dijadwalkan_at'));
    }

    /**
     * Pekerjaan yang menunda dirinya karena kuota tidak dilepas dua kali.
     *
     * Ia masih hidup di Redis menunggu gilirannya, jadi melepasnya lagi berarti
     * satu artikel dinilai dua kali dan membayar dua kali.
     */
    public function test_pekerjaan_yang_sudah_dilepas_tidak_dilepas_lagi(): void
    {
        Queue::fake();

        $this->artikel('Belum pernah dinilai');

        $this->artisan('gemini:antre', ['--isi' => true, '--batas' => 5]);
        $this->artisan('gemini:antre', ['--batas' => 5]);

        Queue::assertPushed(KlasifikasiGemini::class, 1);
    }

    /**
     * Kuota habis menunda pekerjaan, bukan menggagalkannya.
     *
     * Kegagalan menghabiskan jatah percobaan, dan kuota harian yang habis pada
     * sore hari akan membakar ketiga jatah setiap artikel sebelum tengah malam.
     * Esok paginya antreannya kosong bukan karena selesai, melainkan karena
     * semuanya sudah menyerah.
     */
    public function test_kuota_habis_menunda_pekerjaan_tanpa_menambah_percobaan(): void
    {
        $artikel = $this->artikel('Belum pernah dinilai');

        KunciGemini::create([
            'label' => 'Kunci A',
            'kunci' => 'kunci-a',
            'aktif' => true,
            'limit_sampai' => now()->addMinutes(10),
        ]);

        $baris = AntreanGemini::create([
            'artikel_id' => $artikel->id,
            'prioritas' => 1,
            'status' => 'menunggu',
        ]);

        $job = new KlasifikasiGemini($baris->id);
        $job->handle(app(KlasifikasiArtikel::class), app(RotasiKunciGemini::class));

        $baris->refresh();

        $this->assertSame('menunggu', $baris->status);
        $this->assertSame(0, $baris->percobaan, 'Menunggu kuota bukan kegagalan.');
    }

    /**
     * Pekerjaan yang menunggu kuota tidur sebentar, bukan sampai jam reset.
     *
     * Dulu ia tidur selama sisa waktu menuju tengah malam waktu Pasifik, dan itu
     * bisa tujuh jam. Selama tidur barisnya tetap terhitung menggantung oleh
     * `gemini:antre`, jadi dua puluh pekerjaan yang tidur memenuhi seluruh jatah
     * gantung dan tidak ada pekerjaan baru yang dilepas. Antreannya membeku, dan
     * kunci baru yang ditambahkan admin tidak membangunkan satu pun dari mereka
     * sementara tombol Klasifikasi di layar bekerja normal dengan kunci itu.
     */
    public function test_penundaan_kuota_dibatasi_agar_kunci_baru_terpakai(): void
    {
        $artikel = $this->artikel('Belum pernah dinilai');

        KunciGemini::create([
            'label' => 'Kunci A',
            'kunci' => 'kunci-a',
            'aktif' => true,
            // Kuota harian habis, pulihnya masih tujuh jam lagi.
            'limit_sampai' => now()->addHours(7),
        ]);

        $baris = AntreanGemini::create([
            'artikel_id' => $artikel->id,
            'prioritas' => 1,
            'status' => 'menunggu',
        ]);

        (new KlasifikasiGemini($baris->id))->handle(
            app(KlasifikasiArtikel::class),
            app(RotasiKunciGemini::class),
        );

        $this->assertLessThanOrEqual(
            300,
            now()->diffInSeconds($baris->refresh()->dijadwalkan_at, absolute: true),
            'Pekerjaan harus memeriksa ulang paling lama lima menit lagi, bukan menunggu jam reset.',
        );
    }

    /**
     * Pekerjaan dilepas berjarak, bukan berbarengan.
     *
     * Pada uji jalan pertama, 109 permintaan terkirim untuk 19 artikel yang
     * berhasil. Sekitar tujuh puluh di antaranya dijawab 429 karena dua puluh
     * pekerjaan berdesakan di sepuluh detik pertama, dan Google tetap
     * menghitung permintaan yang ditolaknya.
     */
    public function test_pekerjaan_dilepas_berjarak_bukan_berbarengan(): void
    {
        Queue::fake();

        KunciGemini::create(['label' => 'Kunci A', 'kunci' => 'kunci-a', 'aktif' => true]);

        foreach (range(1, 3) as $nomor) {
            $this->artikel("Belum pernah dinilai {$nomor}");
        }

        config(['ai.antrean.jeda_detik' => 60]);

        $this->artisan('gemini:antre', ['--isi' => true, '--batas' => 3]);

        $jadwal = AntreanGemini::orderBy('id')->pluck('dijadwalkan_at');

        $this->assertSame(60, (int) $jadwal[0]->diffInSeconds($jadwal[1]));
        $this->assertSame(60, (int) $jadwal[1]->diffInSeconds($jadwal[2]));
    }

    /**
     * Baris menunggu yang jobnya sudah lenyap dibebaskan, bukan didiamkan.
     *
     * Kebuntuan ini benar-benar terjadi dan mendiamkan antrean produksi sehari
     * penuh. Dua puluh baris tertinggal berstatus menunggu dengan
     * `dijadwalkan_at` terisi setelah jobnya hilang dari tabel `jobs`. Ketiganya
     * berkonspirasi: jatah gantung habis dipakai baris itu, `siapDiambil()`
     * mengecualikannya karena mengira ia masih hidup, dan penyapu lama hanya
     * menyentuh yang berjalan. Tiga ribu artikel siap kerja tidak pernah
     * dilepas, tanpa satu pun pesan galat.
     */
    public function test_pekerjaan_menunggu_tanpa_job_dibebaskan(): void
    {
        Queue::fake();

        $artikel = $this->artikel('Belum pernah dinilai');

        $this->artisan('gemini:antre', ['--isi' => true, '--batas' => 0]);

        // Meniru pelepasan kemarin yang jobnya sudah tidak ada lagi.
        AntreanGemini::query()->update([
            'status' => 'menunggu',
            'dijadwalkan_at' => now()->subHours(23),
        ]);

        $this->artisan('gemini:antre', ['--batas' => 5]);

        Queue::assertPushed(KlasifikasiGemini::class, 1);
        $this->assertSame('menunggu', AntreanGemini::where('artikel_id', $artikel->id)->value('status'));

        // Jatah percobaan tidak boleh ikut terpakai. Barisnya tidak pernah
        // gagal, ia cuma kehilangan jobnya.
        $this->assertSame(0, (int) AntreanGemini::where('artikel_id', $artikel->id)->value('percobaan'));
    }

    /**
     * Pekerjaan yang baru saja menunda dirinya tidak ikut terbebaskan.
     *
     * Batas atas penundaan job lima menit, jadi baris yang dijadwalkan beberapa
     * menit lagi memang masih hidup. Membebaskannya berarti satu artikel dinilai
     * dua kali dan membayar dua kali.
     */
    public function test_penundaan_yang_masih_hidup_tidak_dibebaskan(): void
    {
        Queue::fake();

        $this->artikel('Belum pernah dinilai');

        $this->artisan('gemini:antre', ['--isi' => true, '--batas' => 0]);

        AntreanGemini::query()->update([
            'status' => 'menunggu',
            'dijadwalkan_at' => now()->addMinutes(3),
        ]);

        $this->artisan('gemini:antre', ['--batas' => 5]);

        Queue::assertNothingPushed();
    }

    /**
     * Dengan IndoBERT, jaraknya lima detik selama jatah hariannya mencukupi.
     *
     * Kapasitas per menit tidak lagi menjadi lantainya. Penyaringnya berjalan di
     * server sendiri, dan artikel yang ditolaknya selesai tanpa satu pun
     * permintaan ke Google. Yang menggantikannya lantai jatah harian, dan di
     * sini ia sengaja dibuat berlimpah supaya yang teruji memang angka pilihan
     * admin. Lantai hariannya sendiri diuji di PenyediaRelevansiTest.
     */
    public function test_jeda_indobert_jauh_lebih_rapat_daripada_gemini(): void
    {
        KunciGemini::create(['label' => 'Kunci A', 'kunci' => 'kunci-a', 'aktif' => true]);

        config([
            'ai.antrean.jeda_detik' => 60,
            'ai.antrean.jeda_detik_indobert' => 5,
            'ai.batas_kunci.rpd' => 1000000,
        ]);

        $this->assertSame(60.0, AntreanGemini::jedaDetik());

        PengaturanAi::aktif()->update(['penyedia_relevansi' => 'indobert']);

        $this->assertSame(5.0, AntreanGemini::jedaDetik());
    }

    /**
     * Jeda pilihan admin tidak boleh melanggar kapasitas kuncinya.
     *
     * Satu kunci pada free tier sanggup 15 permintaan per menit, dan satu
     * artikel memakan sekitar 1,8 permintaan. Jarak satu detik yang diminta
     * config berarti menembak jauh lebih cepat daripada itu, dan hasilnya
     * kembali seperti uji jalan pertama: sebagian besar permintaan dijawab 429
     * tetapi tetap dihitung Google.
     */
    public function test_jeda_tidak_pernah_lebih_rapat_daripada_kapasitas_kunci(): void
    {
        KunciGemini::create(['label' => 'Kunci A', 'kunci' => 'kunci-a', 'aktif' => true]);

        config(['ai.antrean.jeda_detik' => 1, 'ai.batas_kunci.rpm' => 15]);

        // 15 permintaan per menit dibagi 1,8 permintaan per artikel berarti
        // paling cepat satu artikel tiap 7,2 detik.
        $this->assertEqualsWithDelta(7.2, AntreanGemini::jedaDetik(), 0.01);
    }

    /** Halaman pemantauan terbuka dan menyebut sisa antrean apa adanya. */
    public function test_halaman_pemantauan_menampilkan_sisa_antrean(): void
    {
        $artikel = $this->artikel('Belum pernah dinilai');

        AntreanGemini::create(['artikel_id' => $artikel->id, 'prioritas' => 1, 'status' => 'menunggu']);

        $props = $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->get('/admin/antrean-ai')
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(1, $props['ringkasan']['menunggu']);
        $this->assertSame(1, collect($props['prioritas'])->firstWhere('nilai', 1)['jumlah']);
    }

    /**
     * Penunjuk keadaan menggantikan dua tombol yang dihapus, jadi ia satu-satunya
     * yang memberi tahu admin mesinnya masih hidup atau tidak. Empat keadaannya
     * diuji sekaligus karena yang menentukan adalah urutan cabangnya, bukan
     * masing-masing cabang berdiri sendiri.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: ?int, 4: bool}>
     */
    public static function keadaanAntrean(): array
    {
        return [
            // status baris sisa, keadaan yang diharapkan, umur selesai_at dalam
            // detik, berapa detik lagi pekerjaannya dijadwalkan bangun, dan
            // apakah seluruh kunci sedang kena limit
            'antrean kosong' => ['selesai', 'kosong', 10, null, false],
            'sedang dikirim ke Gemini' => ['berjalan', 'bekerja', 10, null, false],
            'menunggu giliran dalam jeda' => ['menunggu', 'menunggu', 10, null, false],
            'diam jauh melewati dua kali jeda' => ['menunggu', 'macet', 3600, null, false],
            // Diam sama lamanya dengan kasus di atas, tetapi seluruh kuncinya
            // sedang kena limit. Ini bukan kerusakan, dan menyebutnya macet akan
            // membuat admin mengejar worker yang sebenarnya sehat.
            'seluruh kunci kena limit' => ['menunggu', 'tertunda', 3600, 3600, true],
            // Pekerjaannya tidur persis seperti kasus di atas, tetapi masih ada
            // kunci yang siap dipakai. Layar pernah tetap berbunyi "Kuota Gemini
            // habis" di sini, lama setelah admin menambahkan kunci baru yang
            // jatahnya utuh, karena keadaannya ditebak dari `dijadwalkan_at`
            // pekerjaan yang telanjur tidur alih-alih dibaca dari kuncinya.
            'tidur padahal kuota masih ada' => ['menunggu', 'menunggu', 3600, 3600, false],
        ];
    }

    #[DataProvider('keadaanAntrean')]
    public function test_penunjuk_keadaan_membaca_gerak_antrean(
        string $status,
        string $harapan,
        int $umur,
        ?int $bangun,
        bool $kuotaHabis,
    ): void {
        KunciGemini::create([
            'label' => 'Kunci uji',
            'kunci' => 'kunci-uji-yang-cukup-panjang',
            'aktif' => true,
            'limit_sampai' => $kuotaHabis ? now()->addHours(7) : null,
        ]);

        $artikel = $this->artikel('Berita contoh');

        // Selalu ada satu baris yang pernah selesai, karena tanpanya seluruh
        // kasus jatuh ke cabang "belum pernah ada yang selesai".
        AntreanGemini::create([
            'artikel_id' => $artikel->id,
            'prioritas' => 1,
            'status' => 'selesai',
            'selesai_at' => now()->subSeconds($umur),
        ]);

        if ($status !== 'selesai') {
            AntreanGemini::create([
                'artikel_id' => $this->artikel('Berita kedua')->id,
                'prioritas' => 1,
                'status' => $status,
                'dijadwalkan_at' => $bangun === null ? null : now()->addSeconds($bangun),
            ]);
        }

        $props = $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->get('/admin/antrean-ai')
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame($harapan, $props['aktivitas']['keadaan']);
    }

    /**
     * Daftar kegagalan hanya berisi yang benar-benar menyerah.
     *
     * Batasnya `percobaan >= MAKS_PERCOBAAN`, sama dengan yang menghitung angka
     * di kartunya. Kalau keduanya sampai berbeda, kartu bertuliskan satu angka
     * akan membuka daftar berisi angka lain, dan yang akan dipercaya admin
     * adalah yang salah. Baris gagal yang jatah percobaannya masih ada memang
     * akan dilepas lagi dengan sendirinya, jadi ia bukan urusan daftar ini.
     */
    public function test_daftar_gagal_hanya_memuat_yang_kehabisan_percobaan(): void
    {
        $menyerah = $this->artikel('Sudah tiga kali gagal');
        $masihAdaJatah = $this->artikel('Baru sekali gagal');

        AntreanGemini::create([
            'artikel_id' => $menyerah->id,
            'prioritas' => 1,
            'status' => 'gagal',
            'percobaan' => AntreanGemini::MAKS_PERCOBAAN,
            'galat' => 'The MAC is invalid.',
            'selesai_at' => now(),
        ]);

        AntreanGemini::create([
            'artikel_id' => $masihAdaJatah->id,
            'prioritas' => 1,
            'status' => 'gagal',
            'percobaan' => 1,
            'galat' => 'cURL error 28',
            'selesai_at' => now(),
        ]);

        $isi = $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->getJson('/admin/antrean-ai/gagal')
            ->assertOk()
            ->json();

        $this->assertSame(1, $isi['total']);
        $this->assertCount(1, $isi['baris']);
        $this->assertSame('Sudah tiga kali gagal', $isi['baris'][0]['judul']);
        $this->assertSame('The MAC is invalid.', $isi['baris'][0]['galat']);

        // Pengelompokan dihitung di server, dan ia harus menyaring dengan
        // ambang yang sama. Kalau tidak, jumlah di kelompok tidak akan pernah
        // cocok dengan jumlah baris di bawahnya pada layar yang sama.
        $this->assertCount(1, $isi['kelompok']);
        $this->assertSame(1, $isi['kelompok'][0]['jumlah']);
    }

    /** Angka pada kartu dan isi daftarnya harus berasal dari ambang yang sama. */
    public function test_daftar_gagal_cocok_dengan_angka_menyerah_di_halaman(): void
    {
        $artikel = $this->artikel('Gagal terus');

        AntreanGemini::create([
            'artikel_id' => $artikel->id,
            'prioritas' => 1,
            'status' => 'gagal',
            'percobaan' => AntreanGemini::MAKS_PERCOBAAN,
            'galat' => 'AI provider [gemini] is overloaded.',
            'selesai_at' => now(),
        ]);

        $admin = User::factory()->create(['peran' => 'superadmin']);

        $menyerah = $this->actingAs($admin)
            ->get('/admin/antrean-ai')
            ->assertOk()
            ->viewData('page')['props']['ringkasan']['menyerah'];

        $total = $this->actingAs($admin)
            ->getJson('/admin/antrean-ai/gagal')
            ->assertOk()
            ->json('total');

        $this->assertSame($menyerah, $total);
    }

    private function artikel(string $judul, string $status = 'selesai'): Artikel
    {
        return Artikel::create([
            'media_id' => $this->media->id,
            'judul' => $judul,
            'url' => 'https://contoh.id/'.str($judul)->slug(),
            'url_kanonik' => 'https://contoh.id/'.str($judul)->slug(),
            'isi' => 'Isi berita contoh yang cukup panjang untuk dinilai.',
            'jumlah_kata' => 8,
            'diambil_at' => now(),
            'status_proses' => $status,
        ]);
    }

    private function analisis(Artikel $artikel, bool $relevan, ?string $label, ?string $provider): void
    {
        AnalisisSentimen::create([
            'artikel_id' => $artikel->id,
            'relevan' => $relevan,
            'label_model' => $label,
            'provider' => $provider,
        ]);
    }
}
