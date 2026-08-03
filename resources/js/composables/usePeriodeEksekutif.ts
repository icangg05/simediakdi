import { router } from '@inertiajs/vue3';

/**
 * Rentang tanggal dan konteks dibawa antar halaman eksekutif.
 *
 * Kalau tidak dibawa, pengguna yang sudah memilih 90 hari lalu membuka halaman
 * isu akan kembali ke bawaan tujuh hari tanpa penjelasan.
 */
export function usePeriodeEksekutif(
    periode: { dari: string; sampai: string },
    konteksId: number | null,
    urlBasis: string,
) {
    function pindah(parameter: Record<string, string | number | null>) {
        router.get(
            urlBasis,
            { ...periode, konteks: konteksId, ...parameter },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    /** Query string untuk tautan ke halaman eksekutif lain. */
    function kueri(tambahan: Record<string, string | number | null> = {}): string {
        const params = new URLSearchParams();

        Object.entries({ ...periode, konteks: konteksId, ...tambahan }).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') params.set(k, String(v));
        });

        return params.toString();
    }

    return { pindah, kueri };
}
