<?php

namespace Tests\Feature;

use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\Media;
use App\Models\SumberFeed;
use App\Models\UrlDibuang;
use App\Models\User;
use App\Services\Crawler\ItemFeed;
use App\Services\Crawler\PencatatArtikel;
use App\Services\PembuangArtikel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pembuangan artikel dan nisan URL-nya.
 *
 * Nisan ada karena penghapusan tanpa jejak tidak bertahan: deduplikasi lapis 1
 * bersandar pada index unique url_kanonik, dan begitu barisnya hilang, crawl
 * berikutnya membaca artikel yang sama sebagai berita baru lalu mengantrekannya
 * ke Gemini untuk mendapat jawaban yang sama persis.
 */
class BuangArtikelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    private function media(): Media
    {
        return Media::firstOrCreate(
            ['slug' => 'contoh'],
            ['nama' => 'Contoh', 'domain' => 'contoh.test'],
        );
    }

    private function artikel(string $url, bool $relevan = false, string $status = 'selesai'): Artikel
    {
        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => $this->media()->id,
            'judul' => 'Berita contoh',
            'url' => $url,
            'url_kanonik' => $url,
            'diambil_at' => now(),
            'status_proses' => $status,
        ]);

        AnalisisSentimen::create([
            'artikel_id' => $artikel->id,
            'relevan' => $relevan,
            'model_versi' => 'uji-1.0',
            'dianalisis_at' => now(),
        ]);

        return $artikel;
    }

    /**
     * Induk salinan pun ikut terbuang, tidak ada lagi baris yang dikecualikan.
     *
     * Salinannya dipromosikan jadi asli, bukan sekadar kehilangan penunjuk.
     * Constraint chk_artikel_induk_sesuai_dedup menolak baris berstatus salinan
     * yang artikel_induk_id-nya kosong, jadi tanpa promosi itu penghapusannya
     * gagal seluruhnya.
     */
    public function test_induk_salinan_ikut_dibuang_dan_salinannya_jadi_asli(): void
    {
        $induk = $this->artikel('https://contoh.test/induk');

        $salinan = $this->artikel('https://contoh.test/salinan');
        $salinan->forceFill([
            'artikel_induk_id' => $induk->id,
            'status_dedup' => 'salinan',
        ])->save();

        $hasil = app(PembuangArtikel::class)->buang([$induk->id], 'uji');

        $this->assertSame(1, $hasil['dibuang']);
        $this->assertSame(0, $hasil['dilindungi']);
        $this->assertDatabaseMissing('artikel', ['id' => $induk->id]);
        $this->assertDatabaseHas('artikel', [
            'id' => $salinan->id,
            'artikel_induk_id' => null,
            'status_dedup' => 'asli',
        ]);
    }

    public function test_artikel_dibuang_meninggalkan_nisan(): void
    {
        $artikel = $this->artikel('https://contoh.test/a');

        $hasil = app(PembuangArtikel::class)->buang([$artikel->id], 'uji');

        $this->assertSame(1, $hasil['dibuang']);
        $this->assertDatabaseMissing('artikel', ['id' => $artikel->id]);
        $this->assertDatabaseHas('url_dibuang', ['url_kanonik' => 'https://contoh.test/a', 'alasan' => 'uji']);
    }

    public function test_crawl_tidak_memasukkan_kembali_url_yang_sudah_dibuang(): void
    {
        $artikel = $this->artikel('https://contoh.test/a');
        app(PembuangArtikel::class)->buang([$artikel->id], 'uji');

        $sumber = SumberFeed::create([
            'media_id' => $this->media()->id,
            'nama' => 'Contoh RSS',
            'tipe' => 'rss',
            'url' => 'https://contoh.test/rss',
        ]);

        $hasil = app(PencatatArtikel::class)->catat(
            new ItemFeed(judul: 'Berita contoh', url: 'https://contoh.test/a', ringkasan: ''),
            $sumber,
        );

        // Inilah seluruh alasan tabel nisan ada. Tanpanya baris ini lolos,
        // masuk antrean, dan dinilai Gemini untuk kedua kalinya.
        $this->assertNull($hasil);
        $this->assertDatabaseCount('artikel', 0);
    }

    public function test_artikel_relevan_tidak_pernah_masuk_daftar_kandidat(): void
    {
        $this->artikel('https://contoh.test/relevan', relevan: true);
        $this->artikel('https://contoh.test/tidak-relevan');

        $kandidat = PembuangArtikel::kandidat()->pluck('url_kanonik');

        $this->assertCount(1, $kandidat);
        $this->assertSame('https://contoh.test/tidak-relevan', $kandidat->first());
    }

    public function test_perlu_review_ikut_jadi_kandidat(): void
    {
        $this->artikel('https://contoh.test/ragu', relevan: true, status: 'perlu_review');

        $this->assertCount(1, PembuangArtikel::kandidat()->get());
    }

    public function test_artikel_berbukti_pemuatan_ikut_dibuang_dan_buktinya_tetap(): void
    {
        $aman = $this->artikel('https://contoh.test/aman');
        $berbukti = $this->artikel('https://contoh.test/berbukti');

        $kontrak = DB::table('kontrak')->insertGetId([
            'media_id' => $this->media()->id,
            'nomor' => 'K-1',
            'judul' => 'Kontrak contoh',
            'jenis' => 'advertorial',
            'tanggal_mulai' => now()->toDateString(),
            'tanggal_akhir' => now()->addMonth()->toDateString(),
            'target_pemuatan' => 1,
            'status' => 'aktif',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pemuatan')->insert([
            'kontrak_id' => $kontrak,
            'media_id' => $this->media()->id,
            'artikel_id' => $berbukti->id,
            'url' => 'https://contoh.test/berbukti',
            'judul' => 'Berita contoh',
            'tanggal_muat' => now()->toDateString(),
            'sumber_catatan' => 'laporan_media',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $hasil = app(PembuangArtikel::class)->buang([$aman->id, $berbukti->id], 'uji');

        // Laporan pemuatan tidak lagi menahan penghapusan. Foreign key-nya
        // SET NULL dan barisnya menyimpan url, judul, serta arsipnya sendiri,
        // jadi bukti klaim media tetap utuh setelah artikelnya hilang.
        $this->assertSame(2, $hasil['dibuang']);
        $this->assertSame(0, $hasil['dilindungi']);
        $this->assertDatabaseMissing('artikel', ['id' => $berbukti->id]);
        $this->assertDatabaseHas('pemuatan', [
            'url' => 'https://contoh.test/berbukti',
            'artikel_id' => null,
        ]);
    }

    public function test_id_yang_bukan_kandidat_ditolak_meski_dikirim_dari_layar(): void
    {
        $relevan = $this->artikel('https://contoh.test/relevan', relevan: true);

        // Layar bisa basi, jadi id yang dikirim disaring ulang di server.
        $this->actingAs(User::factory()->create())
            ->delete('/admin/artikel/buang', ['id' => [$relevan->id]])
            ->assertRedirect();

        $this->assertDatabaseHas('artikel', ['id' => $relevan->id]);
        $this->assertDatabaseCount('url_dibuang', 0);
    }

    public function test_buang_semua_menyapu_seluruh_kandidat(): void
    {
        $this->artikel('https://contoh.test/a');
        $this->artikel('https://contoh.test/b');
        $relevan = $this->artikel('https://contoh.test/c', relevan: true);

        $this->actingAs(User::factory()->create())
            ->delete('/admin/artikel/buang-semua?tahap=selesai&relevansi=tidak_relevan')
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseCount('artikel', 1);
        $this->assertDatabaseHas('artikel', ['id' => $relevan->id]);
        $this->assertSame(2, UrlDibuang::count());
    }
    public function test_tombol_buang_hanya_muncul_pada_saringan_yang_tepat(): void
    {
        $this->artikel('https://contoh.test/a');

        $pengguna = User::factory()->create();

        // Bawaan halaman adalah Selesai dengan saringan Relevan, dan di situ
        // tombol buang tidak boleh ada sama sekali.
        $this->actingAs($pengguna)->get('/admin/artikel')
            ->assertInertia(fn ($p) => $p->where('pembuangan.boleh', false));

        $this->actingAs($pengguna)->get('/admin/artikel?tahap=selesai&relevansi=tidak_relevan')
            ->assertInertia(fn ($p) => $p->where('pembuangan.boleh', true)->where('pembuangan.jumlah', 1));

        $this->actingAs($pengguna)->get('/admin/artikel?tahap=review')
            ->assertInertia(fn ($p) => $p->where('pembuangan.boleh', true));

        $this->actingAs($pengguna)->get('/admin/artikel?tahap=belum')
            ->assertInertia(fn ($p) => $p->where('pembuangan.boleh', false));
    }

    public function test_buang_semua_ditolak_di_luar_saringan_yang_diizinkan(): void
    {
        $artikel = $this->artikel('https://contoh.test/a');

        // Saringan Relevan. Kalau penjaganya lolos, seluruh artikel tidak
        // relevan tersapu oleh permintaan yang layarnya sendiri tidak pernah
        // menawarkan tombolnya.
        $this->actingAs(User::factory()->create())
            ->delete('/admin/artikel/buang-semua?tahap=selesai&relevansi=relevan')
            ->assertRedirect()
            ->assertSessionHas('galat');

        $this->assertDatabaseHas('artikel', ['id' => $artikel->id]);
    }

    public function test_buang_semua_menghormati_saringan_media(): void
    {
        $lain = Media::create(['nama' => 'Lain', 'slug' => 'lain', 'domain' => 'lain.test']);

        $ikut = $this->artikel('https://contoh.test/ikut');
        $luar = $this->artikel('https://contoh.test/luar');
        $luar->forceFill(['media_id' => $lain->id])->save();

        $this->actingAs(User::factory()->create())
            ->delete("/admin/artikel/buang-semua?tahap=selesai&relevansi=tidak_relevan&media={$this->media()->id}")
            ->assertRedirect();

        // Buang semua pada daftar yang sudah disaring satu media berarti semua
        // milik media itu, bukan semua yang ada di tabel.
        $this->assertDatabaseMissing('artikel', ['id' => $ikut->id]);
        $this->assertDatabaseHas('artikel', ['id' => $luar->id]);
    }
}
