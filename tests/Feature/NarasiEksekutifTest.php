<?php

namespace Tests\Feature;

use App\Ai\Agents\AnalisEksekutif;
use App\Enums\LabelSentimen;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\KunciGemini;
use App\Models\Media;
use App\Models\NarasiEksekutif as Baris;
use App\Models\User;
use App\Services\Agregasi\NarasiEksekutif;
use App\Services\Agregasi\RingkasanHarian;
use App\Support\Waktu;
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
        AnalisEksekutif::fake([$this->jawaban([[
            'judul' => 'Topik dengan id yang tidak pernah dikirim',
            'ringkasan' => 'Ringkasan.',
            'artikel_ids' => [$this->id[0], 999_999],
        ]])]);

        $this->expectException(RuntimeException::class);

        app(NarasiEksekutif::class)->perbarui('7d');
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
            'poin' => ['Poin pertama', 'Poin kedua'],
            'perhatian' => [['topik' => 'Pengelolaan parkir', 'alasan' => 'Muncul di dua media.']],
            'nada_ringkas' => [
                'positif' => 'Kegiatan pelayanan publik.',
                'netral' => 'Agenda pemerintahan.',
                'negatif' => 'Pengelolaan parkir.',
            ],
        ];
    }
}
