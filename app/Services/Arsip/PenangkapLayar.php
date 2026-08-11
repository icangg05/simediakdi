<?php

namespace App\Services\Arsip;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Satu-satunya tempat aplikasi memanggil layanan tangkapan layar.
 *
 * Mengembalikan null, bukan melempar, saat layanan mati atau halaman tidak
 * bisa dirender. Tangkapan layar selalu pelengkap, tidak pernah satu-satunya
 * hasil yang dibutuhkan pemanggilnya, jadi kegagalannya tidak boleh
 * menggagalkan pekerjaan yang sedang berjalan.
 */
class PenangkapLayar
{
    public function tangkap(string $url): ?string
    {
        try {
            $tanggapan = Http::baseUrl((string) config('arsip.base_url'))
                ->timeout((int) config('arsip.timeout'))
                ->asJson()
                ->post('/tangkap', ['url' => $url]);
        } catch (ConnectionException) {
            return null;
        }

        return $tanggapan->successful() ? $tanggapan->body() : null;
    }

    public function sehat(): bool
    {
        try {
            return Http::baseUrl((string) config('arsip.base_url'))
                ->timeout(5)->get('/health')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }
}
