<?php

namespace Tests\Feature;

use App\Jobs\BuatNarasiBulanan;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\LogCrawl;
use App\Models\Media;
use App\Models\NarasiEksekutif;
use App\Models\PemantauanNarasiBulanan;
use App\Models\RingkasanHarian;
use App\Models\SumberFeed;
use App\Models\User;
use App\Support\Waktu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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
            '/admin/analisis-bulanan',
            '/eksekutif/laporan',
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

    public function test_admin_dapat_melihat_kegagalan_analisis_bulanan(): void
    {
        $this->travelTo(now()->setDate(2026, 8, 15)->setTime(12, 0));

        PemantauanNarasiBulanan::create([
            'bulan' => '2026-08-01',
            'status' => PemantauanNarasiBulanan::STATUS_GAGAL,
            'pemeriksaan' => 2,
            'galat' => 'Kuota Gemini belum tersedia.',
            'mulai_at' => now()->subMinute(),
            'gagal_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/admin/analisis-bulanan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/AnalisisBulanan')
                ->where('ringkasan.gagal', 1)
                ->where('bulan.0.bulan', '2026-08')
                ->where('bulan.0.status', 'gagal')
                ->where('bulan.0.galat', 'Kuota Gemini belum tersedia.')
                ->where('bulan.0.dikunci', false));
    }

    public function test_proses_bulanan_yang_terlalu_lama_ditandai_gagal_di_pemantauan(): void
    {
        $this->travelTo(now()->setDate(2026, 8, 15)->setTime(12, 0));

        PemantauanNarasiBulanan::create([
            'bulan' => '2026-08-01',
            'status' => PemantauanNarasiBulanan::STATUS_BERJALAN,
            'pemeriksaan' => 1,
            'mulai_at' => now()->subMinutes(31),
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/admin/analisis-bulanan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('ringkasan.berjalan', 0)
                ->where('ringkasan.gagal', 1)
                ->where('bulan.0.status', 'gagal')
                ->where('bulan.0.galat', 'Proses tidak selesai lebih dari 30 menit. Periksa penjadwal dan log aplikasi, lalu jalankan ulang bila worker sudah aktif.'));
    }

    public function test_narasi_30_hari_bergulir_tidak_dianggap_sebagai_analisis_bulanan(): void
    {
        $this->travelTo(now()->setDate(2026, 8, 15)->setTime(12, 0));
        $narasiBergulir = NarasiEksekutif::create([
            'periode' => '30d',
            'dari' => '2026-07-16',
            'sampai' => '2026-08-14',
            'judul' => 'Ringkasan tiga puluh hari terakhir',
            'jumlah_artikel' => 80,
            'sidik' => str_repeat('a', 40),
            'dibuat_at' => now(),
        ]);

        PemantauanNarasiBulanan::create([
            'bulan' => '2026-07-01',
            'status' => PemantauanNarasiBulanan::STATUS_SELESAI,
            'dikunci' => true,
            'pemeriksaan' => 1,
            'narasi_eksekutif_id' => $narasiBergulir->id,
            'selesai_at' => now(),
        ]);
        RingkasanHarian::create([
            'tanggal' => '2026-07-01',
            'media_id' => null,
            'jumlah_artikel' => 1,
            'jumlah_netral' => 1,
            'dihitung_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/admin/analisis-bulanan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('bolehAnalisisManual', true)
                ->where('bulan.1.bulan', '2026-07')
                ->where('bulan.1.status', 'belum_dianalisis')
                ->where('bulan.1.dikunci', false)
                ->where('bulan.1.judul', null)
                ->where('bulan.1.jumlah_bahan', 1)
                ->where('bulan.1.dapat_dianalisis_manual', true));
    }

    public function test_superadmin_dapat_menjadwalkan_analisis_bulan_yang_belum_memiliki_hasil(): void
    {
        $this->travelTo(now()->setDate(2026, 8, 15)->setTime(12, 0));
        Bus::fake();
        RingkasanHarian::create([
            'tanggal' => '2026-07-07',
            'media_id' => null,
            'jumlah_artikel' => 4,
            'jumlah_positif' => 2,
            'jumlah_netral' => 2,
            'dihitung_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->post('/admin/analisis-bulanan/2026-07/jalankan')
            ->assertRedirect()
            ->assertSessionHas('sukses', 'Analisis Juli 2026 dimasukkan ke antrean.');

        $this->assertDatabaseHas('pemantauan_narasi_bulanan', [
            'bulan' => '2026-07-01',
            'status' => PemantauanNarasiBulanan::STATUS_MENUNGGU,
            'dikunci' => false,
            'narasi_eksekutif_id' => null,
        ]);
        $this->assertNotNull(PemantauanNarasiBulanan::firstOrFail()->mulai_at);
        Bus::assertDispatched(BuatNarasiBulanan::class, fn (BuatNarasiBulanan $job): bool => $job->bulan === '2026-07');
    }

    public function test_bulan_berjalan_dengan_hasil_lama_tetap_bisa_diperbarui_manual(): void
    {
        $this->travelTo(now()->setDate(2026, 8, 15)->setTime(12, 0));
        Bus::fake();
        RingkasanHarian::create([
            'tanggal' => '2026-08-07',
            'media_id' => null,
            'jumlah_artikel' => 4,
            'jumlah_positif' => 2,
            'jumlah_netral' => 2,
            'dihitung_at' => now(),
        ]);
        $hasil = NarasiEksekutif::create([
            'periode' => '30d',
            'dari' => '2026-08-01',
            'sampai' => '2026-08-31',
            'judul' => 'Hasil sementara Agustus',
            'jumlah_artikel' => 4,
            'sidik' => str_repeat('b', 40),
            'dibuat_at' => now()->subDay(),
        ]);
        PemantauanNarasiBulanan::create([
            'bulan' => '2026-08-01',
            'status' => PemantauanNarasiBulanan::STATUS_SELESAI,
            'dikunci' => false,
            'pemeriksaan' => 1,
            'narasi_eksekutif_id' => $hasil->id,
            'selesai_at' => now()->subDay(),
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/admin/analisis-bulanan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('bulan.0.judul', 'Hasil sementara Agustus')
                ->where('bulan.0.dikunci', false)
                ->where('bulan.0.dapat_dianalisis_manual', true));

        $this->post('/admin/analisis-bulanan/2026-08/jalankan')
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $pantauan = PemantauanNarasiBulanan::firstOrFail();
        $this->assertSame($hasil->id, $pantauan->narasi_eksekutif_id);
        Bus::assertDispatched(BuatNarasiBulanan::class);
    }

    public function test_job_bulanan_mencatat_galat_bila_mati_sebelum_service_selesai(): void
    {
        $pantauan = PemantauanNarasiBulanan::create([
            'bulan' => '2026-07-01',
            'status' => PemantauanNarasiBulanan::STATUS_MENUNGGU,
            'mulai_at' => now(),
        ]);

        (new BuatNarasiBulanan($pantauan->id, '2026-07'))->failed(new \RuntimeException('Worker berhenti mendadak.'));

        $pantauan->refresh();
        $this->assertSame(PemantauanNarasiBulanan::STATUS_GAGAL, $pantauan->status);
        $this->assertSame('Worker berhenti mendadak.', $pantauan->galat);
        $this->assertNotNull($pantauan->gagal_at);
    }

    public function test_analisis_manual_tidak_dijadwalkan_bila_bulannya_tanpa_bahan(): void
    {
        Bus::fake();

        $this->actingAs(User::factory()->create())
            ->post('/admin/analisis-bulanan/2026-07/jalankan')
            ->assertRedirect()
            ->assertSessionHas('galat');

        Bus::assertNothingDispatched();
        $this->assertDatabaseCount('pemantauan_narasi_bulanan', 0);
    }

    /** Di bawah atau tepat tiga jam sehat; lewat satu detik baru bermasalah. */
    public function test_status_crawler_mengikuti_interval_tiga_jam(): void
    {
        $this->travelTo(now()->startOfSecond());
        $admin = User::factory()->create();

        LogCrawl::query()->update(['dimulai_at' => now()->subHour()]);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kesehatan.crawler.status', 'hijau'));

        LogCrawl::query()->update(['dimulai_at' => now()->subHours(3)]);

        $this->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kesehatan.crawler.status', 'hijau'));

        LogCrawl::query()->update(['dimulai_at' => now()->subHours(3)->subSecond()]);

        $this->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kesehatan.crawler.status', 'merah'));
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
