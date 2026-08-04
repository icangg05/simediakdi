import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Boleh tidaknya angka sentimen ditampilkan.
 *
 * Dibaca dari prop bersama, bukan dari prop tiap halaman. Dashboard yang
 * menyatakan sentimen belum tersedia sementara halaman sentimen di sebelahnya
 * tetap menampilkan angka adalah keadaan yang lebih membingungkan daripada
 * keduanya salah.
 *
 * Default-nya tersedia. Halaman yang propnya belum sempat dibagikan tidak
 * boleh ikut kosong hanya karena datanya tidak sampai.
 */
export function useGerbangSentimen() {
    const page = usePage<SharedData>();

    return {
        sentimenTersedia: computed(() => page.props.sentimen?.tersedia !== false),
        alasanSentimen: computed(() => page.props.sentimen?.alasan ?? null),
    };
}
