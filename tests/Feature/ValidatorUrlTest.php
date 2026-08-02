<?php

namespace Tests\Feature;

use App\Services\Crawler\ValidatorUrl;
use Tests\TestCase;

/**
 * Admin bisa memasukkan URL sumber feed apa pun. Di server cloud, satu URL
 * ke 169.254.169.254 berujung pada kebocoran kredensial metadata.
 */
class ValidatorUrlTest extends TestCase
{
    private ValidatorUrl $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new ValidatorUrl;
    }

    public function test_menolak_alamat_internal_dan_skema_terlarang(): void
    {
        $terlarang = [
            'http://127.0.0.1/feed' => 'loopback',
            'http://localhost/feed' => 'localhost',
            'http://169.254.169.254/latest/meta-data/' => 'metadata cloud',
            'http://10.1.2.3/feed' => 'privat kelas A',
            'http://172.16.0.5/feed' => 'privat kelas B',
            'http://192.168.1.1/feed' => 'privat kelas C',
            'http://[::1]/feed' => 'loopback IPv6',
            'file:///etc/passwd' => 'skema file',
            'gopher://contoh.id/' => 'skema gopher',
            'bukan-url-sama-sekali' => 'teks bukan URL',
        ];

        foreach ($terlarang as $url => $alasan) {
            $this->assertFalse(
                $this->validator->aman($url),
                "URL {$url} ({$alasan}) seharusnya ditolak.",
            );
        }
    }

    public function test_menerima_url_publik_biasa(): void
    {
        $this->assertTrue($this->validator->aman('https://example.com/feed'));
    }

    public function test_pesan_penolakan_menyebut_penyebabnya(): void
    {
        try {
            $this->validator->pastikanAman('http://127.0.0.1/feed');
            $this->fail('Seharusnya melempar UrlDitolak.');
        } catch (\App\Services\Crawler\UrlDitolak $e) {
            $this->assertStringContainsString('internal', $e->getMessage());
        }
    }
}
