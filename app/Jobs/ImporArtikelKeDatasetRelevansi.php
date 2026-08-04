<?php

namespace App\Jobs;

use App\Enums\StatusDedup;
use App\Models\Artikel;
use App\Models\SampelRelevansi;
use App\Services\Relevance\SkorPrioritasPelabelan;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Artikel hasil crawler masuk sebagai kandidat dataset relevansi.
 *
 * Ditaruh sebelum penilaian, bukan sesudah. Artikel yang belum pernah dinilai
 * model apa pun justru sampel yang paling dibutuhkan pelabel, dan kalau impor
 * menunggu hasil prediksi, tidak akan ada satu pun kandidat yang masuk selama
 * belum ada model. Itu persis keadaan sekarang.
 *
 * Salinan tidak diimpor. Salinan ke-10 dari rilis yang sama tidak menambah
 * informasi apa pun bagi model, dan yang lebih berbahaya, ia bisa jatuh di
 * split yang berbeda dari induknya lalu membuat angka evaluasi bohong ke atas.
 *
 * Dokumen 10 bagian 7.1, dokumen 02 bagian 5 langkah 5.
 */
class ImporArtikelKeDatasetRelevansi implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $artikelId)
    {
        // Antrean `default`, bukan `nlp`. Tidak ada model yang dipanggil di
        // sini, dan menaruhnya di antrean satu proses berarti impor ikut
        // tertahan setiap kali layanan NLP mati.
        $this->onQueue('default');
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(SkorPrioritasPelabelan $prioritas): void
    {
        $artikel = Artikel::withoutGlobalScopes()->with('media:id,nama')->find($this->artikelId);

        if ($artikel === null || $artikel->isi === null) {
            return;
        }

        if ($artikel->status_dedup === StatusDedup::Salinan) {
            return;
        }

        $skor = $prioritas->hitung($artikel);
        $sampel = SampelRelevansi::firstOrNew(['artikel_id' => $artikel->id]);

        // Sampel yang sudah ada hanya diperbarui urutan antreannya. Teksnya
        // tidak pernah disentuh lagi, dan itu bukan penghematan kueri: media
        // menyunting artikel setelah terbit, dan menyalin ulang judul atau isi
        // berarti pelabel menilai satu teks sementara model dilatih dengan teks
        // lain. Backfill yang dijalankan dua kali juga tidak boleh menghapus
        // keputusan manusia yang sudah ada di antaranya.
        if ($sampel->exists) {
            $sampel->update([
                'priority_score' => $skor['total'],
                'priority_reasons' => $skor['komponen'],
            ]);

            return;
        }

        $sampel->fill([
            'sumber_dataset' => 'crawler',
            'judul' => $artikel->judul,
            'excerpt' => $artikel->ringkasan,
            'isi' => $artikel->isi,
            'url' => $artikel->url,
            'media_id' => $artikel->media_id,
            'tanggal_publikasi' => $artikel->dipublikasikan_at,
            // `kategori_sumber` dan `tag_sumber` dibiarkan kosong: kolomnya
            // belum ada di tabel artikel dan crawler belum memanennya. Sprint 6
            // fase 2 yang mengerjakannya, dan sampai itu selesai, mengarang
            // nilainya di sini hanya membuat kolom terisi tanpa isi.
            'duplicate_group_id' => $artikel->artikel_induk_id ?? $artikel->id,
            'priority_score' => $skor['total'],
            'priority_reasons' => $skor['komponen'],
        ])->save();
    }
}
