<?php

namespace Tests\Feature;

use App\Enums\PeranPengguna;
use App\Jobs\AmbilIsiArtikel;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tambah berita dari portal media, dokumen 04 bagian C.6.
 *
 * Tidak ada persetujuan admin: berita yang dikirim media langsung masuk arsip.
 * Karena itu satu-satunya penjaga yang tersisa adalah pemeriksaan domain, dan
 * di situlah tes ini menaruh perhatiannya. Kalau batas itu jebol, satu akun
 * media bisa menyuntikkan berita milik pesaingnya ke arsip yang dibaca panel
 * eksekutif.
 */
class PortalLaporTest extends TestCase
{
    use RefreshDatabase;

    private Media $media;

    private User $pic;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kendari-pos', 'domain' => 'kendaripos.test']);

        $this->pic = User::create([
            'name' => 'PIC Kendari Pos', 'email' => 'pic@kendaripos.test', 'password' => 'rahasia123',
            'peran' => PeranPengguna::Media, 'media_id' => $this->media->id, 'email_verified_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $baris */
    private function tambah(array $baris)
    {
        return $this->actingAs($this->pic)->post('/portal/lapor', ['baris' => [$baris]]);
    }

    /** Artikel media ini yang sudah dinilai relevan dan berlabel. */
    private function beritaTerpantau(
        string $url,
        string $judul = 'Berita terpantau',
        ?string $terbit = null,
        ?int $dilaporkanOleh = null,
    ): Artikel {
        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => $this->media->id, 'judul' => $judul,
            'url' => $url, 'url_kanonik' => $url,
            'diambil_at' => now(), 'status_proses' => 'selesai',
            'dipublikasikan_at' => $terbit,
            'dilaporkan_oleh' => $dilaporkanOleh,
        ]);

        AnalisisSentimen::create([
            'artikel_id' => $artikel->id, 'relevan' => true,
            'label_model' => 'netral', 'perlu_review' => false,
            'model_versi' => 'uji', 'dianalisis_at' => now(),
        ]);

        return $artikel;
    }

    /**
     * F-50. Ini batas antar media, bukan sekadar bantuan pengisian form, jadi
     * ditegakkan saat menyimpan dan bukan hanya saat pratinjau.
     */
    public function test_url_dari_domain_media_lain_ditolak(): void
    {
        $this->tambah([
            'url' => 'https://mediapesaing.test/berita-a',
            'judul' => 'Berita milik media lain',
            'tanggal' => now()->toDateString(),
        ]);

        $this->assertSame(0, Artikel::withoutGlobalScopes()->count());
    }

    public function test_subdomain_media_sendiri_diterima(): void
    {
        $this->tambah([
            'url' => 'https://daerah.kendaripos.test/berita-b',
            'judul' => 'Berita dari subdomain',
            'tanggal' => now()->toDateString(),
        ]);

        $this->assertSame(1, Artikel::withoutGlobalScopes()->count());
    }

    /**
     * Berita kiriman masuk arsip seketika, tanpa antrean persetujuan, dan
     * melewati jalur pemrosesan yang sama dengan temuan crawler.
     */
    public function test_berita_yang_ditambahkan_langsung_masuk_arsip(): void
    {
        $this->tambah([
            'url' => 'https://kendaripos.test/berita-c',
            'judul' => 'Berita C',
            'tanggal' => now()->subDay()->toDateString(),
        ]);

        $artikel = Artikel::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('Berita C', $artikel->judul);
        $this->assertSame($this->media->id, $artikel->media_id);
        // Pembeda berita kiriman dari temuan crawler, dipakai kartu bias F-54.
        $this->assertSame($this->pic->id, $artikel->dilaporkan_oleh);
        Queue::assertPushed(AmbilIsiArtikel::class);
    }

