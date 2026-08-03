/**
 * Format angka Indonesia: pemisah ribuan titik, desimal koma.
 * Dipakai di semua tempat, jangan panggil toLocaleString langsung.
 */
export function useFormatAngka() {
    const angka = new Intl.NumberFormat('id-ID');
    const desimalSatu = new Intl.NumberFormat('id-ID', {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    });
    const rupiah = new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    });

    return {
        formatAngka: (n: number | null | undefined) => (n == null ? '-' : angka.format(n)),

        /** Persentase selalu satu angka desimal. */
        formatPersen: (n: number | null | undefined) => (n == null ? '-' : `${desimalSatu.format(n)}%`),

        formatRupiah: (n: number | string | null | undefined) =>
            n == null ? '-' : rupiah.format(typeof n === 'string' ? Number(n) : n),

        /** Bagian dari total, aman saat total nol. */
        formatProporsi: (bagian: number, total: number) =>
            total === 0 ? '0,0%' : `${desimalSatu.format((bagian / total) * 100)}%`,
    };
}
