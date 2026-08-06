<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Membuang sisa penilaian sentimen pada artikel yang dinyatakan tidak relevan.
 *
 * Sentimen hanya dinilai setelah relevansinya berbunyi relevan. Begitu
 * jawabannya berubah menjadi tidak relevan, seluruh kolom hasil sentimen
 * menjadi jawaban atas pertanyaan yang sudah dibatalkan.
 *
 * Jalur klasifikasi AI dulu tidak membuangnya, hanya jalur keputusan manusia
 * yang membuang. Akibatnya terbaca di layar: 327 baris tidak relevan menyimpan
 * `provider` bertuliskan gemini berdampingan dengan `model_versi` bertuliskan
 * indobert-sentiment-classifier dari pipeline lama yang sudah dihapus, lalu
 * halaman detail merangkai keduanya menjadi "Dinilai gemini:
 * indobert-sentiment-classifier-2.0.0". Ditemukan pada artikel 2055.
 *
 * Kebocorannya sudah ditutup di App\Services\Ai\KlasifikasiArtikel, dan daftar
 * kolom yang dikosongkan kini dibaca dari App\Models\AnalisisSentimen. Berkas
 * ini hanya membereskan baris yang terlanjur tertulis.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('analisis_sentimen')
            ->where('relevan', false)
            ->where(function ($kueri) {
                $kueri->whereNotNull('label_model')
                    ->orWhereNotNull('model_versi')
                    ->orWhereNotNull('dianalisis_at')
                    ->orWhere('perlu_review', true);
            })
            ->update([
                'label_model' => null,
                'model_versi' => null,
                'dianalisis_at' => null,
                'perlu_review' => false,
            ]);
    }

    /**
     * Tidak dibatalkan, dan itu disengaja.
     *
     * Yang dihapus adalah nilai yang memang tidak berlaku, bukan nilai yang
     * dipindahkan. Tidak ada tempat menyimpannya, dan mengembalikannya berarti
     * mengembalikan kesalahan yang persis ingin dibuang.
     */
    public function down(): void {}
};
