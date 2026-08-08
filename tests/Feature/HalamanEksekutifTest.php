<?php

namespace Tests\Feature;

use App\Enums\LabelSentimen;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\ExecutiveTopic;
use App\Models\Media;
use App\Models\User;
use App\Services\Agregasi\RingkasanHarian;
use App\Support\Waktu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel eksekutif seluruhnya read-only dan dilihat pimpinan.
 *
 * Yang diuji di sini bukan tampilannya, tapi bahwa angkanya benar-benar terbaca
 * dari tabel ringkasan dan pilihan rentang tidak hilang antar halaman.
 */
class HalamanEksekutifTest extends TestCase
{
    use RefreshDatabase;

    private User $walikota;

    protected function setUp(): void
    {
        parent::setUp();

        $this->walikota = User::factory()->walikota()->create();

        $media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);

        foreach ([LabelSentimen::Negatif, LabelSentimen::Positif, LabelSentimen::Positif] as $n => $label) {
            $artikel = Artikel::withoutGlobalScopes()->create([
                'media_id' => $media->id,
                'judul' => "Berita {$n}",
                'url' => "https://kp.test/{$n}",
                'url_kanonik' => "https://kp.test/{$n}",
                'isi' => 'Isi berita.',
                'diambil_at' => Waktu::awalHariIni()->addHours(9),
                'status_proses' => 'selesai',
            ]);

            AnalisisSentimen::create([
                'artikel_id' => $artikel->id,
                'relevan' => true,
                'label_model' => $label,
            ]);
        }

