<?php

namespace App\Services\Alert;

use App\Models\PengaturanAlert;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Pengiriman pesan ke grup Telegram Diskominfo (F-38).
 *
 * Telegram satu-satunya kanal alert. Notifikasi WhatsApp (F-41) dihapus dari
 * lingkup versi 1, bukan ditunda: API resminya berbayar dan berbasis template
 * yang harus disetujui lebih dulu, dan itu tidak sebanding untuk satu jenis
 * pesan internal.
 *
 * Mengembalikan hasil, bukan melempar. Alert yang gagal terkirim harus tercatat
 * di riwayat beserta sebabnya, karena satu-satunya hal yang lebih buruk
 * daripada alert yang tidak terkirim adalah alert yang tidak terkirim tanpa
 * ada yang tahu.
 */
class PengirimTelegram
{
    /** @return array{terkirim: bool, error: ?string} */
    public function kirim(string $pesan, ?string $chatId = null): array
    {
        // Dibaca dari PengaturanAlert, bukan langsung dari config. Nilai yang
        // disetel admin lewat halaman Pengaturan mengalahkan `.env`, dan
        // urutan itu ditulis satu kali di modelnya supaya tidak ada pemanggil
        // yang menyalinnya lalu suatu hari berbeda.
        $pengaturan = PengaturanAlert::aktif();

        $token = $pengaturan->token();
        $tujuan = $chatId ?? $pengaturan->chatId();

        if ($token === '' || $tujuan === '') {
            return [
                'terkirim' => false,
                'error' => 'Token bot atau chat ID Telegram belum diisi. Setel di halaman Pengaturan sistem.',
            ];
        }

        try {
            $tanggapan = Http::timeout(15)
                ->asJson()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $tujuan,
                    'text' => $pesan,
                    'parse_mode' => 'HTML',
                    // Pratinjau tautan mengubah satu peringatan ringkas menjadi
                    // kartu besar berisi foto artikel, dan grup jadi sulit dibaca.
                    'disable_web_page_preview' => true,
                ]);
        } catch (ConnectionException $e) {
            return ['terkirim' => false, 'error' => $e->getMessage()];
        }

        if ($tanggapan->successful()) {
            return ['terkirim' => true, 'error' => null];
        }

        // Telegram menjelaskan penolakannya dengan cukup baik ("chat not found",
        // "bot was blocked"), jadi pesannya diteruskan apa adanya ke riwayat.
        return [
            'terkirim' => false,
            'error' => (string) ($tanggapan->json('description') ?? "HTTP {$tanggapan->status()}"),
        ];
    }
}
