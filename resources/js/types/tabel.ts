export type ArahUrut = 'asc' | 'desc';

export interface KolomDefinisi {
    /** Nama kolom di data sekaligus nilai parameter `urut` yang dikirim ke server. */
    kunci: string;
    judul: string;
    bisaDiurutkan?: boolean;
    /** Kelas tambahan untuk sel, misalnya `angka text-right`. */
    kelas?: string;
    /** Lebar kolom, misalnya `w-40`. */
    lebar?: string;
}

export interface OpsiFilter {
    nilai: string;
    label: string;
}

export interface FilterDefinisi {
    kunci: string;
    label: string;
    opsi: OpsiFilter[];
}

/** Bentuk yang dikembalikan LengthAwarePaginator Laravel. */
export interface PaginasiMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface AksiBaris<T = Record<string, unknown>> {
    label: string;
    /** Tautan Inertia. Isi salah satu dari href atau onKlik. */
    href?: (baris: T) => string;
    onKlik?: (baris: T) => void;
    merusak?: boolean;
}
