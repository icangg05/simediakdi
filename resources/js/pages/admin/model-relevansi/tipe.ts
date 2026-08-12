/**
 * Bentuk data halaman Model Relevansi.
 *
 * Ditaruh terpisah karena tiga tab membaca prop yang sama dan Index.vue hanya
 * meneruskannya. Menyalin interface-nya ke tiap tab berarti tiga tempat yang
 * harus disunting setiap kali satu kolom bertambah di controller, dan yang
 * ketinggalan tidak menimbulkan galat, hanya prop bertipe salah diam-diam.
 */

import { Archive, CircleCheck, CircleSlash, CircleX, Hourglass, Layers, Loader2, ThumbsDown, ThumbsUp } from 'lucide-vue-next';
import type { Component } from 'vue';

export type StatusPelatihan = 'menunggu' | 'berjalan' | 'berhasil' | 'gagal' | 'dibatalkan';

export type Label = 'relevan' | 'tidak_relevan';

export interface Kandidat {
    total: number;
    relevan: number;
    tidak_relevan: number;
    persen_relevan: number;
    persen_tidak_relevan: number;
    min_panjang_isi: number;
}

export interface Snapshot {
    id: number;
    nama: string;
    deskripsi: string | null;
    status: 'siap' | 'terpakai' | 'arsip';
    random_seed: number;
    total: number;
    total_relevan: number;
    total_tidak_relevan: number;
    total_train: number;
    total_validation: number;
    total_test: number;
    persen_relevan: number;
    persen_tidak_relevan: number;
    persen_train: number;
    persen_validation: number;
    persen_test: number;
    pelatihan_count: number;
    pembuat: string | null;
    dibuat_at: string | null;
}

export interface Konfigurasi {
    base_model: string;
    epoch: number;
    batch_size: number;
    learning_rate: number;
    max_seq_length: number;
    seed: number;
    early_stopping: number | null;
}

export interface Epoch {
    epoch: number;
    train_loss: number;
    val_loss: number;
    val_accuracy: number;
    val_f1: number;
    // Opsional karena pelatihan yang sudah tersimpan sebelum kolom ini ada
    // tidak punya angkanya.
    val_pred_relevan?: number;
}

export interface Metrik {
    accuracy: number;
    precision: number;
    recall: number;
    f1: number;
    jumlah_fitur: number;
    jumlah_test: number;
    epoch_terbaik: number;
    epoch_dijalankan: number;
    val_loss_terbaik: number;
}

export interface SkorKelas {
    precision: number;
    recall: number;
    f1: number;
    support: number;
}

export interface Pelatihan {
    id: number;
    nama: string;
    status: StatusPelatihan;
    tahap: string | null;
    progres: number;
    epoch_berjalan: number | null;
    estimasi_sisa_detik: number | null;
    base_model: string;
    konfigurasi: Konfigurasi;
    metrik: Metrik | null;
    riwayat_epoch: Epoch[] | null;
    confusion_matrix: Record<Label, Record<Label, number>> | null;
    laporan_klasifikasi: Record<string, SkorKelas> | null;
    perangkat: string | null;
    galat: string | null;
    aktif: boolean;
    batal_diminta: boolean;
    artefak_path: string | null;
    snapshot: {
        id: number;
        nama: string;
        total: number;
        total_train: number;
        total_validation: number;
        total_test: number;
    } | null;
    pembuat: string | null;
    mulai_at: string | null;
    selesai_at: string | null;
    dibuat_at: string | null;
    durasi_detik: number | null;
}

export interface RiwayatUji {
    id: number;
    potongan: string;
    label_prediksi: Label;
    confidence: number;
    probabilitas_relevan: number;
    inferensi_ms: number;
    model: string | null;
    base_model: string | null;
    penguji: string | null;
    diuji_at: string | null;
}

export interface Batas {
    min: number;
    maks: number;
    bawaan: number;
}

/** Keadaan layanan model. null berarti container `relevansi` tidak menjawab. */
export interface Layanan {
    status: string;
    perangkat: string;
    torch: string;
    transformers: string;
    sedang_melatih: boolean;
}

export interface Opsi {
    base_model: Record<string, string>;
    batas: Record<'epoch' | 'batch_size' | 'learning_rate' | 'max_seq_length' | 'early_stopping', Batas>;
}

