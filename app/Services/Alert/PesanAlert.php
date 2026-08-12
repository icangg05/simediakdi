<?php

namespace App\Services\Alert;

use App\Models\AturanAlert;
use App\Support\Waktu;
use Carbon\CarbonImmutable;

/**
 * Satu-satunya tempat teks pesan Telegram disusun.
 *
 * Alert sungguhan dan pesan uji memakai penyusun yang sama, dan itu bukan
 * penghematan baris. Tombol Kirim uji ada untuk menjawab satu pertanyaan:
 * seperti apa alert nanti terlihat di layar ponsel. Kalau pesan ujinya disusun
 * di tempat lain, ia akan menjawab pertanyaan itu dengan bentuk yang bukan
 * bentuk sebenarnya, dan perbedaannya baru ketahuan saat alert pertama benar
 * benar menyala.
 *
 * Bedanya hanya sampul. Pesan uji dibungkus penanda di kepala dan kaki supaya
 * tidak ada yang membacanya sepintas lalu mengira Pemkot sedang diberitakan
 * buruk. Isinya tetap disusun `alert()` yang sama persis.
 *
 * Seluruh isi yang datang dari luar diloloskan lewat `e()`. Judul berita
 * ditulis orang lain dan bisa memuat & atau <, dan satu tanda saja membuat
 * Telegram menolak seluruh pesan karena parse_mode-nya HTML.
 */
class PesanAlert
{
    /**
     * Ikon dibedakan per jenis aturan, bukan satu lonceng untuk semuanya.
     *
     * Tiga jenis alert menuntut tindakan yang berbeda jauh. Lonjakan berita
     * negatif adalah urusan humas, feed yang mati adalah urusan teknis, dan
     * yang membaca notifikasi di layar kunci perlu tahu bedanya sebelum
     * membuka aplikasinya.
     */
    private const IKON = [
        // Satu berita buruk yang baru masuk adalah yang paling genting, jadi
        // ia yang memakai sirene. Lonjakan memakai grafik naik: ia laporan
        // tentang kecenderungan, bukan tentang satu peristiwa yang harus
        // ditangani pada menit itu juga.
        'berita_negatif' => '🚨',
        'lonjakan_negatif' => '📈',
        'kata_kunci_muncul' => '🔎',
        'sumber_mati' => '📡',
    ];

    /**
     * @param  array<int, array<string, mixed>|object>  $sorotan  berita contoh, boleh kosong
     */
    public static function alert(AturanAlert $aturan, string $ringkasan, array $sorotan = []): string
    {
        $ikon = self::IKON[$aturan->jenis] ?? '🔔';

        $baris = [
            $ikon.' <b>'.e($aturan->nama).'</b>',
            '',
            e($ringkasan),
        ];

        // Tiga, bukan seluruhnya. Alert yang panjang dilipat Telegram menjadi
        // "Read more" dan bagian yang paling penting justru ada di atas.
        foreach (array_slice($sorotan, 0, 3) as $satu) {
            $baris[] = '';
            $baris[] = self::sorotan((array) $satu);
        }

        return implode("\n", $baris);
    }

    /**
     * Satu berita, dikirim sendiri, beserta ringkasan alasan dari Gemini.
     *
     * Urutannya sengaja terbalik dari `alert()`: judulnya lebih dulu, baru
     * alasannya. Alert berkala menjawab "seberapa banyak" dan angkanya harus di
     * atas, sedangkan alert satu berita menjawab "berita apa", jadi yang perlu
     * terbaca di pratinjau notifikasi adalah judulnya.
     *
     * Ringkasan Gemini boleh kosong. Artikel yang dinilai sebelum kolom itu ada,
     * atau yang jawaban modelnya tidak memuat alasan, tetap dikirim tanpa
     * paragraf itu. Menahan pengirimannya berarti menahan kabar buruk hanya
     * karena keterangannya tidak lengkap.
     *
     * @param  array<string, mixed>  $berita
     */
    public static function berita(AturanAlert $aturan, array $berita, ?string $ringkasan = null): string
    {
        $ikon = self::IKON[$aturan->jenis] ?? '🔔';

        return implode("\n", array_filter([
            $ikon.' <b>'.e($aturan->nama).'</b>',
            '',
            self::sorotan($berita),
            ($ringkasan ?? '') === '' ? null : "\n".e($ringkasan),
        ], fn ($baris) => $baris !== null));
    }

    /**
     * Penanda uji coba, satu baris di kepala pesan dan tidak lebih.
     *
     * Di kepala karena di situlah pratinjau notifikasi memotong: penanda di
     * kaki baru terbaca setelah pesannya dibuka, dan yang perlu dicegah justru
     * kepanikan pada detik pertama.
     *
     * Isinya tidak disentuh sama sekali. Tombol ini ada untuk memperlihatkan
     * teks alert yang sebenarnya, dan setiap kata yang ditambahkan ke dalamnya
     * membuat yang terlihat di layar bukan lagi yang akan terkirim nanti.
     */
    public static function uji(string $pesan): string
    {
        return "🧪 <b>UJI COBA</b>\n\n".$pesan;
    }

    /** @param  array<string, mixed>  $item */
    private static function sorotan(array $item): string
    {
        $judul = e((string) ($item['judul'] ?? 'Tanpa judul'));
        $url = (string) ($item['url'] ?? '');

        // Tautan ditempel pada judulnya, bukan ditulis telanjang di baris
        // sendiri. URL panjang media daerah memenuhi tiga baris di layar
        // ponsel dan mendorong isi pesannya keluar dari pratinjau notifikasi.
        $tautan = $url === '' ? $judul : '<a href="'.e($url).'">'.$judul.'</a>';

        $jejak = array_filter([
            isset($item['media']) ? e((string) $item['media']) : null,
            isset($item['diambil_at']) ? self::waktu($item['diambil_at']) : null,
        ]);

        return '📰 '.$tautan.($jejak === [] ? '' : "\n".implode(' · ', $jejak));
    }

    private static function waktu(mixed $waktu): string
    {
        return CarbonImmutable::parse($waktu)->setTimezone(Waktu::ZONA)->format('d/m/Y H:i');
    }
}
