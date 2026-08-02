<?php

namespace Tests\Feature;

use App\Models\Artikel;
use App\Models\LogCrawl;
use App\Models\Media;
use App\Models\SumberFeed;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test dokumen 02 bagian 9: setiap halaman mengembalikan 200 untuk peran
 * yang berhak. Dijalankan dengan data terisi, bukan tabel kosong — beberapa
 * kesalahan hanya muncul saat ada barisnya, misalnya cast waktu pada agregat.
 */
class HalamanAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $media = Media::create(['nama' => 'Contoh', 'slug' => 'contoh', 'domain' => 'contoh.id']);

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

        Artikel::withoutGlobalScopes()->create([
            'media_id' => $media->id, 'judul' => 'Berita contoh',
            'url' => 'https://contoh.id/berita', 'url_kanonik' => 'https://contoh.id/berita',
            'isi' => 'Isi berita contoh.', 'jumlah_kata' => 3,
            'diambil_at' => now(), 'status_proses' => 'selesai',
        ]);
    }

    public function test_semua_halaman_admin_terbuka_untuk_superadmin(): void
    {
        $this->actingAs(User::factory()->create());

        $halaman = [
            '/admin', '/admin/artikel', '/admin/log-crawl', '/admin/media',
            '/admin/sumber-feed', '/admin/konteks', '/admin/pelabelan', '/admin/evaluasi',
            '/admin/media/create', '/admin/sumber-feed/create', '/admin/konteks/create',
            '/admin/artikel/'.Artikel::withoutGlobalScopes()->value('id'),
        ];

        foreach ($halaman as $url) {
            $this->get($url)->assertOk("Halaman {$url} tidak mengembalikan 200.");
        }
    }

    public function test_halaman_eksekutif_dan_portal_terbuka_untuk_perannya(): void
    {
        $this->actingAs(User::factory()->walikota()->create())->get('/eksekutif')->assertOk();

        $media = Media::first();
        $this->actingAs(User::factory()->media($media)->create())->get('/portal')->assertOk();
    }

    /** Dashboard menghitung batas hari WITA, bukan UTC. */
    public function test_dashboard_menghitung_berita_hari_ini_menurut_wita(): void
    {
        $media = Media::first();

        // 23.00 WITA hari ini = 15.00 UTC hari ini. Masih "hari ini" di Kendari.
        Artikel::withoutGlobalScopes()->create([
            'media_id' => $media->id, 'judul' => 'Berita malam',
            'url' => 'https://contoh.id/malam', 'url_kanonik' => 'https://contoh.id/malam',
            'diambil_at' => \App\Support\Waktu::awalHariIni()->addHours(23),
            'status_proses' => 'selesai',
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('kpi.artikel_hari_ini', 2));
    }
}
