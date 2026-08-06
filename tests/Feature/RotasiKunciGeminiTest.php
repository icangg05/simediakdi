<?php

namespace Tests\Feature;

use App\Models\KunciGemini;
use App\Services\Ai\RotasiKunciGemini;
use Carbon\CarbonInterface;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\TestCase;

/**
 * Rotasi kunci API Gemini saat kuota habis.
 *
 * Yang diuji di sini kegagalan yang tidak menimbulkan galat apa pun sampai
 * tagihan atau kuota habis: kunci limit yang terus dicoba ulang, waktu pulih
 * yang ditebak alih-alih dibaca dari jawaban Google, dan kunci kedua yang
 * tidak pernah benar-benar terpakai karena provider-nya sudah tersimpan di
 * memori dengan kunci lama.
 */
class RotasiKunciGeminiTest extends TestCase
{
    use RefreshDatabase;

    private RotasiKunciGemini $rotasi;

    protected function setUp(): void
    {
        parent::setUp();

        // Kolom timestamp Postgres di sini berpresisi detik, jadi waktu beku
        // yang masih membawa mikrodetik tidak akan pernah sama dengan yang
        // dibaca kembali dari database.
        Carbon::setTestNow(now()->startOfSecond());

        $this->rotasi = app(RotasiKunciGemini::class);
    }

    public function test_kunci_yang_kena_limit_dilewati_dan_kunci_berikutnya_dipakai(): void
    {
        $pertama = $this->kunci('Kunci A');
        $kedua = $this->kunci('Kunci B');

        $dipakai = [];

        $hasil = $this->rotasi->jalankan(function () use (&$dipakai) {
            $dipakai[] = config('ai.providers.gemini.key');

            if (count($dipakai) === 1) {
                throw $this->limit('GenerateRequestsPerMinutePerProjectPerModel-FreeTier');
            }

            return 'selesai';
        });

        $this->assertSame('selesai', $hasil);
        $this->assertSame(['kunci-kunci-a', 'kunci-kunci-b'], $dipakai);
        $this->assertTrue($pertama->fresh()->sedangLimit());
        $this->assertFalse($kedua->fresh()->sedangLimit());
    }

    /**
     * Kunci yang sudah ditandai tidak boleh dicoba lagi sebelum waktunya.
     *
     * Tanpa ini, rotasi hanya memindahkan masalah: setiap artikel berikutnya
     * tetap membayar satu permintaan gagal ke kunci yang sudah pasti menolak.
     */
    public function test_kunci_yang_masih_limit_tidak_dipanggil_lagi(): void
    {
        $this->kunci('Kunci A', limitSampai: now()->addMinutes(10));
        $this->kunci('Kunci B');

        $dipakai = [];

        $this->rotasi->jalankan(function () use (&$dipakai) {
            $dipakai[] = config('ai.providers.gemini.key');

            return 'selesai';
        });

        $this->assertSame(['kunci-kunci-b'], $dipakai);
    }

    /** Limit harian pulih pada tengah malam waktu Pasifik, bukan 24 jam setelah pemakaian. */
    public function test_limit_harian_ditandai_sampai_tengah_malam_pasifik(): void
    {
        $kunci = $this->kunci('Kunci A');

        try {
            $this->rotasi->jalankan(fn () => throw $this->limit('GenerateRequestsPerDayPerProjectPerModel-FreeTier'));
        } catch (RateLimitedException) {
            // Kunci tunggal yang habis memang berakhir sebagai galat.
        }

        $kunci->refresh();

        $this->assertSame('kuota_harian', $kunci->alasan_limit);
        $this->assertTrue(
            $kunci->limit_sampai->equalTo(Carbon::tomorrow('America/Los_Angeles')),
            'Waktu pulih harus tengah malam berikutnya di zona kuota Google.',
        );
    }

    /** `retryDelay` dari Google dipakai apa adanya, selama tidak lebih pendek dari batas bawah. */
    public function test_jeda_dari_google_dipakai_untuk_limit_per_menit(): void
    {
        $kunci = $this->kunci('Kunci A');

        try {
            $this->rotasi->jalankan(fn () => throw $this->limit(
                'GenerateRequestsPerMinutePerProjectPerModel-FreeTier',
                retryDelay: '90s',
            ));
        } catch (RateLimitedException) {
            // Sama seperti di atas.
        }

        $this->assertSame('retry_delay', $kunci->fresh()->alasan_limit);
        $this->assertTrue($kunci->fresh()->limit_sampai->equalTo(now()->addSeconds(90)));
    }

    public function test_semua_kunci_habis_menyebut_kapan_kuotanya_terbuka(): void
    {
        $this->kunci('Kunci A', limitSampai: now()->addHours(3));

        $this->expectException(RateLimitedException::class);
        $this->expectExceptionMessageMatches('/Semua kunci API Gemini sedang kena limit/');

        $this->rotasi->jalankan(fn () => 'tidak boleh sampai sini');
    }

    /**
     * Tanpa kunci, galatnya menyebutkan cara memperbaikinya.
     *
     * Sejak `.env` tidak lagi memuat GEMINI_API_KEY, tabel kosong berarti
     * sistem memang belum bisa memanggil Gemini. Membiarkan panggilan berjalan
     * tanpa kunci hanya menukar galat yang bisa ditindaklanjuti dengan 401 dari
     * Google yang tidak menyebutkan apa pun tentang halaman Pengaturan.
     */
    public function test_tanpa_kunci_di_database_panggilan_ditolak(): void
    {
        $tersentuh = false;

        try {
            $this->rotasi->jalankan(function () use (&$tersentuh) {
                $tersentuh = true;

                return 'selesai';
            });
            $this->fail('Panggilan tanpa kunci seharusnya dilempar sebagai galat.');
        } catch (RateLimitedException $galat) {
            $this->assertStringContainsString('Tidak ada kunci API Gemini', $galat->getMessage());
        }

        $this->assertFalse($tersentuh, 'Gemini tidak boleh dipanggil tanpa kunci.');
    }

    private function kunci(string $label, ?CarbonInterface $limitSampai = null): KunciGemini
    {
        return KunciGemini::create([
            'label' => $label,
            'kunci' => str($label)->slug()->prepend('kunci-')->value(),
            'aktif' => true,
            'limit_sampai' => $limitSampai,
        ]);
    }

    /** Galat 429 dengan badan seperti yang benar-benar dikirim Google. */
    private function limit(string $quotaId, ?string $retryDelay = null): RateLimitedException
    {
        $rincian = [[
            '@type' => 'type.googleapis.com/google.rpc.QuotaFailure',
            'violations' => [['quotaId' => $quotaId, 'quotaMetric' => 'generativelanguage.googleapis.com/generate_content_free_tier_requests']],
        ]];

        if ($retryDelay !== null) {
            $rincian[] = ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $retryDelay];
        }

        $tanggapan = new Response(new PsrResponse(429, ['Content-Type' => 'application/json'], json_encode([
            'error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'details' => $rincian],
        ])));

        return RateLimitedException::forProvider('gemini', 429, new RequestException($tanggapan));
    }
}
