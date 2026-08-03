<?php

namespace App\Jobs;

use App\Models\AnalisisSentimen as BarisAnalisis;
use App\Models\Artikel;
use App\Models\KonteksPantauan;
use App\Services\Nlp\PenyaringKataKunci;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * F-10: menilai relevansi artikel sebelum menilai sentimen.
 *
 * Urutan ini bukan selera. Model sentimen tetap mengeluarkan label untuk
 * artikel yang tidak relevan, label itu masuk agregasi, lalu grafik dashboard
 * terisi angka yang tidak ada hubungannya dengan Pemkot Kendari. Saringan
 * relevansi membuangnya lebih dulu.
 *
 * Job ini tidak memanggil layanan NLP sama sekali. Skornya cosine similarity
 * antara vektor artikel dan vektor deskripsi konteks, keduanya sudah tersimpan
 * sejak HitungEmbedding, dan perbandingannya dikerjakan PostgreSQL.
 *
 * Keuntungannya bukan kecepatan per artikel melainkan penyetelan: mengubah
 * ambang cukup menjalankan ulang kueri atas seluruh korpus, tanpa satu pun
 * inferensi model. Ambang yang harus dicoba puluhan kali hanya akan
 * benar-benar disetel kalau mencobanya murah. Dokumen 05 bagian 2.
 */
class AnalisisRelevansi implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $artikelId)
    {
        $this->onQueue('nlp');
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(PenyaringKataKunci $penyaring): void
    {
        $artikel = Artikel::withoutGlobalScopes()->find($this->artikelId);

        if ($artikel === null || $artikel->isi === null) {
            return;
        }

        $skor = $this->skor($artikel);
        $adaYangRelevan = false;
        $adaYangRagu = false;

        foreach (KonteksPantauan::query()->aktif()->get() as $konteks) {
            [$relevan, $ragu] = $this->putuskan(
                $skor[$konteks->id] ?? null,
                $artikel,
                $konteks,
                $penyaring,
            );

            BarisAnalisis::updateOrCreate(
                ['artikel_id' => $artikel->id, 'konteks_pantauan_id' => $konteks->id],
                [
                    // Konteks yang tidak lolos tetap dicatat sebagai tidak
                    // relevan, bukan dihilangkan. Barisnya ada supaya terlihat
                    // konteks itu memang sudah dinilai.
                    'relevan' => $relevan,
                    'skor_relevansi' => $skor[$konteks->id] ?? null,
                ],
            );

            $adaYangRelevan = $adaYangRelevan || $relevan;
            $adaYangRagu = $adaYangRagu || $ragu;
        }

        // Artikel ragu berhenti di sini dan menunggu manusia. Meneruskannya ke
        // sentimen berarti menaruh angka yang sistem sendiri tidak yakini ke
        // dalam grafik yang dibaca pimpinan.
        if (! $adaYangRelevan && $adaYangRagu) {
            $artikel->update(['status_proses' => 'perlu_review']);

            return;
        }

        if (! $adaYangRelevan) {
            $artikel->update(['status_proses' => 'tidak_relevan']);

            return;
        }

        $artikel->update(['status_proses' => 'dianalisis']);

        AnalisisSentimen::dispatch($artikel->id);
    }

    /**
     * Tiga jalur dari satu angka.
     *
     * @return array{0: bool, 1: bool} relevan, dan perlu review
     */
    private function putuskan(
        ?float $skor,
        Artikel $artikel,
        KonteksPantauan $konteks,
        PenyaringKataKunci $penyaring,
    ): array {
        $atas = config('nlp.ambang.relevansi_atas');
        $bawah = config('nlp.ambang.relevansi_bawah');

        // Ambang belum diukur, atau vektornya belum ada. Tidak ada yang
        // otomatis masuk dan tidak ada yang otomatis dibuang: semuanya
        // diputuskan manusia sampai ambangnya ditetapkan dari validation set.
        if ($skor === null || $atas === null || $bawah === null) {
            return [false, true];
        }

        if ($skor < $bawah) {
            return [false, false];
        }

        if ($skor < $atas) {
            return [false, true];
        }

        // Pengetat sesudah ambang, bukan sebelum. Teks yang dinilai disusun
        // dari sekitar sebutan Pemkot, jadi artikel yang menyebutnya sekali
        // sepintas pun bisa berskor tinggi. Alasan lengkapnya beserta
        // angkanya ada di PenyaringKataKunci::menonjol().
        return [$penyaring->menonjol($artikel->judul, $artikel->isi, $konteks), false];
    }

    /**
     * Skor kemiripan artikel terhadap tiap konteks aktif, dikunci konteks_id.
     *
     * Dihitung PostgreSQL dalam satu kueri, bukan di PHP. Operator `<=>`
     * pgvector mengembalikan jarak cosine, jadi kemiripannya 1 dikurangi itu.
     *
     * @return array<int, float>
     */
    private function skor(Artikel $artikel): array
    {
        if ($artikel->embedding_relevansi === null) {
            return [];
        }

        $baris = DB::select(
            'SELECT k.id, 1 - (a.embedding_relevansi <=> k.embedding) AS skor
             FROM artikel a
             JOIN konteks_pantauan k ON k.embedding IS NOT NULL AND k.aktif
             WHERE a.id = ? AND a.embedding_relevansi IS NOT NULL',
            [$artikel->id],
        );

        return array_column($baris, 'skor', 'id');
    }
}
