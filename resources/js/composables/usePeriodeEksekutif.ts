import { router } from '@inertiajs/vue3';

export type PeriodeEksekutif = { dari: string; sampai: string };

/** URL menu yang mempertahankan rentang aktif saat berpindah halaman. */
export function hrefDenganPeriodeEksekutif(href: string, periode?: PeriodeEksekutif, bulan?: string): string {
    if (!href.startsWith('/eksekutif') || periode === undefined) return href;

    const parameter = new URLSearchParams();

    if (href === '/eksekutif/laporan') {
        parameter.set('bulan', bulan ?? periode.dari.slice(0, 7));
    } else {
        parameter.set('dari', periode.dari);
        parameter.set('sampai', periode.sampai);
    }

    return `${href}?${parameter.toString()}`;
}

/**
 * Rentang tanggal dibawa antar halaman eksekutif.
 *
 * Kalau tidak dibawa, pengguna yang sudah memilih tiga bulan lalu membuka arsip
 * berita akan kembali ke minggu kalender saat ini tanpa penjelasan.
 */
export function usePeriodeEksekutif(periode: PeriodeEksekutif, urlBasis: string) {
    function pindah(parameter: Record<string, string | number | null>) {
        router.get(urlBasis, { ...periode, ...parameter }, { preserveState: true, preserveScroll: true, replace: true });
    }

    /** Query string untuk tautan ke halaman eksekutif lain. */
    function kueri(tambahan: Record<string, string | number | null> = {}): string {
        const params = new URLSearchParams();

        Object.entries({ ...periode, ...tambahan }).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') params.set(k, String(v));
        });

        return params.toString();
    }

    return { pindah, kueri };
}
