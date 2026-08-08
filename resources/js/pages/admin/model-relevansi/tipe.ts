/**
 * Bentuk data halaman Model Relevansi.
 *
 * Ditaruh terpisah karena tiga tab membaca prop yang sama dan Index.vue hanya
 * meneruskannya. Menyalin interface-nya ke tiap tab berarti tiga tempat yang
 * harus disunting setiap kali satu kolom bertambah di controller, dan yang
 * ketinggalan tidak menimbulkan galat, hanya prop bertipe salah diam-diam.
 */

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

/** Warna badge status pelatihan. Satu sumber, dipakai dua tab. */
export const WARNA_STATUS: Record<StatusPelatihan, string> = {
    menunggu: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    berjalan: 'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200',
    berhasil: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
    gagal: 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200',
    dibatalkan: 'bg-neutral-200 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200',
};

export const LABEL_STATUS: Record<StatusPelatihan, string> = {
    menunggu: 'Menunggu',
    berjalan: 'Berjalan',
    berhasil: 'Berhasil',
    gagal: 'Gagal',
    dibatalkan: 'Dibatalkan',
};

/** Durasi dalam detik menjadi kalimat pendek. */
export function formatDurasi(detik: number | null): string {
    if (detik === null) return '-';
    if (detik < 60) return `${detik} detik`;

    const menit = Math.floor(detik / 60);

    if (menit < 60) return `${menit} menit ${detik % 60} detik`;

    return `${Math.floor(menit / 60)} jam ${menit % 60} menit`;
}
