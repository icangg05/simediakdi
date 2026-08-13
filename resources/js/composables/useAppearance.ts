import { onMounted, ref } from 'vue';

type Appearance = 'light' | 'dark' | 'system';

/**
 * Tema bawaan, dipakai selama pengguna belum pernah memilih sendiri.
 *
 * Sengaja terang, bukan mengikuti tema perangkat. Panel ini dibuka di komputer
 * kantor dan diproyeksikan di ruang rapat, dan satu perangkat yang kebetulan
 * bertema gelap membuat halaman yang sama terlihat berbeda dari yang lain.
 * Pilihan Sistem tetap tersedia di halaman Appearance, hanya tidak lagi
 * menjadi bawaan.
 */
const TEMA_BAWAAN: Appearance = 'light';

export function updateTheme(value: Appearance) {
    if (value === 'system') {
        const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        document.documentElement.classList.toggle('dark', systemTheme === 'dark');
    } else {
        document.documentElement.classList.toggle('dark', value === 'dark');
    }
}

/**
 * Dibuat saat dibutuhkan, bukan saat berkas ini diimpor.
 *
 * Sebelumnya `window.matchMedia` dipanggil langsung di badan modul. Di
 * peramban itu tidak pernah bermasalah, tetapi render sisi server memuat modul
 * yang sama di Node, tempat `window` tidak ada, sehingga berkas ini meledak
 * saat diimpor dan menjatuhkan seluruh render sebelum satu komponen pun
 * digambar. Halaman Appearance mengimpornya, jadi bukan kasus jauh.
 *
 * Disimpan supaya pendengarnya tidak didaftarkan dua kali kalau
 * `initializeTheme` dipanggil lebih dari sekali.
 */
let mediaQuery: MediaQueryList | null = null;

const handleSystemThemeChange = () => {
    const currentAppearance = localStorage.getItem('appearance') as Appearance | null;
    updateTheme(currentAppearance || TEMA_BAWAAN);
};

export function initializeTheme() {
    // Tema diambil dari pilihan tersimpan, kalau belum ada pakai tema bawaan.
    const savedAppearance = localStorage.getItem('appearance') as Appearance | null;
    updateTheme(savedAppearance || TEMA_BAWAAN);

    if (mediaQuery) return;

    // Set up system theme change listener...
    mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
    mediaQuery.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance() {
    const appearance = ref<Appearance>(TEMA_BAWAAN);

    onMounted(() => {
        initializeTheme();

        const savedAppearance = localStorage.getItem('appearance') as Appearance | null;

        if (savedAppearance) {
            appearance.value = savedAppearance;
        }
    });

    function updateAppearance(value: Appearance) {
        appearance.value = value;
        localStorage.setItem('appearance', value);
        updateTheme(value);
    }

    return {
        appearance,
        updateAppearance,
    };
}
