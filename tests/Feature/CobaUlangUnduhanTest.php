<?php

namespace Tests\Feature;

use App\Services\Crawler\GagalMengunduh;
use App\Services\Crawler\PengunduhHalaman;
use App\Services\Crawler\ValidatorUrl;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * Coba ulang satu kali untuk kegagalan sesaat.
 *
 * Ditulis karena log crawl sungguhan memperlihatkan pola yang sama berulang:
 * Tempo menolak sambungan tiga kali dalam sepuluh hari lalu normal lagi
 * beberapa menit kemudian, dan Figur Sultra menjawab 500 satu kali di antara
 * 83 permintaan yang semuanya berhasil. Keempat kegagalan itu sudah cukup
 * untuk memunculkan lencana merah di dashboard, padahal tidak ada satu pun
 * yang perlu ditangani manusia.
 *
 * Yang diuji bukan hanya "ada coba ulang", melainkan batasnya: 403 dan 404
 * tidak boleh diulang, karena keduanya jawaban pasti dan mengulanginya hanya
 * menambah beban ke situs orang lain tanpa pernah berhasil.
 */
class CobaUlangUnduhanTest extends TestCase
{
    private const URL = 'https://contoh-media.test/feed';

    /**
     * @param  list<mixed>  $antrean  Jawaban berurutan untuk tiap permintaan.
     */
    private function pengunduh(array $antrean): array
    {
        // Validator dipalsukan supaya tes tidak menyentuh DNS sama sekali.
        $validator = Mockery::mock(ValidatorUrl::class);
        $validator->shouldReceive('pastikanAman')->andReturnNull();

        // robots.txt disemai ke cache, kalau tidak pengunduh akan mengambilnya
        // lebih dulu dan memakan satu jawaban dari antrean palsu.
        Cache::put('robots:https://contoh-media.test', '', now()->addHour());

        $mock = new MockHandler($antrean);

        return [
            new PengunduhHalaman($validator, ['handler' => HandlerStack::create($mock)]),
            $mock,
        ];
    }

    public function test_sambungan_ditolak_dicoba_ulang_sekali_lalu_berhasil(): void
    {
        [$pengunduh] = $this->pengunduh([
            new ConnectException('Connection refused', new Request('GET', self::URL)),
            new Response(200, [], '<rss>isi</rss>'),
        ]);

        $this->assertSame('<rss>isi</rss>', $pengunduh->unduh(self::URL));
    }

    public function test_galat_server_dicoba_ulang_sekali_lalu_berhasil(): void
    {
        [$pengunduh] = $this->pengunduh([
            new Response(500, [], 'Internal Server Error'),
            new Response(200, [], '<rss>isi</rss>'),
        ]);

        $this->assertSame('<rss>isi</rss>', $pengunduh->unduh(self::URL));
    }

    /** Dua kegagalan berturut-turut tetap menyerah, tidak berputar selamanya. */
    public function test_gagal_dua_kali_berhenti_dan_melempar(): void
    {
        [$pengunduh, $mock] = $this->pengunduh([
            new Response(503, [], 'Service Unavailable'),
            new Response(503, [], 'Service Unavailable'),
        ]);

        $this->expectException(GagalMengunduh::class);

        try {
            $pengunduh->unduh(self::URL);
        } finally {
            $this->assertCount(0, $mock, 'Kedua percobaan harus terpakai, tidak lebih.');
        }
    }

    /**
     * 403 adalah jawaban pasti, bukan keadaan sesaat.
     *
     * Antreannya sengaja menyediakan jawaban kedua yang berhasil. Kalau
     * pengunduh salah menganggap 403 layak diulang, tesnya lulus tanpa
     * peringatan apa pun, jadi yang diperiksa adalah jawaban kedua itu tidak
     * pernah tersentuh.
     */
    public function test_ditolak_403_tidak_dicoba_ulang(): void
    {
        [$pengunduh, $mock] = $this->pengunduh([
            new Response(403, [], 'Forbidden'),
            new Response(200, [], '<rss>isi</rss>'),
        ]);

        try {
            $pengunduh->unduh(self::URL);
            $this->fail('Seharusnya melempar GagalMengunduh.');
        } catch (GagalMengunduh $e) {
            $this->assertSame(403, $e->status);
            $this->assertCount(1, $mock, 'Jawaban kedua tidak boleh tersentuh untuk 403.');
        }
    }

    public function test_tidak_ditemukan_404_tidak_dicoba_ulang(): void
    {
        [$pengunduh, $mock] = $this->pengunduh([
            new Response(404, [], 'Not Found'),
            new Response(200, [], '<rss>isi</rss>'),
        ]);

        try {
            $pengunduh->unduh(self::URL);
            $this->fail('Seharusnya melempar GagalMengunduh.');
        } catch (GagalMengunduh $e) {
            $this->assertSame(404, $e->status);
            $this->assertCount(1, $mock, 'Jawaban kedua tidak boleh tersentuh untuk 404.');
        }
    }
}
