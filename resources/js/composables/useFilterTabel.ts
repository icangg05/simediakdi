import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ArahUrut } from '@/types/tabel';

/**
 * Seluruh filter, sort, dan paginasi dikerjakan server.
 *
 * State disimpan di query string, bukan di klien. Alasannya bukan performa
 * saja: admin bisa membookmark hasil filternya, dan laporan bug bisa
 * direproduksi dari satu tautan.
 */
export function useFilterTabel(urlBasis: string) {
    const kueri = computed<Record<string, string>>(() => {
        const posisi = window.location.search;
        return Object.fromEntries(new URLSearchParams(posisi));
    });

    function kunjungi(perubahan: Record<string, string | number | null>) {
        const params: Record<string, string> = { ...kueri.value };

        for (const [kunci, nilai] of Object.entries(perubahan)) {
            if (nilai === null || nilai === '') {
                delete params[kunci];
            } else {
                params[kunci] = String(nilai);
            }
        }

        // Setiap perubahan filter mengembalikan pengguna ke halaman pertama;
        // tanpa ini filter yang menyempit meninggalkan tabel kosong di
        // halaman 7 dan terlihat seperti bug.
        if (!('halaman' in perubahan)) {
            delete params.halaman;
        }

        router.get(urlBasis, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    const urut = computed(() => kueri.value.urut ?? null);
    const arah = computed<ArahUrut>(() => (kueri.value.arah === 'desc' ? 'desc' : 'asc'));
    const cari = computed(() => kueri.value.cari ?? '');

    /** Klik pertama pada kolom = asc, klik berikutnya membalik arah. */
    function urutkan(kunci: string) {
        const arahBaru: ArahUrut = urut.value === kunci && arah.value === 'asc' ? 'desc' : 'asc';
        kunjungi({ urut: kunci, arah: arahBaru });
    }

    function cariDengan(kata: string) {
        kunjungi({ cari: kata || null });
    }

    function saring(kunci: string, nilai: string[]) {
        kunjungi({ [kunci]: nilai.length ? nilai.join(',') : null });
    }

    function nilaiFilter(kunci: string): string[] {
        const nilai = kueri.value[kunci];
        return nilai ? nilai.split(',') : [];
    }

    function keHalaman(halaman: number) {
        kunjungi({ halaman });
    }

    function bersihkan() {
        router.get(urlBasis, {}, { preserveScroll: true, replace: true });
    }

    const adaFilterAktif = computed(() =>
        Object.keys(kueri.value).some((k) => k !== 'halaman' && kueri.value[k] !== ''),
    );

    return { kueri, urut, arah, cari, urutkan, cariDengan, saring, nilaiFilter, keHalaman, bersihkan, adaFilterAktif };
}
