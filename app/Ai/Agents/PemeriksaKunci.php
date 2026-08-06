<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Ketukan pintu ke Gemini, untuk memastikan sebuah kunci masih berfungsi.
 *
 * Tanpa keluaran terstruktur, berbeda dari dua agen penilai. Yang diuji di sini
 * bukan kemampuan modelnya melainkan kuncinya, dan skema JSON hanya menambah
 * satu cara lagi untuk gagal yang tidak ada hubungannya dengan kunci: model
 * yang menjawab dengan bentuk keliru akan terbaca sebagai kunci rusak.
 *
 * Batas token sengaja sangat kecil. Satu uji adalah satu permintaan yang
 * dihitung penuh oleh Google, jadi jawabannya cukup pendek asal membuktikan
 * paket benar-benar sampai dan kembali.
 *
 * Perintahnya menyuruh model menuliskan ulang label kunci yang sedang diuji.
 * Karena itu instruksi di sini tidak boleh menuntut kalimat karangan sendiri:
 * yang dicari justru salinan persis, supaya jawaban bisa dicocokkan dengan
 * kunci yang mengirimnya.
 */
#[Provider(Lab::Gemini)]
#[MaxTokens(60)]
final class PemeriksaKunci implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Ikuti perintah persis seperti yang tertulis. Jangan menambahkan sapaan, '
            .'penjelasan, tanda kutip, maupun format markdown apa pun.';
    }
}
