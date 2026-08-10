<?php

namespace Tests\Feature;

use App\Enums\LabelSentimen;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\Media;
use App\Models\RingkasanHarian as BarisRingkasan;
use App\Services\Agregasi\RingkasanHarian;
use App\Support\Waktu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RingkasanHarianTest extends TestCase
{
    use RefreshDatabase;

    private RingkasanHarian $ringkasan;

    private Media $mediaA;

    private string $hariIni;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ringkasan = app(RingkasanHarian::class);
        $this->hariIni = Waktu::tanggalWita(now());

        $this->mediaA = Media::create(['nama' => 'Media A', 'slug' => 'media-a', 'domain' => 'a.test']);
    }

    private function artikel(?Media $media): Artikel
    {
        static $n = 0;
        $n++;

        return Artikel::withoutGlobalScopes()->create([
            'media_id' => $media?->id,
            'judul' => "Berita {$n}",
            'url' => "https://a.test/{$n}",
            'url_kanonik' => "https://a.test/{$n}",
            'isi' => 'Isi berita.',
            'diambil_at' => Waktu::awalHariIni()->addHours(10),
        ]);
    }

    private function nilai(Artikel $artikel, LabelSentimen $label, bool $perluReview = false): void
    {
        AnalisisSentimen::create([
            'artikel_id' => $artikel->id,
            'relevan' => true,
            'label_model' => $label,
            'perlu_review' => $perluReview,
        ]);
    }

    /**
     * Penarikan arsip tidak boleh terbaca sebagai lonjakan pemberitaan.
     *
     * Kegagalannya tidak menimbulkan galat apa pun. Yang terjadi hanya grafik
     * pimpinan yang menampilkan satu menara di hari crawler menyapu arsip, lalu
     * datar di seluruh hari lainnya, dan angka itu terlihat sama wajarnya
     * dengan angka yang benar.
     */
    public function test_artikel_dihitung_pada_tanggal_terbit_bukan_tanggal_unduh(): void
    {
        $kemarin = Waktu::tanggalWita(now()->subDay());

        // Diunduh hari ini, terbit kemarin. Persis bentuk artikel arsip.
        $artikel = $this->artikel($this->mediaA);
        $artikel->update(['dipublikasikan_at' => Waktu::awalHari($kemarin)->addHours(9)]);

        $this->nilai($artikel, LabelSentimen::Positif);

        $this->ringkasan->hitung($this->hariIni);
        $this->ringkasan->hitung($kemarin);

        $hariIni = BarisRingkasan::whereNull('media_id')->where('tanggal', $this->hariIni)->first();
        $baris = BarisRingkasan::whereNull('media_id')->where('tanggal', $kemarin)->first();

        $this->assertSame(1, $baris->jumlah_artikel, 'Artikel harus masuk ke tanggal terbitnya.');
        $this->assertSame(1, $baris->jumlah_positif);
        $this->assertSame(0, $hariIni?->jumlah_artikel ?? 0, 'Tanggal unduh tidak boleh ikut menghitungnya.');
    }

    public function test_artikel_tanpa_tanggal_terbit_jatuh_ke_tanggal_unduh(): void
    {
        // Sebagian feed tidak memuat tanggal terbit. Artikelnya tidak boleh
        // hilang dari seluruh grafik hanya karena kolom itu kosong.
        $this->nilai($this->artikel($this->mediaA), LabelSentimen::Netral);

        $this->ringkasan->hitung($this->hariIni);

        $baris = BarisRingkasan::whereNull('media_id')->where('tanggal', $this->hariIni)->first();

        $this->assertSame(1, $baris->jumlah_artikel);
        $this->assertSame(1, $baris->jumlah_netral);
    }

    public function test_baris_agregat_menghitung_seluruh_artikel(): void
    {
        $this->artikel($this->mediaA);
        $this->artikel($this->mediaA);

        $this->ringkasan->hitung($this->hariIni);

        $agregat = BarisRingkasan::whereNull('media_id')->first();

        $this->assertNotNull($agregat, 'Baris agregat untuk dashboard eksekutif harus ada.');
        $this->assertSame(2, $agregat->jumlah_artikel);
    }

    public function test_menghitung_ulang_memperbarui_baris_yang_sama_bukan_menambah(): void
    {
        $this->artikel($this->mediaA);

        $this->ringkasan->hitung($this->hariIni);
        $jumlahAwal = BarisRingkasan::count();

        $this->artikel($this->mediaA);
        $this->ringkasan->hitung($this->hariIni);

        // Inti UNIQUE NULLS NOT DISTINCT: tanpa itu ON CONFLICT tidak pernah
        // cocok untuk baris agregat dan barisnya menumpuk tiap 10 menit.
        $this->assertSame($jumlahAwal, BarisRingkasan::count());
        $this->assertSame(
            2,
            BarisRingkasan::whereNull('media_id')->first()->jumlah_artikel,
        );
    }

    public function test_artikel_tanpa_media_tidak_menimpa_baris_agregat(): void
    {
        // Artikel dari Google News yang domainnya belum dikenali.
        $this->artikel(null);
        $this->artikel($this->mediaA);

        $this->ringkasan->hitung($this->hariIni);

        $agregat = BarisRingkasan::whereNull('media_id')->first();

        // Keduanya masuk agregat; yang tanpa media tidak membuat baris per-media
        // sendiri yang kuncinya bentrok dengan baris agregat.
        $this->assertSame(2, $agregat->jumlah_artikel);
        $this->assertSame(1, BarisRingkasan::whereNotNull('media_id')->count());
    }

    public function test_koreksi_label_ikut_terhitung_saat_dihitung_ulang(): void
    {
        $artikel = $this->artikel($this->mediaA);
        $this->nilai($artikel, LabelSentimen::Negatif);

        $this->ringkasan->hitung($this->hariIni);

        $baris = fn () => BarisRingkasan::whereNull('media_id')->first();

        $this->assertSame(1, $baris()->jumlah_negatif);

        AnalisisSentimen::where('artikel_id', $artikel->id)
            ->update(['label_manual' => LabelSentimen::Positif]);

        $this->ringkasan->hitung($this->hariIni);

        // Agregasi membaca label_efektif, jadi koreksi manusia ikut terpakai.
        $this->assertSame(0, $baris()->jumlah_negatif);
        $this->assertSame(1, $baris()->jumlah_positif);
    }
}
