<?php

namespace Tests\Feature;

use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\LogCrawl;
use App\Models\Media;
use App\Models\SumberFeed;
use App\Models\User;
use App\Support\Waktu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test dokumen 02 bagian 9: setiap halaman mengembalikan 200 untuk peran
 * yang berhak. Dijalankan dengan data terisi, bukan tabel kosong, beberapa
 * kesalahan hanya muncul saat ada barisnya, misalnya cast waktu pada agregat.
 */
class HalamanAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $media = Media::create([
            'nama' => 'Contoh',
            'slug' => 'contoh',
            'domain' => 'contoh.id',
            'partner' => true,
        ]);

        $sumber = SumberFeed::create([
            'media_id' => $media->id, 'nama' => 'Contoh RSS', 'tipe' => 'rss',
            'url' => 'https://contoh.id/feed', 'gagal_berturut' => 2,
            'pesan_error_terakhir' => 'HTTP 404',
        ]);

        LogCrawl::create([
            'sumber_feed_id' => $sumber->id, 'dimulai_at' => now()->subMinutes(10),
            'selesai_at' => now()->subMinutes(9), 'jumlah_ditemukan' => 5,
            'jumlah_baru' => 3, 'jumlah_salinan' => 2, 'status' => 'sukses',
        ]);

        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => $media->id, 'judul' => 'Berita contoh',
            'url' => 'https://contoh.id/berita', 'url_kanonik' => 'https://contoh.id/berita',
            'isi' => 'Isi berita contoh.', 'jumlah_kata' => 3,
            'diambil_at' => now(), 'status_proses' => 'selesai',
        ]);

        AnalisisSentimen::create([
            'artikel_id' => $artikel->id, 'relevan' => true, 'label_model' => 'netral',
        ]);
    }

    public function test_semua_halaman_admin_terbuka_untuk_superadmin(): void
    {
        $this->actingAs(User::factory()->create());

        $halaman = [
            '/admin', '/admin/artikel', '/admin/log-crawl', '/admin/media',
            '/admin/pengguna', '/admin/pengguna/create',
            '/admin/pengaturan',
            '/admin/alert', '/admin/alert/create',
            '/admin/media/create',
            // Pengelolaan sumber feed pindah ke sini. Halaman
            // /admin/sumber-feed dan formulirnya sudah dihapus.
            '/admin/media/'.Media::withoutGlobalScopes()->value('id'),
            '/admin/artikel/'.Artikel::withoutGlobalScopes()->value('id'),
        ];

        foreach ($halaman as $url) {
            $this->get($url)->assertOk("Halaman {$url} tidak mengembalikan 200.");
        }
    }

    /** Status kerja sama ikut sampai ke tabel artikel dan media. */
    public function test_status_kerja_sama_media_dikirim_ke_halaman_admin(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get('/admin/artikel')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('artikel.data.0.media', 'Contoh')
                ->where('artikel.data.0.media_partner', true));

        $this->actingAs($admin)
            ->get('/admin/media')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('media.data.0.partner', true)
                ->where('opsi.partner.0.label', 'Bekerja sama')
                ->where('opsi.partner.1.label', 'Tidak bekerja sama'));
    }

    public function test_halaman_eksekutif_dan_portal_terbuka_untuk_perannya(): void
    {
        $this->actingAs(User::factory()->walikota()->create())->get('/eksekutif')->assertOk();

        $media = Media::first();
        $pic = User::factory()->media($media)->create();

        foreach (['/portal', '/portal/berita', '/portal/lapor'] as $url) {
            $this->actingAs($pic)->get($url)->assertOk("Halaman {$url} tidak mengembalikan 200.");
        }
    }

    /**
     * Ekspor ditulis sebagai aliran, bukan dikumpulkan di memori, satu ekspor
     * setahun bisa puluhan ribu baris.
     */
    public function test_ekspor_excel_mengalirkan_berkas(): void
    {
        $tanggapan = $this->actingAs(User::factory()->create())
            ->get('/admin/ekspor/artikel');

        $tanggapan->assertOk();
        $tanggapan->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $tanggapan->assertDownload();
    }

    /** Dashboard menghitung batas hari WITA, bukan UTC. */
    public function test_dashboard_menghitung_berita_hari_ini_menurut_wita(): void
    {
        $media = Media::first();

        // 23.00 WITA hari ini = 15.00 UTC hari ini. Masih "hari ini" di Kendari.
        $malam = Artikel::withoutGlobalScopes()->create([
            'media_id' => $media->id, 'judul' => 'Berita malam',
            'url' => 'https://contoh.id/malam', 'url_kanonik' => 'https://contoh.id/malam',
            'diambil_at' => Waktu::awalHariIni()->addHours(23),
            'status_proses' => 'selesai',
        ]);

        AnalisisSentimen::create([
            'artikel_id' => $malam->id, 'relevan' => true, 'label_model' => 'positif',
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('kpi.artikel_hari_ini', 2));
    }

    /**
     * Angka dan daftar di dashboard hanya menghitung berita yang relevan.
     *
     * Artikel yang sudah diputus di luar pantauan bukan berita Pemkot. Ia tidak
     * boleh ikut menaikkan hitungan hari ini, dan tidak boleh muncul di daftar
     * berita terbaru. Diuji bersamaan supaya keduanya tidak bisa berselisih:
     * angka di kop dan baris di bawahnya wajib menghitung populasi yang sama.
     */
    public function test_dashboard_menyembunyikan_berita_tidak_relevan(): void
    {
        $media = Media::first();

        $tidakRelevan = Artikel::withoutGlobalScopes()->create([
            'media_id' => $media->id, 'judul' => 'Kegiatan perusahaan swasta',
            'url' => 'https://contoh.id/swasta', 'url_kanonik' => 'https://contoh.id/swasta',
            'diambil_at' => Waktu::awalHariIni()->addHours(10),
            'status_proses' => 'tidak_relevan',
        ]);

        AnalisisSentimen::create([
            'artikel_id' => $tidakRelevan->id, 'relevan' => false,
        ]);

        // Artikel yang belum dinilai sama sekali juga belum berhak tampil:
        // relevansinya belum diputuskan, jadi ia belum berita yang terhitung.
        Artikel::withoutGlobalScopes()->create([
            'media_id' => $media->id, 'judul' => 'Belum dinilai',
            'url' => 'https://contoh.id/mentah', 'url_kanonik' => 'https://contoh.id/mentah',
            'diambil_at' => Waktu::awalHariIni()->addHours(11),
            'status_proses' => 'mentah',
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Hanya artikel relevan dari setUp() yang terhitung.
                ->where('kpi.artikel_hari_ini', 1)
                ->has('artikelTerbaru', 1)
                ->where('artikelTerbaru.0.judul', 'Berita contoh')
                // Media, bukan nama sumber feed, yang bernama "Contoh RSS".
                ->where('artikelTerbaru.0.media', 'Contoh'));
    }

    /**
     * Kartu sumber bermasalah dikelompokkan per media, bukan per feed.
     *
     * Dua feed rusak milik satu media sebelumnya menghasilkan dua baris dengan
     * tulisan yang sama persis, tanpa cara membedakannya dari layar ini.
     */
    public function test_media_bermasalah_dikelompokkan_per_media(): void
    {
        $media = Media::first();

        SumberFeed::create([
            'media_id' => $media->id, 'nama' => 'Contoh RSS kedua', 'tipe' => 'rss',
            'url' => 'https://contoh.id/feed-2', 'gagal_berturut' => 7,
            'pesan_error_terakhir' => 'Koneksi habis waktu',
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('mediaBermasalah', 1)
                ->where('mediaBermasalah.0.nama', 'Contoh')
                // Kegagalan terparah yang mewakili, bukan yang pertama ditemukan.
                ->where('mediaBermasalah.0.gagal_berturut', 7)
                ->where('mediaBermasalah.0.jumlah_sumber', 2));
    }
}
