<?php

namespace App\Services\Arsip;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Satu-satunya tempat aplikasi memanggil layanan tangkapan layar.
 *
 * Mengembalikan null, bukan melempar, saat layanan mati atau halaman tidak
 * bisa dirender. Alasannya ada di ArsipkanBuktiPemuatan: bukti teks jauh lebih
 * penting daripada gambar, dan kegagalan gambar tidak boleh membuang bukti
 * teks yang sudah di tangan.
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