    /**
     * Berita yang sudah ditangkap crawler tidak perlu ditambahkan lagi. Kalau
     * ini jebol, satu berita masuk arsip dua kali dan seluruh agregasi
     * menghitungnya dobel.
     */
    public function test_url_yang_sudah_ada_di_arsip_tidak_membuat_baris_kedua(): void
    {
        $this->beritaTerpantau('https://kendaripos.test/berita-d', 'Berita D');

        // Parameter pelacak berbeda, berita yang sama. Pemeriksaan memakai URL
        // kanonik justru untuk kasus ini.
        $this->tambah([
            'url' => 'https://kendaripos.test/berita-d?utm_source=wa',
            'judul' => 'Berita D',
            'tanggal' => now()->toDateString(),
        ]);

        $this->assertSame(1, Artikel::withoutGlobalScopes()->count());
    }

    /** F-48: berita yang sudah terpantau tampil sebelum form. */
    public function test_halaman_tambah_menampilkan_berita_yang_sudah_terpantau(): void
    {
        $this->beritaTerpantau('https://kendaripos.test/berita-e', 'Sudah terpantau');

        $this->actingAs($this->pic)
            ->get('/portal/lapor')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sudahOtomatis', 1)
                ->where('sudahOtomatis.0.judul', 'Sudah terpantau'));
    }

    /**
     * Berita kiriman belum tampil di "Berita saya" sampai penilaiannya selesai.
     * Tahapannya harus terbaca, kalau tidak media mengirim ulang berita yang
     * sebenarnya sudah masuk.
     */
    public function test_kiriman_sendiri_menampilkan_tahap_pemrosesannya(): void
    {
        $this->tambah([
            'url' => 'https://kendaripos.test/berita-f',
            'judul' => 'Berita F',
            'tanggal' => now()->toDateString(),
        ]);

        $this->actingAs($this->pic)
            ->get('/portal/lapor')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('kiriman', 1)
                ->where('kiriman.0.status', 'diproses'));
    }

    /** Berita yang dinilai di luar pantauan tidak boleh terbaca sebagai masih diproses. */
    public function test_kiriman_yang_tidak_relevan_ditandai_di_luar_pantauan(): void
    {
        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => $this->media->id, 'judul' => 'Berita G',
            'url' => 'https://kendaripos.test/berita-g', 'url_kanonik' => 'https://kendaripos.test/berita-g',
            'diambil_at' => now(), 'status_proses' => 'selesai', 'dilaporkan_oleh' => $this->pic->id,
        ]);

        AnalisisSentimen::create([
            'artikel_id' => $artikel->id, 'relevan' => false,
            'perlu_review' => false, 'model_versi' => 'uji', 'dianalisis_at' => now(),
        ]);

        $this->actingAs($this->pic)
            ->get('/portal/lapor')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('kiriman.0.status', 'di_luar_pantauan'));
    }

    /**
     * Pemeriksaan URL harus menyebut keadaan artikelnya, bukan satu kalimat
     * untuk semua keadaan.
     *
     * Ini keluhan nyata: media menempel tautan berita yang tidak relevan, layar
     * menjawab "sudah ada di sistem, penilaiannya masih berjalan", lalu berita
     * itu tidak pernah muncul di mana pun. Crawler memang menyimpan seluruh isi
     * feed lebih dulu, termasuk yang kemudian dinilai di luar pantauan, jadi
     * tercatat tidak sama dengan terpantau dan pesannya harus mengatakan itu.
     */
    public function test_periksa_menyebut_url_tercatat_yang_di_luar_pantauan(): void
    {
        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => $this->media->id, 'judul' => 'Berita tidak relevan',
            'url' => 'https://kendaripos.test/berita-i', 'url_kanonik' => 'https://kendaripos.test/berita-i',
            'diambil_at' => now(), 'status_proses' => 'tidak_relevan',
        ]);

        AnalisisSentimen::create([
            'artikel_id' => $artikel->id, 'relevan' => false,
            'perlu_review' => false, 'model_versi' => 'uji', 'dianalisis_at' => now(),
        ]);

        $this->actingAs($this->pic)
            ->post('/portal/lapor/periksa', ['tautan' => 'https://kendaripos.test/berita-i'])
            ->assertSessionHas('hasilPeriksa', fn (array $hasil) => $hasil['baris'][0]['tahap'] === 'di_luar_pantauan'
                && str_contains($hasil['baris'][0]['pesan'], 'di luar pantauan'));
    }

    /** Tanggal terbit di masa depan hampir selalu salah ketik, bukan kiriman sah. */
    public function test_tanggal_terbit_di_masa_depan_ditolak(): void
    {
        $this->tambah([
            'url' => 'https://kendaripos.test/berita-h',
            'judul' => 'Berita H',
            'tanggal' => now()->addDay()->toDateString(),
        ])->assertSessionHasErrors('baris.0.tanggal');
    }

    /**
     * "Berita saya" hanya memuat yang sudah dinilai relevan dan berlabel,
     * populasi yang sama dengan panel eksekutif. Artikel mentah hasil crawl
     * tidak boleh bocor ke sini.
     */
    public function test_berita_saya_hanya_memuat_yang_relevan_dan_berlabel(): void
    {
        $this->beritaTerpantau('https://kendaripos.test/relevan', 'Berita relevan');

        // Belum dinilai sama sekali. Tidak boleh muncul.
        Artikel::withoutGlobalScopes()->create([
            'media_id' => $this->media->id, 'judul' => 'Belum dinilai',
            'url' => 'https://kendaripos.test/mentah', 'url_kanonik' => 'https://kendaripos.test/mentah',
            'diambil_at' => now(), 'status_proses' => 'mentah',
        ]);

        $this->actingAs($this->pic)
            ->get('/portal/berita')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('artikel.data', 1)
                ->where('artikel.data.0.judul', 'Berita relevan'));
    }

    /**
     * Penanda asal baris. Media perlu tahu berita mana yang tertangkap sistem
     * tanpa diminta, karena itu yang menentukan apakah sumber feed-nya sehat.
     */
    public function test_berita_saya_menandai_asal_tiap_baris(): void
    {
        $this->beritaTerpantau('https://kendaripos.test/otomatis', 'Temuan crawler');
        $this->beritaTerpantau('https://kendaripos.test/kiriman', 'Kiriman sendiri', dilaporkanOleh: $this->pic->id);

        $this->actingAs($this->pic)
            ->get('/portal/berita?urut=judul&arah=asc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('artikel.data', 2)
                ->where('artikel.data.0.judul', 'Kiriman sendiri')
                ->where('artikel.data.0.ditambahkan_sendiri', true)
                ->where('artikel.data.1.judul', 'Temuan crawler')
                ->where('artikel.data.1.ditambahkan_sendiri', false));
    }

    /**
     * Rentang disaring menurut tanggal terbit, bukan tanggal unduh. Penarikan
     * arsip memasukkan berita lama pada satu hari yang sama, dan menyaringnya
     * dengan tanggal unduh membuat berita bulan lalu menghilang dari rentang
     * yang seharusnya memuatnya.
     */
    public function test_berita_saya_disaring_rentang_tanggal_terbit(): void
    {
        $this->beritaTerpantau('https://kendaripos.test/baru', 'Terbit kemarin', terbit: now()->subDay()->toDateTimeString());
        $this->beritaTerpantau('https://kendaripos.test/lama', 'Terbit tahun lalu', terbit: now()->subYear()->toDateTimeString());

        // Bawaan 30 hari: yang tahun lalu di luar rentang.
        $this->actingAs($this->pic)
            ->get('/portal/berita')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('artikel.data', 1)
                ->where('artikel.data.0.judul', 'Terbit kemarin'));

        $this->actingAs($this->pic)
            ->get('/portal/berita?dari='.now()->subYear()->subWeek()->toDateString().'&sampai='.now()->toDateString())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('artikel.data', 2));
    }

    /** Rentang yang sedang berlaku ikut dikirim, supaya pemilih tanggal tidak menampilkan nilai basi. */
    public function test_berita_saya_mengirim_rentang_yang_berlaku(): void
    {
        $this->actingAs($this->pic)
            ->get('/portal/berita?dari=2026-07-01&sampai=2026-07-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('periode.dari', '2026-07-01')
                ->where('periode.sampai', '2026-07-31'));
    }

    /**
     * Portal tidak boleh membocorkan sentimen ke media (dokumen 01 bagian 8).
     * Diuji terhadap bentuk resource-nya, bukan terhadap tampilan, karena
     * kebocoran akan terjadi lewat data yang terkirim.
     */
    public function test_berita_saya_tidak_memuat_field_sentimen(): void
    {
        $this->beritaTerpantau('https://kendaripos.test/uji', 'Berita uji');

        $this->actingAs($this->pic)
            ->get('/portal/berita')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('artikel.data', 1)
                ->missing('artikel.data.0.label_efektif')
                ->missing('artikel.data.0.analisis_sentimen')
                ->missing('artikel.data.0.isi'));
    }

    /**
     * Media boleh mencabut kirimannya sendiri, termasuk yang sudah terhitung.
     *
     * Batas ini ditetapkan pemilik produk pada 12 Agustus 2026, dan akibatnya
     * tertulis lengkap di PembuangArtikel::buangKirimanPortal(). Diuji dengan
     * berita berlencana "Tampil" justru karena itu kasus yang paling berat
     * akibatnya: kalau suatu saat kebijakannya dibalik, tes inilah yang gagal
     * lebih dulu dan memaksa keputusannya ditinjau ulang, bukan ditemukan
     * belakangan lewat angka realisasi yang menyusut tanpa penjelasan.
     */
    public function test_media_bisa_mencabut_kirimannya_sendiri(): void
    {
        $artikel = $this->beritaTerpantau(
            'https://kendaripos.test/kiriman-saya',
            'Kiriman sendiri',
            dilaporkanOleh: $this->pic->id,
        );

        $this->actingAs($this->pic)
            ->delete("/portal/lapor/{$artikel->id}")
            ->assertRedirect('/portal/lapor');

        $this->assertSame(0, Artikel::withoutGlobalScopes()->whereKey($artikel->id)->count());
    }

    /**
     * Temuan crawler tidak bisa dicabut dari portal, walaupun medianya sendiri.
     *
     * `dilaporkan_oleh` kosong berarti sistem yang menemukannya, dan arsip
     * temuan sistem bukan milik media untuk disunting. 403, bukan 404: barisnya
     * memang ada dan memang milik media ini, yang ditolak jenis beritanya.
     */
    public function test_media_tidak_bisa_mencabut_temuan_crawler(): void
    {
        $artikel = $this->beritaTerpantau('https://kendaripos.test/temuan-sistem', 'Temuan crawler');

        $this->actingAs($this->pic)
            ->delete("/portal/lapor/{$artikel->id}")
            ->assertForbidden();

        $this->assertSame(1, Artikel::withoutGlobalScopes()->whereKey($artikel->id)->count());
    }

    /**
     * Batas antar media, sama pentingnya dengan F-50 pada arah sebaliknya.
     *
     * Scope global MilikMedia yang menegakkannya lewat route model binding,
     * jadi jawabannya 404: bagi akun ini barisnya memang tidak pernah ada.
     */
    public function test_media_tidak_bisa_mencabut_kiriman_media_lain(): void
    {
        $lain = Media::create(['nama' => 'Media Pesaing', 'slug' => 'media-pesaing', 'domain' => 'mediapesaing.test']);

        $picLain = User::create([
            'name' => 'PIC Pesaing', 'email' => 'pic@mediapesaing.test', 'password' => 'rahasia123',
            'peran' => PeranPengguna::Media, 'media_id' => $lain->id, 'email_verified_at' => now(),
        ]);

        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => $lain->id, 'judul' => 'Kiriman media lain',
            'url' => 'https://mediapesaing.test/kiriman', 'url_kanonik' => 'https://mediapesaing.test/kiriman',
            'diambil_at' => now(), 'status_proses' => 'selesai',
            'dilaporkan_oleh' => $picLain->id,
        ]);

        $this->actingAs($this->pic)
            ->delete("/portal/lapor/{$artikel->id}")
            ->assertNotFound();

        $this->assertSame(1, Artikel::withoutGlobalScopes()->whereKey($artikel->id)->count());
    }
}
