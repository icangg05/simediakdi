import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    /** Pesan hasil aksi. Dirender terpusat di AppLayout sebagai toast. */
    flash?: {
        sukses?: string | null;
        galat?: string | null;
        nonce?: string | null;
        /** Tombol opsional di dalam toast, misalnya membuka data yang barusan berpindah. */
        tautan?: { url: string; label: string } | null;
        /**
         * Hasil relevansi, mewarnai border toast. Hijau relevan, merah tidak
         * relevan, kuning saat Gemini menolak memutuskan.
         */
        nada?: 'relevan' | 'tidak_relevan' | 'perlu_review' | null;
        /** Sentimen artikel relevan, ditampilkan sebagai kata berwarna di dalam toast. */
        sentimen?: 'positif' | 'netral' | 'negatif' | null;
        /** Keterangan yang tidak terbaca dari hasilnya sendiri, misalnya koreksi yang dicabut. */
        catatan?: string | null;
        /** Hasil uji satu kunci Gemini, dirender di halaman Pengaturan. */
        ujiKunci?: {
            id: number;
            label: string;
            berhasil: boolean;
            jawaban: string | null;
            galat: string | null;
            ms: number;
        } | null;
        /** Hasil satu pengujian model, dirender di tab Pengujian Model. */
        hasilUji?: {
            id: number;
            label: 'relevan' | 'tidak_relevan';
            probabilitas_relevan: number;
            probabilitas_tidak_relevan: number;
            confidence: number;
            inferensi_ms: number;
            model: string;
            base_model: string;
            diuji_at: string;
        } | null;
    };
    /**
     * Sentimen tidak tersedia selama GEMINI_API_KEY belum diisi.
     * Halaman mana pun yang menampilkan angka sentimen wajib memeriksanya.
     */
    sentimen?: { tersedia: boolean; alasan: string | null };
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export type PeranPengguna = 'superadmin' | 'walikota' | 'media';

/** Label sentimen efektif. Cerminan App\Enums\LabelSentimen. */
export type LabelSentimen = 'negatif' | 'netral' | 'positif';

/**
 * Satu sumber pengambilan milik sebuah media.
 *
 * Ditaruh di sini, bukan di komponennya, karena halaman detail media dan dialog
 * formulirnya harus sepakat soal bentuknya, dan `<script setup>` tidak boleh
 * mengekspor apa pun.
 */
export interface SumberFeedBaris {
    id: number;
    nama: string;
    tipe: 'rss' | 'scrape' | 'scrape_render';
    url: string;
    selector: { item?: string; judul?: string; tautan?: string } | null;
    kata_kunci: string | null;
    interval_menit: number;
    aktif: boolean;
    gagal_berturut: number;
    pesan_error_terakhir: string | null;
    berhasil_terakhir_at: string | null;
    dijalankan_terakhir_at: string | null;
}

/**
 * Satuan pengelompokan grafik tren, ditentukan server dari panjang rentang.
 * Cerminan RingkasanEksekutif::satuan().
 */
export type SatuanDeret = 'harian' | 'mingguan' | 'bulanan';

/** Deret grafik tren beserta satuannya. */
export interface DeretTren {
    satuan: SatuanDeret;
    baris: Array<Record<string, number | string>>;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    peran: PeranPengguna;
    media_id: number | null;
    jabatan: string | null;
    aktif: boolean;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;
