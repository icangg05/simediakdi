<?php

namespace Tests\Feature;

use App\Ai\Agents\AnalisEksekutif;
use App\Enums\LabelSentimen;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\KunciGemini;
use App\Models\Media;
use App\Models\NarasiEksekutif as Baris;
use App\Models\PemantauanNarasiBulanan;
use App\Models\User;
use App\Services\Agregasi\NarasiEksekutif;
use App\Services\Agregasi\RingkasanHarian;
use App\Support\Waktu;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Batas antara yang dihitung Postgres dan yang ditulis Gemini.
 *
 * Yang diuji di sini adalah kegagalan yang tidak menimbulkan galat apa pun:
 * jumlah berita per topik yang datang dari model alih-alih dari basis data, id
 * karangan yang menggelembungkan angka, satu artikel yang dihitung di dua
 * topik, dan panggilan Gemini yang diulang untuk data yang tidak berubah.
 *
 * Semuanya berakhir sebagai angka yang tampak wajar di layar pimpinan.
 */
class NarasiEksekutifTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, int> judul artikel => id */
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Sabtu memberi tiga hari bahan sekaligus tanpa menyeberang ke pekan
        // sebelumnya setelah preset 7d berubah menjadi Senin-Minggu.
        $this->travelTo(CarbonImmutable::parse('2026-08-15 12:00:00', Waktu::ZONA));

        KunciGemini::create(['label' => 'Kunci uji', 'kunci' => 'kunci-uji-yang-cukup-panjang']);

        // Dua media, supaya jumlah media per topik benar-benar terhitung dan
        // bukan sekadar selalu satu.
        $mediaA = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);
        $mediaB = Media::create(['nama' => 'Zonasultra', 'slug' => 'zs', 'domain' => 'zs.test']);

        $bahan = [
            [$mediaA, LabelSentimen::Negatif, 0],
            [$mediaB, LabelSentimen::Negatif, 1],
            [$mediaA, LabelSentimen::Positif, 1],
            [$mediaB, LabelSentimen::Netral, 2],
        ];

        foreach ($bahan as $n => [$media, $label, $mundur]) {
            $artikel = Artikel::withoutGlobalScopes()->create([
                'media_id' => $media->id,
                'judul' => "Berita {$n}",
                'url' => "https://{$media->domain}/{$n}",
                'url_kanonik' => "https://{$media->domain}/{$n}",
                'ringkasan' => "Ringkasan berita {$n}.",
                'isi' => 'Isi berita.',
                'diambil_at' => Waktu::awalHariIni()->subDays($mundur)->addHours(9),
                'status_proses' => 'selesai',
            ]);

            AnalisisSentimen::create([
                'artikel_id' => $artikel->id,
                'relevan' => true,
                'label_model' => $label,
            ]);

            $this->id[$n] = $artikel->id;
        }

        app(RingkasanHarian::class)->hitungMundur(7);
    }

    public function test_statistik_topik_dihitung_basis_data_bukan_oleh_gemini(): void
    {
        // Gemini mengirim angka yang salah dan sistem tidak boleh memakainya.
        AnalisEksekutif::fake([$this->jawaban([[
            'judul' => 'Pengelolaan parkir mulai mendapat sorotan dalam sejumlah pemberitaan',
            'ringkasan' => 'Beberapa media menyoroti pengelolaan parkir.',
            'artikel_ids' => [$this->id[0], $this->id[1]],
            'jumlah_artikel' => 99,
        ]])]);

        $baris = app(NarasiEksekutif::class)->perbarui('7d');

        $topik = $baris->topik[0];

        $this->assertSame(2, $topik['jumlah_artikel']);
        $this->assertSame(2, $topik['jumlah_media']);
        $this->assertSame(2, $topik['negatif']);
        $this->assertSame(0, $topik['positif']);
        // Artikel 0 hari ini dan artikel 1 kemarin, jadi dua hari beruntun.
        $this->assertSame(2, $topik['hari_beruntun']);
        $this->assertSame('negatif', $topik['sentimen_dominan']);
    }

    public function test_artikel_id_karangan_membatalkan_narasi(): void
    {
        $jawabanSalah = $this->jawaban([[
            'judul' => 'Topik dengan id yang tidak pernah dikirim',
            'ringkasan' => 'Ringkasan.',
            'artikel_ids' => [$this->id[0], 999_999],
        ]]);
        AnalisEksekutif::fake([$jawabanSalah, $jawabanSalah]);

        $this->expectException(RuntimeException::class);

        app(NarasiEksekutif::class)->perbarui('7d');
    }

    public function test_artikel_id_karangan_diminta_dikoreksi_sekali(): void
    {
        AnalisEksekutif::fake([
            $this->jawaban([[
                'judul' => 'Topik pertama memakai id yang tidak pernah dikirim',
                'ringkasan' => 'Ringkasan yang akan dikoreksi.',
                'artikel_ids' => [$this->id[0], 999_999],
            ]]),
            $this->jawaban([[
                'judul' => 'Pengelolaan parkir mulai mendapat sorotan dalam pemberitaan',
                'ringkasan' => 'Jawaban koreksi memakai id yang tersedia.',
                'artikel_ids' => [$this->id[0], $this->id[1]],
            ]]),
        ])->preventStrayPrompts();

        $baris = app(NarasiEksekutif::class)->perbarui('7d');

        $this->assertNotNull($baris);
        $this->assertSame([$this->id[0], $this->id[1]], $baris->topik[0]['artikel_ids']);
    }

    public function test_satu_artikel_tidak_dihitung_di_dua_topik(): void
    {
        AnalisEksekutif::fake([$this->jawaban([
            [
                'judul' => 'Topik pertama yang menyebut artikel nol dan satu',
                'ringkasan' => 'Ringkasan.',
                'artikel_ids' => [$this->id[0], $this->id[1]],
            ],
            [
                'judul' => 'Topik kedua yang menyalin artikel nol sekali lagi',
                'ringkasan' => 'Ringkasan.',
                'artikel_ids' => [$this->id[0], $this->id[2]],
            ],
        ])]);

        $baris = app(NarasiEksekutif::class)->perbarui('7d');

        $total = array_sum(array_column($baris->topik, 'jumlah_artikel'));

        $this->assertSame(3, $total, 'Artikel yang disalin ke dua topik membuat total melebihi jumlah berita periodenya.');
    }

    public function test_gemini_tidak_dipanggil_lagi_saat_bahannya_tidak_berubah(): void
    {
        AnalisEksekutif::fake([$this->jawaban([[
            'judul' => 'Topik apa pun yang penting berbentuk kalimat',
            'ringkasan' => 'Ringkasan.',
            'artikel_ids' => [$this->id[0]],
        ]])]);

        $narasi = app(NarasiEksekutif::class);

        $this->assertNotNull($narasi->perbarui('7d'));
        // Jawaban palsu hanya disediakan satu. Panggilan kedua akan gagal kalau
        // sidik bahan tidak menahannya.
        $this->assertNull($narasi->perbarui('7d'));
        $this->assertSame(1, Baris::where('periode', '7d')->count());
    }

    public function test_perintah_narasi_laporan_memakai_batas_bulan_kalender(): void
    {
        AnalisEksekutif::fake([$this->jawaban([[
            'judul' => 'Pemberitaan pelayanan publik menjadi topik utama bulan ini',
            'ringkasan' => 'Ringkasan.',
            'artikel_ids' => [$this->id[0]],
        ]])]);

        $this->artisan('narasi:eksekutif', ['--bulan' => ['2026-08']])
            ->assertSuccessful();

        $baris = Baris::query()
            ->where('periode', '30d')
            ->where('dari', '2026-08-01')
            ->firstOrFail();

        $this->assertSame('30d', $baris->periode);
        $this->assertSame('2026-08-01', $baris->dari->toDateString());
        $this->assertSame('2026-08-31', $baris->sampai->toDateString());
    }

    public function test_bulan_lampau_dikunci_setelah_pemeriksaan_final(): void
    {
        AnalisEksekutif::fake([$this->jawaban([[
            'judul' => 'Pemberitaan pelayanan publik menjadi topik utama bulan ini',
            'ringkasan' => 'Ringkasan.',
            'artikel_ids' => [$this->id[0]],
        ]])])->preventStrayPrompts();

        $narasi = app(NarasiEksekutif::class);
        $agustus = CarbonImmutable::parse('2026-08-01', Waktu::ZONA);

        $awal = $narasi->perbaruiBulan($agustus);
        $this->assertNotNull($awal);
        $this->assertFalse(PemantauanNarasiBulanan::firstOrFail()->dikunci);

        // Setelah bulan berganti, bahan yang sama cukup diperiksa sidiknya.
        // Gemini tidak dipanggil lagi, tetapi hasilnya berubah menjadi final.
        $this->travelTo(CarbonImmutable::parse('2026-09-01 05:00:00', Waktu::ZONA));
        $this->assertNull($narasi->perbaruiBulan($agustus));

        $pantauan = PemantauanNarasiBulanan::firstOrFail();
        $this->assertTrue($pantauan->dikunci);
        $this->assertSame(PemantauanNarasiBulanan::STATUS_SELESAI, $pantauan->status);
        $this->assertSame(2, $pantauan->pemeriksaan);

        // Bahkan bila data lama berubah sesudah dikunci, bulan final tidak
        // masuk lagi ke proses dan hasil yang sudah disahkan tetap sama.
        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => Media::firstOrFail()->id,
            'judul' => 'Berita terlambat setelah laporan dikunci',
            'url' => 'https://contoh.test/terlambat',
            'url_kanonik' => 'https://contoh.test/terlambat',
            'ringkasan' => 'Ringkasan berita terlambat.',
            'isi' => 'Isi berita.',
            'diambil_at' => CarbonImmutable::parse('2026-08-31 22:00:00', Waktu::ZONA),
            'status_proses' => 'selesai',
        ]);
        AnalisisSentimen::create([
            'artikel_id' => $artikel->id,
            'relevan' => true,
            'label_model' => LabelSentimen::Positif,
        ]);

        $this->assertNull($narasi->perbaruiBulan($agustus));
        $this->assertSame($awal->sidik, $awal->fresh()->sidik);
        $this->assertSame(2, $pantauan->fresh()->pemeriksaan);
    }

    public function test_kegagalan_narasi_bulanan_tercatat_untuk_admin(): void
    {
        AnalisEksekutif::fake(function (): never {
            throw new RuntimeException('Gemini tidak dapat menjawab dalam batas waktu.');
        });

        try {
            app(NarasiEksekutif::class)->perbaruiBulan(CarbonImmutable::parse('2026-08-01', Waktu::ZONA));
            $this->fail('Kegagalan Gemini seharusnya dilempar kembali ke command.');
        } catch (RuntimeException $galat) {
            $this->assertSame('Gemini tidak dapat menjawab dalam batas waktu.', $galat->getMessage());
        }

        $this->assertDatabaseHas('pemantauan_narasi_bulanan', [
            'bulan' => '2026-08-01',
            'status' => PemantauanNarasiBulanan::STATUS_GAGAL,
            'dikunci' => false,
            'pemeriksaan' => 1,
            'galat' => 'Gemini tidak dapat menjawab dalam batas waktu.',
        ]);
    }

    public function test_dashboard_tetap_terisi_saat_narasi_belum_ada(): void
    {
        $walikota = User::factory()->walikota()->create();

        $this->actingAs($walikota)
            ->get('/eksekutif')
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman
                ->where('narasi', null)
                ->where('kpi.berlabel', 4));
    }

    public function test_narasi_terakhir_tetap_tampil_walau_rentangnya_bergeser(): void
    {
        // Meniru keadaan setelah Gemini gagal semalam: narasi kemarin masih di
        // tabel, dan halaman harus memakainya, bukan mengosongkan bagian itu.
        Baris::create([
            'periode' => '7d',
            'dari' => Waktu::tanggalWita(now()->subDays(7)),
            'sampai' => Waktu::tanggalWita(now()->subDay()),
            'nada' => 'netral',
            'judul' => 'Ringkasan kemarin',
            'ringkasan' => 'Isi ringkasan kemarin.',
            'sidik' => 'sidik-lama',
            'dibuat_at' => now()->subDay(),
        ]);

        $walikota = User::factory()->walikota()->create();

        $this->actingAs($walikota)
            ->get('/eksekutif')
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman->where('narasi.judul', 'Ringkasan kemarin'));
    }

    public function test_bulan_lampau_menampilkan_narasi_bulan_itu(): void
    {
        // Bulan lampau bukan salah satu preset, jadi rentangnya dicocokkan tepat
        // pada tanggalnya. Tanpa itu pemilih bulan menampilkan seluruh angka
        // bulan lalu di samping bagian ulasan yang kosong.
        $bulanLalu = CarbonImmutable::now(Waktu::ZONA)->subMonthNoOverflow()->startOfMonth();

        Baris::create([
            'periode' => '30d',
            'dari' => $bulanLalu->toDateString(),
            'sampai' => $bulanLalu->endOfMonth()->toDateString(),
            'nada' => 'netral',
            'judul' => 'Ringkasan bulan lalu',
            'ringkasan' => 'Isi ringkasan bulan lalu.',
            'sidik' => 'sidik-bulan-lalu',
            'dibuat_at' => $bulanLalu->endOfMonth(),
        ]);

        $walikota = User::factory()->walikota()->create();

        $this->actingAs($walikota)
            ->get('/eksekutif?dari='.$bulanLalu->toDateString().'&sampai='.$bulanLalu->endOfMonth()->toDateString())
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman->where('narasi.judul', 'Ringkasan bulan lalu'));
    }

    public function test_kartu_topik_membuka_arsip_yang_tersaring_ke_artikelnya(): void
    {
        $walikota = User::factory()->walikota()->create();

        $this->actingAs($walikota)
            ->get('/eksekutif/berita?artikel='.$this->id[0].','.$this->id[2])
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman->where('artikel.total', 2));
    }

    public function test_judul_topik_satu_kata_tidak_masuk_dashboard(): void
    {
        AnalisEksekutif::fake([$this->jawaban([
            ['judul' => 'Parkir', 'ringkasan' => 'Ringkasan.', 'artikel_ids' => [$this->id[0]]],
            [
                'judul' => 'Pengelolaan parkir mulai mendapat sorotan dalam sejumlah pemberitaan',
                'ringkasan' => 'Ringkasan.',
                'artikel_ids' => [$this->id[1]],
            ],
        ])]);

        $baris = app(NarasiEksekutif::class)->perbarui('7d');

        $this->assertCount(1, $baris->topik);
        $this->assertStringStartsWith('Pengelolaan parkir', $baris->topik[0]['judul']);
    }

    /**
     * Tautan poin harus membuka artikel yang benar-benar ada di periode itu.
     *
     * Id karangan tidak menggagalkan narasi, berbeda dengan id karangan pada
     * topik, tetapi juga tidak boleh lolos ke layar. Tautan yang membuka arsip
     * kosong membuat pembaca menyimpulkan beritanya sudah dihapus.
     */
    public function test_artikel_id_karangan_pada_poin_disaring(): void
    {
        $jawaban = $this->jawaban([[
            'judul' => 'Pengelolaan parkir mulai mendapat sorotan dalam sejumlah pemberitaan',
            'ringkasan' => 'Ringkasan.',
            'artikel_ids' => [$this->id[0]],
        ]]);

        $jawaban['poin'] = [['teks' => 'Poin dengan id karangan', 'artikel_ids' => [$this->id[1], 999999]]];

        AnalisEksekutif::fake([$jawaban]);

        $baris = app(NarasiEksekutif::class)->perbarui('7d');

        $this->assertSame([$this->id[1]], $baris->poin[0]['artikel_ids']);
        $this->assertSame('Poin dengan id karangan', $baris->poin[0]['teks']);
    }

    /**
     * Narasi lama menyimpan poin sebagai untaian biasa, dan barisnya masih ada
     * di basis data sampai penjadwal menimpanya. Halaman tidak boleh pecah
     * selama satu jam itu.
     */
    public function test_poin_bentuk_lama_tetap_terbaca(): void
    {
        $baris = Baris::create([
            'periode' => '7d',
            'dari' => Waktu::tanggalWita(now()),
            'sampai' => Waktu::tanggalWita(now()),
            'nada' => 'netral',
            'judul' => 'Judul',
            'ringkasan' => 'Ringkasan.',
            'poin' => ['Poin bentuk lama'],
            'jumlah_artikel' => 1,
            'sidik' => 'apa-saja',
            'model' => 'uji',
            'dibuat_at' => now(),
        ]);

        $this->assertSame(
            [['teks' => 'Poin bentuk lama', 'artikel_ids' => []]],
            $baris->untukInertia()['poin'],
        );
    }

    public function test_istilah_nada_pada_narasi_lama_disajikan_sebagai_sentimen(): void
    {
        $baris = Baris::create([
            'periode' => '7d',
            'dari' => Waktu::tanggalWita(now()),
            'sampai' => Waktu::tanggalWita(now()),
            'nada' => 'campuran',
            'judul' => 'Nada pemberitaan bernada beragam',
            'ringkasan' => 'Media menjelaskan nadanya.',
            'penjelasan_tren' => 'NADA berubah.',
            'poin' => [['teks' => 'Poin bernada netral', 'artikel_ids' => []]],
            'perhatian' => [['topik' => 'Sorotan', 'alasan' => 'Nada negatif meningkat.']],
            'nada_ringkas' => ['negatif' => 'Berita bernada negatif.'],
            'topik' => [['judul' => 'Topik bernada positif']],
            'jumlah_artikel' => 1,
            'sidik' => 'istilah-lama',
            'model' => 'uji',
            'dibuat_at' => now(),
        ]);

        $narasi = $baris->untukInertia();

        $this->assertSame('campuran', $narasi['nada']);
        $this->assertSame('Sentimen pemberitaan bersentimen beragam', $narasi['judul']);
        $this->assertSame('Media menjelaskan sentimennya.', $narasi['ringkasan']);
        $this->assertSame('SENTIMEN berubah.', $narasi['penjelasan_tren']);
        $this->assertSame('Poin bersentimen netral', $narasi['poin'][0]['teks']);
        $this->assertSame('Sentimen negatif meningkat.', $narasi['perhatian'][0]['alasan']);
        $this->assertSame('Berita bersentimen negatif.', $narasi['nada_ringkas']['negatif']);
        $this->assertSame('Topik bersentimen positif', $narasi['topik'][0]['judul']);
    }

    public function test_istilah_nada_pada_alasan_klasifikasi_lama_disajikan_sebagai_sentimen(): void
    {
        $analisis = AnalisisSentimen::query()->firstOrFail();
        $analisis->update(['reason_summary' => 'Artikel bernada negatif dan nadanya kuat.']);

        $this->assertSame(
            'Artikel bersentimen negatif dan sentimennya kuat.',
            $analisis->fresh()->reason_summary,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $topik
     * @return array<string, mixed>
     */
    private function jawaban(array $topik): array
    {
        return [
            'topik' => $topik,
            'nada' => 'campuran',
            'judul' => 'Pemberitaan Pemkot Kendari bernada beragam',
            'ringkasan' => 'Ringkasan kondisi pemberitaan.',
            'penjelasan_tren' => 'Jumlah berita naik pada dua hari terakhir.',
            'poin' => [
                ['teks' => 'Poin pertama', 'artikel_ids' => [$this->id[0]]],
                ['teks' => 'Poin kedua', 'artikel_ids' => [$this->id[1]]],
            ],
            'perhatian' => [['topik' => 'Pengelolaan parkir', 'alasan' => 'Muncul di dua media.']],
            'nada_ringkas' => [
                'positif' => 'Kegiatan pelayanan publik.',
                'netral' => 'Agenda pemerintahan.',
                'negatif' => 'Pengelolaan parkir.',
            ],
        ];
    }
}
