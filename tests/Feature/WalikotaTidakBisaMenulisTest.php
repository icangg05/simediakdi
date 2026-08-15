<?php

namespace Tests\Feature;

use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\AturanAlert;
use App\Models\KunciGemini;
use App\Models\Media;
use App\Models\PelatihanModelRelevansi;
use App\Models\SnapshotDatasetRelevansi;
use App\Models\SumberFeed;
use App\Models\UjiManualRelevansi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WalikotaTidakBisaMenulisTest extends TestCase
{
    use RefreshDatabase;

    private User $walikota;

    private Media $media;

    /** @var array<string, int|string> */
    private array $parameter = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->media = Media::create(['nama' => 'Media A', 'slug' => 'media-a', 'domain' => 'a.test']);

        $feed = SumberFeed::create([
            'media_id' => $this->media->id, 'nama' => 'Feed A', 'tipe' => 'rss', 'url' => 'https://a.test/feed',
        ]);

        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => $this->media->id, 'judul' => 'Berita uji',
            'url' => 'https://a.test/berita', 'url_kanonik' => 'https://a.test/berita',
            'isi' => 'Isi berita uji.', 'diambil_at' => now(), 'status_proses' => 'isi_diambil',
        ]);

        $analisis = AnalisisSentimen::create([
            'artikel_id' => $artikel->id, 'relevan' => true,
            'label_model' => 'netral', 'perlu_review' => false,
            'model_versi' => 'uji', 'dianalisis_at' => now(),
        ]);

        $alert = AturanAlert::create([
            'nama' => 'Uji', 'jenis' => 'sumber_mati', 'kondisi' => [], 'jendela_jam' => 6,
            'jeda_minimal_jam' => 6, 'kanal' => 'telegram', 'penerima' => [], 'aktif' => true,
        ]);

        $kunci = KunciGemini::create(['label' => 'Kunci uji', 'kunci' => 'kunci-uji-yang-cukup-panjang']);

        $this->walikota = User::factory()->walikota()->create();

        // Snapshot dan pelatihan dibuat langsung, bukan lewat KandidatDataset.
        // Yang diuji di sini middleware peran, dan menyiapkan puluhan artikel
        // berlabel hanya supaya sampling punya bahan berarti tes ini ikut gagal
        // setiap kali definisi kandidat berubah.
        $snapshot = SnapshotDatasetRelevansi::create([
            'nama' => 'Snapshot uji', 'random_seed' => 1,
            'persen_relevan' => 50, 'persen_tidak_relevan' => 50,
            'persen_train' => 80, 'persen_validation' => 10, 'persen_test' => 10,
            'dibuat_oleh' => $this->walikota->id,
        ]);

        $pelatihan = PelatihanModelRelevansi::create([
            'nama' => 'Pelatihan uji', 'snapshot_dataset_relevansi_id' => $snapshot->id,
            'base_model' => 'tfidf-unigram', 'konfigurasi' => [],
            'dibuat_oleh' => $this->walikota->id,
        ]);

        $uji = UjiManualRelevansi::create([
            'pelatihan_model_relevansi_id' => $pelatihan->id,
            'teks' => 'Teks pengujian untuk memastikan route model binding memakai baris nyata.',
            'label_prediksi' => 'relevan',
            'probabilitas_relevan' => 0.9,
            'probabilitas_tidak_relevan' => 0.1,
            'confidence' => 0.9,
            'inferensi_ms' => 5,
            'dibuat_oleh' => $this->walikota->id,
        ]);

        // Id diambil dari baris nyata: kalau route model binding 404 lebih dulu,
        // tes akan lulus tanpa pernah menyentuh middleware yang diuji.
        $this->parameter = [
            // Route::resource('media') menunggalkan "media" jadi "{medium}".
            'medium' => $this->media->id,
            // Rute crawl per media didaftarkan manual, jadi namanya tetap "media".
            'media' => $this->media->id,
            'sumberFeed' => $feed->id,
            'analisis' => $analisis->id,
            'alert' => $alert->id,
            'pengguna' => $this->walikota->id,
            'artikel' => $artikel->id,
            'kunci' => $kunci->id,
            'snapshot' => $snapshot->id,
            'pelatihan' => $pelatihan->id,
            'uji' => $uji->id,
            'bulan' => '2026-07',
            // Cadangan database tidak punya baris di tabel mana pun, parameternya
            // nama berkas. Nilainya tidak perlu benar-benar ada di disk: gerbang
            // peran berada di middleware, jadi ia menjawab 403 sebelum controller
            // sempat memeriksa keberadaan berkasnya.
            'nama' => 'simedia-2026-08-12-101500.sql.gz',
        ];
    }

    /**
     * Seluruh rute tulis di grup admin, dibaca dari router.
     *
     * Sengaja tidak berupa daftar yang ditulis tangan. Daftar tangan berhenti
     * mencerminkan aplikasi pada rute pertama yang lupa ditambahkan, dan
     * lubangnya persis di fitur terbaru yang paling belum teruji. Parameter
     * yang belum dikenal membuat tes gagal, bukan dilewati, supaya resource
     * baru memaksa perakitan datanya ditulis di setUp.
     */
    public function test_menolak_tulisan_ke_rute_admin(): void
    {
        $diuji = 0;

        foreach (Route::getRoutes() as $rute) {
            $metode = collect($rute->methods())->intersect(['POST', 'PUT', 'PATCH', 'DELETE'])->first();

            if ($metode === null || ! str_starts_with($rute->uri(), 'admin/')) {
                continue;
            }

            $url = '/'.$rute->uri();

            foreach ($rute->parameterNames() as $nama) {
                $this->assertArrayHasKey(
                    $nama,
                    $this->parameter,
                    "Rute {$rute->uri()} memakai parameter {{$nama}} yang belum punya data uji. "
                    .'Tambahkan barisnya di setUp, jangan lewati rutenya.',
                );

                $url = str_replace('{'.$nama.'}', (string) $this->parameter[$nama], $url);
            }

            $this->actingAs($this->walikota)
                ->call($metode, $url, ['nama' => 'Coba', 'jenis' => 'online', 'tier' => 'lokal'])
                ->assertForbidden("{$metode} {$url} seharusnya ditolak untuk peran walikota");

            $diuji++;
        }

        // Penjaga terhadap tes yang lulus karena tidak menemukan rute apa pun.
        $this->assertGreaterThan(20, $diuji, 'Rute tulis admin yang teruji terlalu sedikit.');
    }

    public function test_masih_bisa_membaca_halaman_admin(): void
    {
        $this->actingAs($this->walikota)
            ->get('/admin/media')
            ->assertOk();
    }

    public function test_superadmin_tetap_bisa_menulis(): void
    {
        // Media baru mendarat di halaman detailnya, bukan kembali ke daftar.
        // Di sanalah alamat feed-nya muncul begitu pencarian otomatis selesai.
        $this->actingAs(User::factory()->create())
            ->post('/admin/media', ['nama' => 'Media Baru', 'jenis' => 'online', 'tier' => 'lokal'])
            ->assertRedirect('/admin/media/'.Media::where('nama', 'Media Baru')->value('id'));

        $this->assertTrue(Media::where('nama', 'Media Baru')->exists());
    }

    public function test_walikota_tetap_bisa_keluar_dari_sesinya(): void
    {
        $this->actingAs($this->walikota)
            ->post('/logout')
            ->assertRedirect();
    }
}
