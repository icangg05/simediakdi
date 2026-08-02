<?php

namespace Tests\Feature;

use App\Enums\LabelSentimen;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\KonteksPantauan;
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

    private KonteksPantauan $utama;

    private string $hariIni;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ringkasan = app(RingkasanHarian::class);
        $this->hariIni = Waktu::tanggalWita(now());

        $this->mediaA = Media::create(['nama' => 'Media A', 'slug' => 'media-a', 'domain' => 'a.test']);

        $this->utama = KonteksPantauan::create([
            'nama' => 'Pemerintah Kota Kendari', 'slug' => 'pemkot', 'utama' => true,
        ]);
    }

    private function artikel(?Media $media, string $status = 'asli', ?int $indukId = null): Artikel
    {
        static $n = 0;
        $n++;

        return Artikel::withoutGlobalScopes()->create([
            'media_id' => $media?->id,
            'judul' => "Berita {$n}",
            'url' => "https://a.test/{$n}",
            'url_kanonik' => "https://a.test/{$n}",
            'isi' => 'Isi berita.',
            'status_dedup' => $status,
            'artikel_induk_id' => $indukId,
            'diambil_at' => Waktu::awalHariIni()->addHours(10),
        ]);
    }

    private function nilai(Artikel $artikel, LabelSentimen $label, bool $perluReview = false): void
    {
        AnalisisSentimen::create([
            'artikel_id' => $artikel->id,
            'konteks_pantauan_id' => $this->utama->id,
            'relevan' => true,
            'label_model' => $label,
            'keyakinan' => $perluReview ? 0.4 : 0.9,
            'perlu_review' => $perluReview,
        ]);
    }

    public function test_baris_agregat_menghitung_asli_dan_salinan_terpisah(): void
    {
        $asli = $this->artikel($this->mediaA);
        $this->artikel($this->mediaA, 'salinan', $asli->id);

        $this->ringkasan->hitung($this->hariIni);

        $agregat = BarisRingkasan::whereNull('media_id')->whereNull('konteks_pantauan_id')->first();

        $this->assertNotNull($agregat, 'Baris agregat untuk dashboard eksekutif harus ada.');
        $this->assertSame(1, $agregat->jumlah_artikel);
        // Salinan dipisah supaya angka utamanya tetap bisa dipercaya.
        $this->assertSame(1, $agregat->jumlah_salinan);
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
            BarisRingkasan::whereNull('media_id')->whereNull('konteks_pantauan_id')->first()->jumlah_artikel,
        );
    }

    public function test_artikel_tanpa_media_tidak_menimpa_baris_agregat(): void
    {
        // Artikel dari Google News yang domainnya belum dikenali.
        $this->artikel(null);
        $this->artikel($this->mediaA);

        $this->ringkasan->hitung($this->hariIni);

        $agregat = BarisRingkasan::whereNull('media_id')->whereNull('konteks_pantauan_id')->first();

        // Keduanya masuk agregat; yang tanpa media tidak membuat baris per-media
        // sendiri yang kuncinya bentrok dengan baris agregat.
        $this->assertSame(2, $agregat->jumlah_artikel);
        $this->assertSame(1, BarisRingkasan::whereNotNull('media_id')->whereNull('konteks_pantauan_id')->count());
    }

    public function test_jumlah_sentimen_terpisah_per_konteks(): void
    {
        $this->nilai($this->artikel($this->mediaA), LabelSentimen::Negatif);
        $this->nilai($this->artikel($this->mediaA), LabelSentimen::Negatif);
        $this->nilai($this->artikel($this->mediaA), LabelSentimen::Positif);
        $this->nilai($this->artikel($this->mediaA), LabelSentimen::Netral, perluReview: true);

        $this->ringkasan->hitung($this->hariIni);

        $baris = BarisRingkasan::whereNull('media_id')
            ->where('konteks_pantauan_id', $this->utama->id)
            ->first();

        $this->assertSame(2, $baris->jumlah_negatif);
        $this->assertSame(1, $baris->jumlah_positif);
        $this->assertSame(1, $baris->jumlah_netral);
        // Perlu review dihitung terpisah, tidak disembunyikan di dalam netral.
        $this->assertSame(1, $baris->jumlah_perlu_review);
    }

    public function test_koreksi_label_ikut_terhitung_saat_dihitung_ulang(): void
    {
        $artikel = $this->artikel($this->mediaA);
        $this->nilai($artikel, LabelSentimen::Negatif);

        $this->ringkasan->hitung($this->hariIni);

        $baris = fn () => BarisRingkasan::whereNull('media_id')
            ->where('konteks_pantauan_id', $this->utama->id)
            ->first();

        $this->assertSame(1, $baris()->jumlah_negatif);

        AnalisisSentimen::where('artikel_id', $artikel->id)
            ->update(['label_manual' => LabelSentimen::Positif]);

        $this->ringkasan->hitung($this->hariIni);

        // Agregasi membaca label_efektif, jadi koreksi manusia ikut terpakai.
        $this->assertSame(0, $baris()->jumlah_negatif);
        $this->assertSame(1, $baris()->jumlah_positif);
    }
}
