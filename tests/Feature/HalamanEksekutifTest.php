<?php

namespace Tests\Feature;

use App\Enums\LabelSentimen;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\Media;
use App\Models\NarasiEksekutif as BarisNarasi;
use App\Models\User;
use App\Services\Agregasi\NarasiEksekutif;
use App\Services\Agregasi\RingkasanHarian;
use App\Support\Periode;
use App\Support\Waktu;
use Carbon\CarbonImmutable;
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

        $this->travelTo(CarbonImmutable::parse('2026-08-15 12:00:00', Waktu::ZONA));

        $this->walikota = User::factory()->walikota()->create();

        $media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test', 'partner' => true]);

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

        foreach (['/eksekutif', '/eksekutif/sentimen', '/eksekutif/media', '/eksekutif/berita', '/eksekutif/laporan'] as $url) {
            $this->get($url)->assertOk("Halaman {$url} tidak mengembalikan 200.");
        }
    }

    public function test_pengguna_media_tidak_bisa_membuka_panel_eksekutif(): void
    {
        $media = Media::first();

        $this->actingAs(User::factory()->media($media)->create())
            ->get('/eksekutif')
            ->assertForbidden();

        $this->get('/eksekutif/laporan')->assertForbidden();
    }

    public function test_laporan_memakai_satu_bulan_kalender_dan_hanya_media_aktif(): void
    {
        Media::create([
            'nama' => 'Media Aktif Tanpa Berita',
            'slug' => 'aktif-tanpa-berita',
            'domain' => 'aktif-tanpa-berita.test',
            'partner' => false,
        ]);
        Media::create([
            'nama' => 'Media Nonaktif',
            'slug' => 'nonaktif-laporan',
            'domain' => 'nonaktif-laporan.test',
            'partner' => false,
            'aktif' => false,
        ]);

        $this->actingAs($this->walikota)
            ->get('/eksekutif/laporan?bulan=2026-08')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('eksekutif/Laporan')
                ->where('bulan', '2026-08')
                ->where('opsiBulan.0', '2026-08')
                ->where('periode.dari', '2026-08-01')
                ->where('periode.sampai', '2026-08-31')
                ->where('kpi.berlabel', 3)
                ->where('kpi.media_total_aktif', 2)
                ->count('peringkatMedia', 2)
                ->where('peringkatMedia.0.nama', 'Kendari Pos')
                ->where('peringkatMedia.1.nama', 'Media Aktif Tanpa Berita'));
    }

    public function test_bulan_laporan_tidak_valid_kembali_ke_bulan_saat_ini(): void
    {
        $this->actingAs($this->walikota)
            ->get('/eksekutif/laporan?bulan=bukan-bulan')
            ->assertInertia(fn ($page) => $page
                ->where('bulan', '2026-08')
                ->where('periode.dari', '2026-08-01')
                ->where('periode.sampai', '2026-08-31'));
    }

    public function test_laporan_hanya_menampilkan_narasi_gemini_dari_bulan_yang_dipilih(): void
    {
        foreach ([
            ['2026-08-01', '2026-08-31', 'Analisis bulan Agustus', 'sidik-agustus'],
            ['2026-09-01', '2026-09-30', 'Analisis bulan September', 'sidik-september'],
        ] as [$dari, $sampai, $judul, $sidik]) {
            BarisNarasi::create([
                'periode' => '30d',
                'dari' => $dari,
                'sampai' => $sampai,
                'nada' => 'campuran',
                'judul' => $judul,
                'ringkasan' => "Ringkasan {$judul}.",
                'poin' => [['teks' => 'Pola pemberitaan utama.', 'artikel_ids' => []]],
                'jumlah_artikel' => 1,
                'sidik' => $sidik,
                'model' => 'gemini-uji',
                'dibuat_at' => now(),
            ]);
        }

        $this->actingAs($this->walikota)
            ->get('/eksekutif/laporan?bulan=2026-08')
            ->assertInertia(fn ($page) => $page
                ->where('narasi.judul', 'Analisis bulan Agustus')
                ->where('narasi.dari', '2026-08-01')
                ->where('narasi.sampai', '2026-08-31'));
    }

    public function test_rentang_mingguan_laporan_dipotong_pada_batas_bulan(): void
    {
        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => Media::first()->id,
            'judul' => 'Berita awal Agustus',
            'url' => 'https://kp.test/awal-agustus',
            'url_kanonik' => 'https://kp.test/awal-agustus',
            'isi' => 'Isi berita.',
            'diambil_at' => Waktu::awalHari('2026-08-01')->addHours(9),
            'status_proses' => 'selesai',
        ]);

        AnalisisSentimen::create([
            'artikel_id' => $artikel->id,
            'relevan' => true,
            'label_model' => LabelSentimen::Positif,
        ]);

        app(RingkasanHarian::class)->hitung('2026-08-01');

        $this->actingAs($this->walikota)
            ->get('/eksekutif/laporan?bulan=2026-08')
            ->assertInertia(fn ($page) => $page
                // Kelompok SQL-nya dimulai Senin, 27 Juli. Yang ditampilkan
                // kepada pengguna tetap hanya bagian yang berada di Agustus.
                ->where('deret.baris.0.tanggal', '2026-07-27')
                ->where('deret.baris.0.rentang_dari', '2026-08-01')
                ->where('deret.baris.0.rentang_sampai', '2026-08-02'));
    }

    public function test_bulan_tanpa_pemberitaan_tetap_bisa_dibuka_sebagai_laporan_kosong(): void
    {
        $this->actingAs($this->walikota)
            ->get('/eksekutif/laporan?bulan=2026-07')
            ->assertInertia(fn ($page) => $page
                ->where('bulan', '2026-07')
                ->where('opsiBulan.0', '2026-08')
                ->where('opsiBulan.1', '2026-07')
                ->where('kpi.berlabel', 0));
    }

    public function test_preset_dashboard_mengikuti_batas_kalender(): void
    {
        $harapan = [
            'today' => ['2026-08-15', '2026-08-15'],
            // 15 Agustus 2026 jatuh pada Sabtu: pekannya tetap Senin-Minggu.
            '7d' => ['2026-08-10', '2026-08-16'],
            '30d' => ['2026-08-01', '2026-08-31'],
            // Dua bulan penuh sebelumnya, lalu tanggal 1-15 bulan berjalan.
            '90d' => ['2026-06-01', '2026-08-15'],
        ];

        $narasi = app(NarasiEksekutif::class);

        foreach ($harapan as $nama => [$dari, $sampai]) {
            $periode = Periode::dariPreset($nama);
            [$narasiDari, $narasiSampai] = $narasi->rentang($nama);

            $this->assertSame($dari, $periode->dari->toDateString());
            $this->assertSame($sampai, $periode->sampai->toDateString());
            $this->assertSame($dari, $narasiDari->toDateString());
            $this->assertSame($sampai, $narasiSampai->toDateString());
            $this->assertSame($nama, $narasi->preset($periode->dari, $periode->sampai));
        }

        $this->actingAs($this->walikota)
            ->get('/eksekutif')
            ->assertInertia(fn ($page) => $page
                ->where('periode.dari', '2026-08-10')
                ->where('periode.sampai', '2026-08-16'));
    }

    /** Status kerja sama media tersedia pada seluruh daftar yang dibaca pimpinan. */
    public function test_status_kerja_sama_media_dikirim_ke_daftar_eksekutif(): void
    {
        $this->actingAs($this->walikota);

        $this->get('/eksekutif')->assertInertia(fn ($page) => $page
            ->where('beritaPerhatian.0.media_partner', true)
            ->where('beritaPositif.0.media_partner', true)
            ->where('beritaTerbaru.0.media_partner', true));

        $this->get('/eksekutif/media')->assertInertia(fn ($page) => $page
            ->where('peringkat.0.partner', true));

        $this->get('/eksekutif/berita')->assertInertia(fn ($page) => $page
            ->where('artikel.data.0.media_partner', true));

        $this->artikelTambahan('Berita netral dari media kerja sama', relevan: true, label: LabelSentimen::Netral);

        $this->get('/eksekutif/sentimen')->assertInertia(fn ($page) => $page
            ->where('beritaNegatif.0.media_partner', true)
            ->where('beritaNetral.0.media_partner', true)
            ->where('beritaPositif.0.media_partner', true));
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
                ->where('kpi.media_aktif', 1)
                ->where('kpi.media_total_aktif', 1)
                ->where('kpi.media_bekerja_sama', 1)
                ->where('kpi.media_tidak_bekerja_sama', 0));
    }

    public function test_kpi_media_membandingkan_yang_memberitakan_dengan_seluruh_media_aktif(): void
    {
        Media::create([
            'nama' => 'Partner Diam',
            'slug' => 'partner-diam',
            'domain' => 'partner-diam.test',
            'partner' => true,
        ]);
        Media::create([
            'nama' => 'Nonpartner Diam',
            'slug' => 'nonpartner-diam',
            'domain' => 'nonpartner-diam.test',
            'partner' => false,
        ]);
        $nonaktif = Media::create([
            'nama' => 'Media Nonaktif',
            'slug' => 'media-nonaktif',
            'domain' => 'media-nonaktif.test',
            'partner' => false,
            'aktif' => false,
        ]);

        // Media nonaktif sengaja punya berita pada periode yang sama. Ia tetap
        // menjadi bagian riwayat, tetapi tidak boleh menjadi pembilang maupun
        // penyebut rasio media yang masih dipantau.
        $this->artikelTambahan('Berita dari media nonaktif', relevan: true, label: LabelSentimen::Netral, media: $nonaktif);
        app(RingkasanHarian::class)->hitung(Waktu::tanggalWita(now()));

        $hariIni = Waktu::tanggalWita(now());

        $this->actingAs($this->walikota)
            ->get("/eksekutif?dari={$hariIni}&sampai={$hariIni}")
            ->assertInertia(fn ($page) => $page
                ->where('kpi.media_aktif', 1)
                ->where('kpi.media_total_aktif', 3)
                ->where('kpi.media_bekerja_sama', 2)
                ->where('kpi.media_tidak_bekerja_sama', 1));
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
            ['2026-08-01', '2026-08-14', 'harian'],
            ['2026-08-01', '2026-08-31', 'mingguan'],
            ['2026-05-04', '2026-08-01', 'mingguan'],
            ['2026-01-01', '2026-08-01', 'bulanan'],
        ];

        foreach ($harapan as [$dari, $sampai, $satuan]) {
            $this->get("/eksekutif?dari={$dari}&sampai={$sampai}")
                ->assertInertia(fn ($page) => $page->where('deret.satuan', $satuan));
        }
    }

    /**
     * Grafik batang beranimasi memakai satuan yang lebih kasar daripada grafik
     * garis, karena satu periode di sana memakan satu bingkai animasi penuh.
     * Rentang sembilan puluh hari yang jadi tiga belas titik garis harus jatuh
     * ke tujuh bingkai batang.
     */
    public function test_grafik_media_memakai_satuan_lebih_kasar_daripada_grafik_garis(): void
    {
        $this->actingAs($this->walikota);

        $this->get('/eksekutif?dari=2026-05-04&sampai=2026-08-01')
            ->assertInertia(fn ($page) => $page
                ->where('deret.satuan', 'mingguan')
                ->where('deretMedia.satuan', 'dua_mingguan'));
    }

    /**
     * Sumbu grafik batang beranimasi adalah nama media, dan sumbu itu tidak
     * boleh berubah saat periodenya berganti. Karena itu tiap baris periode
     * wajib sepanjang daftar medianya, termasuk periode yang medianya tidak
     * menerbitkan apa pun, dan urutan angkanya wajib mengikuti urutan daftar.
     */
    public function test_deret_media_menyusun_sumbu_media_yang_tetap(): void
    {
        $this->actingAs($this->walikota);

        $deret = $this->get('/eksekutif/sentimen')->viewData('page')['props']['deretMedia'];

        $this->assertSame(['Kendari Pos'], $deret['media']);
        $this->assertNotEmpty($deret['baris']);

        foreach ($deret['baris'] as $baris) {
            foreach (['positif', 'netral', 'negatif'] as $nada) {
                $this->assertCount(count($deret['media']), $baris[$nada], "Baris {$baris['tanggal']} tidak sepanjang sumbu medianya.");
            }
        }

        $hariIni = collect($deret['baris'])->last();

        $this->assertSame([2], $hariIni['positif']);
        $this->assertSame([0], $hariIni['netral']);
        $this->assertSame([1], $hariIni['negatif']);
    }

    /**
     * Halaman peringkat menyebut seluruh media terdaftar, dashboard tidak.
     *
     * Keduanya memanggil `peringkatMedia`, tapi menjawab pertanyaan yang
     * berbeda. Halaman peringkat harus menyebut media yang diam, karena itu satu
     * satunya tempat pimpinan bisa melihat siapa yang tidak memberitakan sama
     * sekali. Kartu di dashboard hanya menampung enam baris, jadi media nol
     * berita di sana cuma mendorong keluar media yang benar benar menulis.
     */
    public function test_halaman_peringkat_menyebut_media_yang_tidak_memberitakan(): void
    {
        Media::create(['nama' => 'Media Diam', 'slug' => 'md', 'domain' => 'md.test']);
        Media::create(['nama' => 'Media Terhapus', 'slug' => 'mt', 'domain' => 'mt.test'])->delete();

        $this->actingAs($this->walikota);

        $this->get('/eksekutif/media')->assertInertia(fn ($page) => $page
            // Kendari Pos dan Media Diam. Yang sudah dihapus tidak ikut.
            ->count('peringkat', 2)
            ->where('peringkat.0.nama', 'Kendari Pos')
            ->where('peringkat.0.jumlah_artikel', 3)
            ->where('peringkat.1.nama', 'Media Diam')
            ->where('peringkat.1.jumlah_artikel', 0)
            ->where('peringkat.1.jumlah_negatif', 0));

        $this->get('/eksekutif')->assertInertia(fn ($page) => $page
            ->count('peringkatMedia', 1)
            ->where('peringkatMedia.0.nama', 'Kendari Pos'));
    }

    /** Artikel tambahan pada hari ini, di media yang sama dengan setUp. */
    private function artikelTambahan(string $judul, bool $relevan, ?LabelSentimen $label, ?Media $media = null): Artikel
    {
        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => ($media ?? Media::first())->id,
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

    public function test_seluruh_halaman_eksekutif_menyediakan_pilihan_bulan(): void
    {
        $this->actingAs($this->walikota);

        foreach (['/eksekutif', '/eksekutif/sentimen', '/eksekutif/media', '/eksekutif/berita'] as $url) {
            $this->get("{$url}?dari=2026-07-01&sampai=2026-07-31")
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('periode.dari', '2026-07-01')
                    ->where('periode.sampai', '2026-07-31')
                    ->where('opsiBulan.0', '2026-08')
                    ->where('opsiBulan.1', '2026-07'));
        }
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

    public function test_tanggal_ngawur_jatuh_ke_minggu_kalender_saat_ini(): void
    {
        $this->actingAs($this->walikota)
            ->get('/eksekutif?dari=bukan-tanggal&sampai=juga-bukan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('periode.dari', '2026-08-10')
                ->where('periode.sampai', '2026-08-16'));
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
}
