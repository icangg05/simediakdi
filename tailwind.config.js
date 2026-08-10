import defaultTheme from 'tailwindcss/defaultTheme';

/** Token warna domain yang menerima penanda transparansi Tailwind. */
const warna = (nama) => `rgb(from var(${nama}) r g b / <alpha-value>)`;

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: ['class'],
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{vue,js,ts,jsx,tsx}',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['IBM Plex Sans', ...defaultTheme.fontFamily.sans],
            },
            borderRadius: {
                lg: 'var(--radius)',
                md: 'calc(var(--radius) - 2px)',
                sm: 'calc(var(--radius) - 4px)',
            },
            colors: {
                /*
                 * Token domain, definisinya di resources/css/app.css.
                 *
                 * Dibungkus rgb(from ...) supaya penanda transparansi Tailwind
                 * berfungsi. Sebelumnya nilainya var() polos, dan Tailwind tidak
                 * punya tempat menyisipkan alpha di situ: kelas seperti
                 * `border-sentimen-negatif/30` tidak menghasilkan CSS sama
                 * sekali, hilang tanpa peringatan. Sintaks warna relatif ini
                 * menerima sumber warna apa pun, termasuk oklch dan
                 * heksadesimal.
                 *
                 * useTemaChart() tetap membaca custom property-nya langsung
                 * lewat getComputedStyle, jadi grafik tidak terpengaruh.
                 */
                brand: {
                    DEFAULT: warna('--color-brand'),
                    terang: warna('--color-brand-terang'),
                    lembut: warna('--color-brand-lembut'),
                },
                aksen: {
                    biru: warna('--color-aksen-biru'),
                    toska: warna('--color-aksen-toska'),
                    ungu: warna('--color-aksen-ungu'),
                },
                sentimen: {
                    positif: warna('--color-sentimen-positif'),
                    'positif-lembut': warna('--color-sentimen-positif-lembut'),
                    netral: warna('--color-sentimen-netral'),
                    'netral-lembut': warna('--color-sentimen-netral-lembut'),
                    negatif: warna('--color-sentimen-negatif'),
                    'negatif-lembut': warna('--color-sentimen-negatif-lembut'),
                    review: warna('--color-sentimen-review'),
                    'review-lembut': warna('--color-sentimen-review-lembut'),
                },
                tier: {
                    nasional: warna('--color-tier-nasional'),
                    regional: warna('--color-tier-regional'),
                    lokal: warna('--color-tier-lokal'),
                },
                background: 'hsl(var(--background))',
                foreground: 'hsl(var(--foreground))',
                card: {
                    DEFAULT: 'hsl(var(--card))',
                    foreground: 'hsl(var(--card-foreground))',
                },
                popover: {
                    DEFAULT: 'hsl(var(--popover))',
                    foreground: 'hsl(var(--popover-foreground))',
                },
                primary: {
                    DEFAULT: 'hsl(var(--primary))',
                    foreground: 'hsl(var(--primary-foreground))',
                },
                secondary: {
                    DEFAULT: 'hsl(var(--secondary))',
                    foreground: 'hsl(var(--secondary-foreground))',
                },
                muted: {
                    DEFAULT: 'hsl(var(--muted))',
                    foreground: 'hsl(var(--muted-foreground))',
                },
                accent: {
                    DEFAULT: 'hsl(var(--accent))',
                    foreground: 'hsl(var(--accent-foreground))',
                },
                destructive: {
                    DEFAULT: 'hsl(var(--destructive))',
                    foreground: 'hsl(var(--destructive-foreground))',
                },
                border: 'hsl(var(--border))',
                input: 'hsl(var(--input))',
                ring: 'hsl(var(--ring))',
                chart: {
                    1: 'hsl(var(--chart-1))',
                    2: 'hsl(var(--chart-2))',
                    3: 'hsl(var(--chart-3))',
                    4: 'hsl(var(--chart-4))',
                    5: 'hsl(var(--chart-5))',
                },
                sidebar: {
                    DEFAULT: 'hsl(var(--sidebar-background))',
                    foreground: 'hsl(var(--sidebar-foreground))',
                    primary: 'hsl(var(--sidebar-primary))',
                    'primary-foreground': 'hsl(var(--sidebar-primary-foreground))',
                    accent: 'hsl(var(--sidebar-accent))',
                    'accent-foreground': 'hsl(var(--sidebar-accent-foreground))',
                    border: 'hsl(var(--sidebar-border))',
                    ring: 'hsl(var(--sidebar-ring))',
                },
            },
        },
    },
    plugins: [require('tailwindcss-animate')],
};