/*
 * Sistem warna halaman ini, disamakan dengan halaman Berita dan Antrean AI.
 *
 * Sebelumnya seluruh berkas di folder ini memakai palet Tailwind mentah, dan
 * akibatnya sama persis dengan yang dulu terjadi di halaman Antrean AI: satu
 * rona memikul dua arti yang berbeda, dan keduanya berdiri berdampingan di satu
 * layar. Hijau berarti "artikel relevan" sekaligus "pelatihan berhasil", jadi
 * pada kartu riwayat pelatihan berdiri lencana hijau "Model aktif" tepat di
 * atas batang distribusi hijau yang artinya label Relevan. Merah berarti
 * "artikel tidak relevan" sekaligus "pelatihan gagal", padahal aturan yang
 * sudah ditetapkan halaman Berita berbunyi tegas: berita di luar cakupan Pemkot
 * bukan kabar buruk dan tidak boleh diwarnai merah.
 *
 * Pembagiannya sekarang:
 *
 * | Rona            | Arti di halaman ini                        |
 * |-----------------|--------------------------------------------|
 * | Navy merek      | Aksi utama, dan pekerjaan yang tuntas      |
 * | Aksen toska     | Label Relevan, masuk lingkup pantauan      |
 * | Abu redup       | Label Tidak Relevan, di luar lingkup       |
 * | Aksen ungu      | Model sedang bekerja                       |
 * | Aksen biru      | Snapshot dan berkas dataset                |
 * | Hijau sentimen  | Berhasil, model aktif, layanan sehat       |
 * | Merah sentimen  | Gagal, dan galat                           |
 * | Kuning sentimen | Menunggu, dan hasil yang perlu ditinjau    |
 *
 * Warna tidak pernah menjadi penanda tunggal. Tiap status membawa ikonnya
 * sendiri di `IKON_STATUS`, dan tiap label membawa ikonnya di `IKON_LABEL`.
 */

/** Warna lencana status pelatihan. Satu sumber, dipakai dua tab. */
export const WARNA_STATUS: Record<StatusPelatihan, string> = {
    menunggu: 'bg-sentimen-review-lembut text-sentimen-review',
    berjalan: 'bg-aksen-ungu/10 text-aksen-ungu',
    berhasil: 'bg-sentimen-positif-lembut text-sentimen-positif',
    gagal: 'bg-sentimen-negatif-lembut text-sentimen-negatif',
    dibatalkan: 'bg-muted text-muted-foreground',
};

export const IKON_STATUS: Record<StatusPelatihan, Component> = {
    menunggu: Hourglass,
    berjalan: Loader2,
    berhasil: CircleCheck,
    gagal: CircleX,
    dibatalkan: CircleSlash,
};

export const LABEL_STATUS: Record<StatusPelatihan, string> = {
    menunggu: 'Menunggu',
    berjalan: 'Berjalan',
    berhasil: 'Berhasil',
    gagal: 'Gagal',
    dibatalkan: 'Dibatalkan',
};

/**
 * Status snapshot. Biru berarti berkas dataset, dan snapshot yang sudah pernah
 * dilatihkan diberi rona yang sama dengan pekerjaannya, bukan rona lain.
 */
export const WARNA_SNAPSHOT: Record<string, string> = {
    siap: 'bg-aksen-biru/10 text-aksen-biru',
    terpakai: 'bg-brand-lembut text-brand dark:text-white',
    arsip: 'bg-muted text-muted-foreground',
};

export const IKON_SNAPSHOT: Record<string, Component> = {
    siap: CircleCheck,
    terpakai: Layers,
    arsip: Archive,
};

/**
 * Warna label relevansi, satu sumber untuk seluruh folder ini.
 *
 * Toska untuk Relevan dan abu untuk Tidak Relevan, sama persis dengan halaman
 * Berita dan Antrean AI, sehingga satu warna berarti satu hal di seluruh panel
 * admin. Sebelumnya nilai ini disalin ke TabPengujian dan TabSnapshot dengan
 * palet hijau dan merah yang berbeda dari keduanya.
 */
export const WARNA_LABEL: Record<Label, string> = {
    relevan: 'bg-aksen-toska/10 text-aksen-toska',
    tidak_relevan: 'bg-muted text-muted-foreground',
};

/** Bidang berisian penuh, untuk batang distribusi dan tile berikon. */
export const ISIAN_LABEL: Record<Label, string> = {
    relevan: 'bg-aksen-toska',
    tidak_relevan: 'bg-muted-foreground/40',
};

export const IKON_LABEL: Record<Label, Component> = {
    relevan: ThumbsUp,
    tidak_relevan: ThumbsDown,
};

export const SEBUTAN_LABEL: Record<Label, string> = {
    relevan: 'Relevan',
    tidak_relevan: 'Tidak Relevan',
};

/** Durasi dalam detik menjadi kalimat pendek. */
export function formatDurasi(detik: number | null): string {
    if (detik === null) return '-';
    if (detik < 60) return `${detik} detik`;

    const menit = Math.floor(detik / 60);

    if (menit < 60) return `${menit} menit ${detik % 60} detik`;

    return `${Math.floor(menit / 60)} jam ${menit % 60} menit`;
}
