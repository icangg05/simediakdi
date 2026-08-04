<?php

namespace App\Services\Relevance;

use App\Enums\StatusGerbangMutu;
use App\Models\VersiModelRelevansi;

/**
 * Penjaga tunggal yang menentukan boleh tidaknya analisis sentimen berjalan.
 *
 * Sentimen yang akurat atas artikel yang salah tetap salah, dan itu kesalahan
 * yang tidak perlu dibaca angkanya untuk disadari pimpinan, cukup dibaca
 * judulnya. Karena itu gerbang ini bukan peringatan melainkan penghenti.
 *
 * Keadaan awal sejak 4 Agustus 2026: tidak ada model relevansi produksi sama
 * sekali, jadi statusnya `blocked` dan seluruh artikel baru tertahan. Itu
 * disengaja, bukan galat. Dokumen 10 bagian 0.3 dan 12.
 *
 * Sengaja bukan singleton. Worker antrean hidup berjam-jam, dan status yang
 * di-cache seumur proses berarti model yang baru dipromosikan tidak berlaku
 * sampai worker dimulai ulang, atau lebih buruk, gerbang yang baru dicabut
 * tetap meloloskan artikel.
 */
class RelevanceQualityGateService
{
    private ?VersiModelRelevansi $produksi = null;

    private bool $sudahDicari = false;

    public function status(): StatusGerbangMutu
    {
        $model = $this->modelProduksi();

        if ($model === null) {
            return StatusGerbangMutu::Blocked;
        }

        return $model->quality_gate_status;
    }

    /**
     * Satu-satunya pertanyaan yang perlu ditanyakan pemanggil.
     *
     * Dua penjaga memakainya, di dispatcher dan di dalam job sentimen. Itu
     * bukan pengulangan yang bisa dihapus: dispatch yang tidak sengaja, entah
     * dari perintah artisan, dari koreksi manual, atau dari kode yang ditulis
     * enam bulan lagi, tidak akan melewati penjaga kedua.
     */
    public function lolos(): bool
    {
        return $this->status()->mengizinkanSentimen();
    }

    /**
     * Model yang sedang melayani produksi, kalau ada.
     *
     * Unique partial index di database menjamin tidak pernah ada dua, jadi
     * `first()` di sini bukan pengambilan sembarang dari beberapa kandidat.
     */
    public function modelProduksi(): ?VersiModelRelevansi
    {
        if (! $this->sudahDicari) {
            $this->produksi = VersiModelRelevansi::produksi()->first();
            $this->sudahDicari = true;
        }

        return $this->produksi;
    }

    /**
     * Alasan yang bisa dibaca manusia, untuk banner dan pesan galat.
     *
     * Menyebut keadaan dan langkah berikutnya, bukan istilah teknis. Admin yang
     * membaca "gate blocked" tidak tahu apa yang harus dikerjakannya.
     */
    public function alasan(): ?string
    {
        return match ($this->status()) {
            StatusGerbangMutu::Passed => null,
            StatusGerbangMutu::Blocked => $this->modelProduksi() === null
                ? 'Belum ada model relevansi yang dipromosikan ke produksi. Lanjutkan pelabelan dataset sampai jumlahnya cukup untuk pelatihan pertama.'
                : 'Model relevansi produksi belum memenuhi standar gerbang mutu.',
            StatusGerbangMutu::NeedsReview => 'Model relevansi produksi perlu dievaluasi ulang, biasanya karena definisi konteks atau standar gerbang berubah.',
            StatusGerbangMutu::Revoked => 'Gerbang mutu dicabut. Periksa laporan pencabutan di tab Versi Model sebelum menjalankan sentimen lagi.',
        };
    }
}
