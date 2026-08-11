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

const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

const handleSystemThemeChange = () => {
    const currentAppearance = localStorage.getItem('appearance') as Appearance | null;
    updateTheme(currentAppearance || TEMA_BAWAAN);
};

export function initializeTheme() {
    // Tema diambil dari pilihan tersimpan, kalau belum ada pakai tema bawaan.
    const savedAppearance = localStorage.getItem('appearance') as Appearance | null;
    updateTheme(savedAppearance || TEMA_BAWAAN);

    // Set up system theme change listener...
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