        app(RingkasanHarian::class)->hitung(Waktu::tanggalWita(now()));
    }

    public function test_semua_halaman_eksekutif_terbuka_untuk_walikota(): void
    {
        $this->actingAs($this->walikota);

        foreach (['/eksekutif', '/eksekutif/sentimen', '/eksekutif/isu', '/eksekutif/media', '/eksekutif/berita'] as $url) {
            $this->get($url)->assertOk("Halaman {$url} tidak mengembalikan 200.");
        }
    }

    public function test_pengguna_media_tidak_bisa_membuka_panel_eksekutif(): void
    {
        $media = Media::first();

        $this->actingAs(User::factory()->media($media)->create())
            ->get('/eksekutif')
            ->assertForbidden();
    }

    /** Angka KPI harus cocok saat dihitung ulang manual dari tabel artikel. */
    public function test_kpi_membaca_angka_dari_tabel_ringkasan(): void
    {
        $this->actingAs($this->walikota)
            ->get('/eksekutif')
            ->assertInertia(fn ($page) => $page
                ->where('kpi.berlabel', 3)
                ->where('kpi.artikel', 3)
                // Bilangan bulat, karena JSON meluruhkan 100.0 menjadi 100.
                ->where('kpi.cakupan_persen', 100)
                ->where('kpi.negatif', 1)
                ->where('kpi.positif', 2)
                // 1 dari 3 berlabel, dibulatkan satu desimal.
                ->where('kpi.negatif_persen', 33.3)
                ->where('kpi.media_aktif', 1));
    }

    /**
     * Angka utama panel adalah berita relevan yang sudah berlabel.
     *
     * Artikel yang belum diklasifikasi ikut menaikkan `artikel` karena memang
     * terpantau, tapi tidak boleh ikut menaikkan `berlabel`. Selisih keduanya
     * yang dilaporkan sebagai cakupan analisis.
     */
    public function test_artikel_tanpa_label_tidak_terhitung_sebagai_berlabel(): void
    {
        $this->artikelTambahan('Belum diklasifikasi', relevan: true, label: null);
        app(RingkasanHarian::class)->hitung(Waktu::tanggalWita(now()));

        $this->actingAs($this->walikota)
            ->get('/eksekutif')
            ->assertInertia(fn ($page) => $page
                ->where('kpi.berlabel', 3)
                ->where('kpi.artikel', 4)
                ->where('kpi.cakupan_persen', 75));
    }

    /**
     * Panel pimpinan tidak boleh memuat artikel yang sudah dinyatakan tidak
     * relevan, baik di ringkasan maupun di arsip.
     */
    public function test_artikel_tidak_relevan_tidak_muncul_di_daftar_berita(): void
    {
        $this->artikelTambahan('Berita tidak relevan', relevan: false, label: null);

        $this->actingAs($this->walikota);

        $this->get('/eksekutif')->assertInertia(fn ($page) => $page
            ->count('beritaTerbaru', 3));

        $this->get('/eksekutif/berita')->assertInertia(fn ($page) => $page
            ->count('artikel.data', 3));
    }

    /**
     * Artikel relevan yang belum berlabel juga tidak masuk daftar. Baris tanpa
     * label di panel eksekutif hanya menimbulkan pertanyaan yang tidak bisa
     * dijawab halaman ini.
     */
    public function test_artikel_relevan_tanpa_label_tidak_muncul_di_arsip(): void
    {
        $this->artikelTambahan('Relevan tapi belum berlabel', relevan: true, label: null);

        $this->actingAs($this->walikota)
            ->get('/eksekutif/berita')
            ->assertInertia(fn ($page) => $page->count('artikel.data', 3));
    }

    /**
     * Rentang tiga bulan yang digambar per hari menghasilkan sembilan puluh
     * titik yang tidak terbaca, jadi satuannya mengikuti panjang rentang.
     */
    public function test_satuan_deret_mengikuti_panjang_rentang(): void
    {
        $this->actingAs($this->walikota);

        $harapan = [
            ['2026-08-01', '2026-08-07', 'harian'],
            ['2026-08-01', '2026-08-31', 'harian'],
            ['2026-06-01', '2026-08-01', 'mingguan'],
            ['2026-01-01', '2026-08-01', 'bulanan'],
        ];

        foreach ($harapan as [$dari, $sampai, $satuan]) {
            $this->get("/eksekutif?dari={$dari}&sampai={$sampai}")
                ->assertInertia(fn ($page) => $page->where('deret.satuan', $satuan));
        }
    }

    /** Artikel tambahan pada hari ini, di media yang sama dengan setUp. */
    private function artikelTambahan(string $judul, bool $relevan, ?LabelSentimen $label): Artikel
    {
        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => Media::first()->id,
            'judul' => $judul,
            'url' => 'https://kp.test/'.md5($judul),
            'url_kanonik' => 'https://kp.test/'.md5($judul),
            'isi' => 'Isi berita.',
            'diambil_at' => Waktu::awalHariIni()->addHours(9),
            'status_proses' => 'selesai',
        ]);

        AnalisisSentimen::create([
            'artikel_id' => $artikel->id,
            'relevan' => $relevan,
            'label_model' => $label,
        ]);

        return $artikel;
    }

    public function test_rentang_tanggal_dibaca_dari_query_dan_dibawa_ke_tampilan(): void
    {
        $this->actingAs($this->walikota)
            ->get('/eksekutif?dari=2026-07-01&sampai=2026-07-31')
            ->assertInertia(fn ($page) => $page
                ->where('periode.dari', '2026-07-01')
                ->where('periode.sampai', '2026-07-31'));
    }

    /** Rentang terbalik biasanya salah ketik, bukan permintaan sungguhan. */
    public function test_rentang_terbalik_ditukar_bukan_ditolak(): void
    {
        $this->actingAs($this->walikota)
            ->get('/eksekutif?dari=2026-07-31&sampai=2026-07-01')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('periode.dari', '2026-07-01')
                ->where('periode.sampai', '2026-07-31'));
    }

    public function test_tanggal_ngawur_jatuh_ke_rentang_bawaan_tujuh_hari(): void
    {
        $this->actingAs($this->walikota)
            ->get('/eksekutif?dari=bukan-tanggal&sampai=juga-bukan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'periode.sampai',
                Waktu::tanggalWita(now()),
            ));
    }

    /** Satu permintaan tidak boleh menyapu bertahun-tahun data. */
    public function test_rentang_sangat_panjang_dipangkas(): void
    {
        $this->actingAs($this->walikota)
            ->get('/eksekutif?dari=2000-01-01&sampai=2026-08-03')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('periode.dari', '2025-08-02'));
    }

    /**
     * Kartu peringatan tidak dirender saat tidak ada isinya, kartu kosong
     * menghabiskan ruang layar yang berharga.
     */
    public function test_kartu_peringatan_null_saat_tidak_ada_alert(): void
    {
        $this->actingAs($this->walikota)
            ->get('/eksekutif')
            ->assertInertia(fn ($page) => $page->where('peringatan', null));
    }

    public function test_pilihan_periode_rolling_diterjemahkan_dengan_benar(): void
    {
        $this->travelTo(now()->setTimezone(Waktu::ZONA)->setDate(2026, 8, 9)->setTime(1, 0));

        $this->actingAs($this->walikota)
            ->get('/eksekutif?period=30d')
            ->assertInertia(fn ($page) => $page
                ->where('dashboard.period.type', '30d')
                ->where('dashboard.period.start', '2026-07-11')
                ->where('dashboard.period.end', '2026-08-09')
                ->where('dashboard.period.previous_start', '2026-06-11')
                ->where('dashboard.period.previous_end', '2026-07-10'));
    }

    public function test_dashboard_baru_mengecualikan_media_yang_hanya_memuat_artikel_tidak_relevan(): void
    {
        $media = Media::create(['nama' => 'Media Tidak Relevan', 'slug' => 'mtr', 'domain' => 'mtr.test']);
        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => $media->id,
            'judul' => 'Artikel di luar konteks Pemerintah Kota Kendari',
            'url' => 'https://mtr.test/tidak-relevan',
            'url_kanonik' => 'https://mtr.test/tidak-relevan',
            'isi' => 'Isi berita.',
            'diambil_at' => Waktu::awalHariIni()->addHours(10),
            'status_proses' => 'selesai',
        ]);
        AnalisisSentimen::create(['artikel_id' => $artikel->id, 'relevan' => false]);
        app(RingkasanHarian::class)->hitung(Waktu::tanggalWita(now()));

        $this->actingAs($this->walikota)
            ->get('/eksekutif?period=today')
            ->assertInertia(fn ($page) => $page
                ->where('dashboard.metrics.total_articles', 3)
                ->where('dashboard.metrics.active_sources', 1)
                ->count('dashboard.top_sources', 1)
                ->where('dashboard.top_sources.0.nama', 'Kendari Pos'));
    }

    public function test_perbandingan_sentimen_menggunakan_poin_persentase(): void
    {
        $kemarin = $this->artikelTambahan('Berita negatif kemarin', relevan: true, label: LabelSentimen::Negatif);
        $kemarin->update(['diambil_at' => Waktu::awalHari(Waktu::tanggalWita(now()->subDay()))->addHours(9)]);
        app(RingkasanHarian::class)->hitung(Waktu::tanggalWita(now()->subDay()));

        $this->actingAs($this->walikota)
            ->get('/eksekutif?period=today')
            ->assertInertia(fn ($page) => $page
                ->where('dashboard.sentiment.positif.percentage', 66.7)
                ->where('dashboard.comparison.sentiment.positif.percentage_points', 66.7)
                ->where('dashboard.comparison.sentiment.negatif.percentage_points', -66.7));
    }

    public function test_topik_dapat_dibuka_ke_artikel_terkait(): void
    {
        $ids = Artikel::withoutGlobalScopes()->orderBy('id')->pluck('id')->all();
        $today = Waktu::tanggalWita(now());
        $topik = ExecutiveTopic::create([
            'period_type' => 'today',
            'start_date' => $today,
            'end_date' => $today,
            'title' => 'Pelayanan publik Pemerintah Kota Kendari mendapat sorotan media',
            'summary' => 'Sejumlah media membahas pelayanan publik pada periode ini.',
            'article_count' => count($ids),
            'positive_count' => 2,
            'neutral_count' => 0,
            'negative_count' => 1,
            'source_count' => 1,
            'dominant_sentiment' => 'positif',
            'trend' => 'baru',
            'priority_score' => 2,
            'priority_level' => 'rendah',
            'article_ids' => $ids,
            'fingerprint' => hash('sha256', 'test'),
            'generated_at' => now(),
        ]);

        $this->actingAs($this->walikota)
            ->get("/eksekutif/topik/{$topik->id}")
            ->assertInertia(fn ($page) => $page
                ->component('eksekutif/Topik')
                ->where('topik.id', $topik->id)
                ->count('artikel', 3));
    }
}
