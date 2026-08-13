import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, defineAsyncComponent, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { initializeTheme } from './composables/useAppearance';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/**
 * Toast dimuat terpisah, setelah halaman tampil.
 *
 * `vue-sonner` beserta pembungkus gayanya sekitar 66 kB sumber, dan dulu ia
 * ikut bundel utama yang menentukan kapan seluruh aplikasi mulai tergambar.
 * Halaman tidak pernah butuh toast untuk tampil: yang membutuhkannya hanya
 * halaman yang baru saja dikirimi pesan flash, dan pesan itu dibaca lagi di
 * `onMounted` komponennya, jadi yang datang bersama muat penuh tetap terbit
 * walau komponennya menyusul beberapa saat kemudian.
 *
 * Tetap disandingkan dengan App, bukan dipindah ke dalam layout. Alasannya
 * tidak berubah dan ada di berkas komponennya.
 */
const NotifikasiFlash = defineAsyncComponent(() => import('@/components/NotifikasiFlash.vue'));

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        // NotifikasiFlash sengaja disandingkan dengan App, bukan ditaruh di
        // dalam layout. Adapter Inertia mengganti `key` komponen halaman pada
        // setiap kunjungan tanpa `preserveState`, jadi apa pun di bawah App
        // ikut dibongkar dan dipasang ulang. Toast yang sedang tampil akan
        // hilang di tengah jalan kalau ia ikut di dalam pohon itu.
        createApp({ render: () => [h(App, props), h(NotifikasiFlash)] })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();
