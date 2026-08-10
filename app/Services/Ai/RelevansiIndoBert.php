<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Artikel;
use App\Models\PelatihanModelRelevansi;
use App\Services\Ai\DTO\HasilKlasifikasi;
use App\Services\ModelRelevansi\PrediktorRelevansi;
use RuntimeException;

/**
 * Penilaian relevansi oleh model IndoBERT yang sedang aktif.
 *
 * Mengembalikan HasilKlasifikasi yang sama persis dengan yang dikembalikan
 * GeminiClassificationService, dan itu inti seluruh kelas ini. KlasifikasiArtikel
 * memetakan hasil relevansi ke kolom database di satu tempat, dan pemetaan itu
 * tidak boleh bercabang per penyedia. Cabang di sana berarti dua penyedia
 * perlahan menulis kolom yang berbeda, dan bedanya baru terlihat sebagai angka
 * dashboard yang tidak bisa dijelaskan.
 *
 * Karena bentuknya sama, kolom `provider` terisi `indobert` dengan sendirinya.
 * Itulah penanda yang membedakan artikel hasil IndoBERT dari hasil Gemini di
 * halaman artikel, dan tidak ada kolom lain yang perlu ditambahkan untuk itu.
 */
class RelevansiIndoBert
{
    public function __construct(private PrediktorRelevansi $prediktor) {}

    public function relevansi(Artikel $artikel): HasilKlasifikasi
    {
        $model = $this->modelAktif();
        $hasil = $this->prediktor->jalankan($model, $this->teks($artikel));

        // Confidence dari layanan Python adalah jarak dari keraguan, bukan
        // probabilitas kelas yang menang: `abs(p - 0.5) * 2`. Nol berarti model
        // benar-benar tidak bisa memilih, satu berarti yakin penuh.
        $ragu = $hasil['confidence'] < (float) config('relevansi.ambang_ragu');

        return new HasilKlasifikasi(
            // Keraguan model tidak diterjemahkan menjadi keputusan. IndoBERT
            // hanya punya dua keluaran, dan tanpa gerbang ini setiap tebakan
            // 51 banding 49 membuang satu berita dari dashboard tanpa pernah
            // ada yang meninjaunya.
            label: $ragu ? 'perlu_review' : $hasil['label'],
            alasanKode: $ragu ? 'confidence_rendah' : 'model_relevansi',
            // ponytail: probabilitas ditulis ke dalam kalimat, bukan ke kolom
            // angka sendiri. Kolom `skor_relevansi` pernah ada dan sudah
            // dihapus migration 2026_08_05_120000, dan menghidupkannya kembali
            // untuk angka yang belum dipakai menyaring atau mengurutkan apa pun
            // hanya menambah kolom yang menunggu pemakainya. Tambahkan kolomnya
            // saat ada layar yang benar-benar mengurutkan menurut angka ini.
            alasanRingkas: $this->alasan($model, $hasil, $ragu),
            // Kosong, dan bukan sementara. IndoBERT mengeluarkan probabilitas,
            // ia tidak menunjuk kalimat mana di artikel yang membuatnya
            // memutuskan. Mengisinya dengan potongan teks pertama akan terbaca
            // sebagai bukti padahal bukan.
            bukti: [],
            perluReview: $ragu,
            penyedia: 'indobert',
            model: $model->nama,
            latensiMs: $hasil['inferensi_ms'],
            // Bukan prompt, tetapi menjawab pertanyaan yang sama: keputusan ini
            // lahir dari penilai yang mana. Id pelatihan dipakai, bukan nama,
            // karena nama bisa disunting sementara id tidak.
            versiPrompt: 'indobert.'.$model->id,
        );
    }

    /**
     * Model yang sedang aktif, atau galat yang menyebut sebabnya.
     *
     * Tidak ada jalan mundur ke Gemini di sini. Pengaturan menolak menyimpan
     * pilihan IndoBERT selama belum ada model aktif, jadi sampai di titik ini
     * modelnya memang seharusnya ada. Berpindah penyedia diam-diam saat ia
     * hilang akan membuat kuota Gemini terpakai sementara layar tetap berbunyi
     * IndoBERT, dan tidak ada yang tahu sampai tagihan kuotanya terlihat.
     */
    private function modelAktif(): PelatihanModelRelevansi
    {
        $model = PelatihanModelRelevansi::query()->where('aktif', true)->first();

        if ($model === null) {
            throw new RuntimeException(
                'Tidak ada model relevansi yang aktif. Aktifkan satu model di halaman '
                .'Model Relevansi, atau gunakan jalur Klasifikasi Gemini.'
            );
        }

        return $model;
    }

    /**
     * Teks yang dinilai, disusun persis seperti saat pelatihan.
     *
     * Judul lebih dulu, lalu isi, dipisah satu baris baru. Sama dengan
     * LatihModelRelevansi::bagian(). Kalau kedua susunan berbeda, model menilai
     * bentuk teks yang tidak pernah dilihatnya saat berlatih, dan akurasi yang
     * terukur di halaman evaluasi berhenti berlaku untuk artikel sungguhan.
     */
    private function teks(Artikel $artikel): string
    {
        return $artikel->judul."\n".$artikel->isi;
    }

    /** @param array{label: string, probabilitas_relevan: float, confidence: float} $hasil */
    private function alasan(PelatihanModelRelevansi $model, array $hasil, bool $ragu): string
    {
        $peluang = number_format($hasil['probabilitas_relevan'] * 100, 1, ',', '.');

        if ($ragu) {
            return "Model {$model->nama} tidak cukup yakin. Peluang relevan {$peluang} persen, "
                .'terlalu dekat dengan batas untuk diputuskan tanpa peninjauan.';
        }

        return "Model {$model->nama} menilai peluang relevan {$peluang} persen.";
    }
}
