<?php

namespace Tests\Feature;

use App\Enums\LabelSentimen;
use App\Jobs\KirimAlertBeritaNegatif;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\AturanAlert;
use App\Models\Media;
use App\Models\PengaturanAlert;
use App\Models\RiwayatAlert;
use App\Services\Alert\PengirimTelegram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Alert berita negatif dikirim seketika, satu pesan per berita.
 *
 * Dua sifatnya yang paling mudah rusak dijaga di sini. Pertama, satu artikel
 * hanya boleh menghasilkan satu pesan seumur hidupnya, betapapun sering ia
 * dinilai ulang. Kedua, beberapa berita negatif berturut-turut harus tetap
 * menjadi beberapa pesan terpisah, bukan digabung, karena masing-masing
 * peristiwa punya penanggung jawab yang berbeda.
 */
class AlertBeritaNegatifTest extends TestCase
{
    use RefreshDatabase;

    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        PengaturanAlert::aktif()->update([
            'telegram_token' => '123456789:palsu',
            'telegram_chat_id' => '-100',
        ]);

        $this->media = Media::create(['nama' => 'Contoh', 'slug' => 'contoh', 'domain' => 'contoh.test']);
    }

    public function test_satu_berita_negatif_mengirim_satu_pesan_berisi_ringkasan_gemini(): void
    {
        $this->aturan();
        $artikel = $this->artikelNegatif('Proyek drainase mangkrak', 'Warga mengeluhkan proyek yang berhenti sejak Mei.');

        (new KirimAlertBeritaNegatif($artikel->id))->handle(app(PengirimTelegram::class));

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return str_contains($request['text'], 'Proyek drainase mangkrak')
                && str_contains($request['text'], 'Warga mengeluhkan proyek yang berhenti sejak Mei.');
        });

        $riwayat = RiwayatAlert::firstOrFail();
        $this->assertSame('terkirim', $riwayat->status_kirim);
        $this->assertSame($artikel->id, $riwayat->artikel_id);
    }

    /**
     * Klasifikasi ulang tidak boleh mengirim pesan kedua untuk artikel yang
     * sama. Penjagaannya unique index, bukan pemeriksaan di PHP, karena dua
     * worker bisa memproses artikel yang sama pada detik yang sama.
     */
    public function test_artikel_yang_sama_hanya_dialertkan_sekali(): void
    {
        $this->aturan();
        $artikel = $this->artikelNegatif('Proyek drainase mangkrak');

        $job = new KirimAlertBeritaNegatif($artikel->id);
        $job->handle(app(PengirimTelegram::class));
        $job->handle(app(PengirimTelegram::class));

        Http::assertSentCount(1);
        $this->assertSame(1, RiwayatAlert::count());
    }

    /** Berita berturut-turut tetap terkirim satu-satu, bukan digabung. */
    public function test_beberapa_berita_negatif_terkirim_satu_per_satu(): void
    {
        $this->aturan();
        $pertama = $this->artikelNegatif('Berita pertama');
        $kedua = $this->artikelNegatif('Berita kedua');

        (new KirimAlertBeritaNegatif($pertama->id))->handle(app(PengirimTelegram::class));
        (new KirimAlertBeritaNegatif($kedua->id))->handle(app(PengirimTelegram::class));

        Http::assertSentCount(2);
        $this->assertSame(2, RiwayatAlert::count());
    }

    /** Aturan yang dimatikan berarti tidak ada pesan sama sekali. */
    public function test_aturan_mati_tidak_mengirim_apa_pun(): void
    {
        $this->aturan(aktif: false);
        $artikel = $this->artikelNegatif('Berita pertama');

        (new KirimAlertBeritaNegatif($artikel->id))->handle(app(PengirimTelegram::class));

        Http::assertNothingSent();
        $this->assertSame(0, RiwayatAlert::count());
    }

    /**
     * Label yang keburu dikoreksi manusia menang atas alasan job dijalankan.
     *
     * Job dibuat saat model menilai negatif, dan antara itu dan pengirimannya
     * bisa ada koreksi. Mengirim peringatan untuk artikel yang barusan
     * dinyatakan netral adalah cara tercepat membuat orang berhenti percaya.
     */
    public function test_artikel_yang_dikoreksi_jadi_netral_tidak_jadi_dikirim(): void
    {
        $this->aturan();
        $artikel = $this->artikelNegatif('Berita pertama');

        AnalisisSentimen::where('artikel_id', $artikel->id)->update(['label_manual' => LabelSentimen::Netral]);

        (new KirimAlertBeritaNegatif($artikel->id))->handle(app(PengirimTelegram::class));

        Http::assertNothingSent();
    }

    /** Yang model sendiri ragukan tidak membangunkan siapa pun. */
    public function test_berita_yang_perlu_review_tidak_dikirim(): void
    {
        $this->aturan();
        $artikel = $this->artikelNegatif('Berita pertama');

        AnalisisSentimen::where('artikel_id', $artikel->id)->update(['perlu_review' => true]);

        (new KirimAlertBeritaNegatif($artikel->id))->handle(app(PengirimTelegram::class));

        Http::assertNothingSent();
    }

    private function aturan(bool $aktif = true): AturanAlert
    {
        return AturanAlert::create([
            'nama' => 'Berita negatif baru',
            'jenis' => 'berita_negatif',
            'kondisi' => ['abaikan_perlu_review' => true],
            'jendela_jam' => 6,
            'jeda_minimal_jam' => 6,
            'kanal' => 'telegram',
            'penerima' => [],
            'aktif' => $aktif,
        ]);
    }

    private function artikelNegatif(string $judul, ?string $ringkasan = null): Artikel
    {
        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => $this->media->id,
            'judul' => $judul,
            'url' => 'https://contoh.test/'.md5($judul),
            'url_kanonik' => 'https://contoh.test/'.md5($judul),
            'diambil_at' => now(),
            'status_proses' => 'selesai',
        ]);

        AnalisisSentimen::create([
            'artikel_id' => $artikel->id,
            'relevan' => true,
            'label_model' => LabelSentimen::Negatif,
            'perlu_review' => false,
            'model_versi' => 'uji',
            'reason_summary' => $ringkasan,
            'dianalisis_at' => now(),
        ]);

        return $artikel;
    }
}
