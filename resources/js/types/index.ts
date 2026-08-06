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
