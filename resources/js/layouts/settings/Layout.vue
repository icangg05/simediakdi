<script setup lang="ts">
import KopHalaman from '@/components/domain/KopHalaman.vue';
import PilKop from '@/components/domain/PilKop.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { KeyRound, Palette, UserRound } from 'lucide-vue-next';
import { computed, type Component } from 'vue';

const props = withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItem[] }>(), {
    breadcrumbs: () => [],
});

const page = usePage<SharedData>();

/**
 * Cangkang halaman pengaturan mengikuti peran pemakainya.
 *
 * Ketiga halaman pengaturan dulu memaksa `AppLayout` untuk semua orang, jadi
 * peran wali kota yang seluruh panelnya memakai navigasi mendatar tiba tiba
 * mendapat sidebar begitu membuka halaman profil. Aturannya sudah tertulis di
 * `nav.ts`: sidebar untuk admin dan portal media, header untuk panel eksekutif.
 * Yang kurang cuma penerapannya di sini.
 *
 * Pilihannya diletakkan di layout ini, bukan diulang di tiga halaman, karena
 * ketiganya menjawab pertanyaan yang sama dan salinan ketiga cepat atau lambat
 * akan tertinggal saat aturannya berubah.
 */
const eksekutif = computed(() => page.props.auth.user?.peran === 'walikota');

const cangkang = computed(() => (eksekutif.value ? LayoutEksekutif : AppLayout));

// Remah roti hanya dikenal AppLayout. Meneruskannya ke layout eksekutif membuat
// atribut itu jatuh ke elemen DOM sebagai teks.
const propCangkang = computed(() => (eksekutif.value ? {} : { breadcrumbs: props.breadcrumbs }));

const pengguna = computed(() => page.props.auth.user);

/**
 * Sebutan peran dalam bahasa Indonesia.
 *
 * Nilai mentahnya nama kolom database, bukan kalimat, dan halaman ini dibaca
 * pengguna dari ketiga peran. Sebutannya disamakan dengan yang dipakai form
 * pengguna di panel admin.
 */
const SEBUTAN_PERAN: Record<string, string> = {
    superadmin: 'Admin Diskominfo',
    walikota: 'Wali Kota dan Staf',
    media: 'Pengelola Media',
};

/**
 * Tiga halaman pengaturan, dalam bahasa Indonesia.
 *
 * Seluruh antarmuka wajib berbahasa Indonesia tanpa kecuali, dan area ini
 * satu-satunya yang masih memakai teks bawaan starter kit. Ikonnya ditambahkan
 * supaya menu ini terbaca sebagai daftar tujuan, bukan tiga tombol seragam.
 */
const menu: { judul: string; href: string; ikon: Component }[] = [
    { judul: 'Profil', href: '/settings/profile', ikon: UserRound },
    { judul: 'Kata sandi', href: '/settings/password', ikon: KeyRound },
    { judul: 'Tampilan', href: '/settings/appearance', ikon: Palette },
];

/**
 * Jalur diambil dari Inertia, bukan dari `window.location`.
 *
 * Dua alasan. Pertama, `window` tidak ada saat halaman dirender di server, dan
 * membacanya di sini menjatuhkan seluruh halaman `settings`. Kedua, nilai dari
 * `window.location` dibaca sekali saat setup lalu membeku, sedangkan `page.url`
 * ikut berubah pada setiap kunjungan Inertia, jadi penanda menu yang sedang
 * terbuka tidak pernah tertinggal.
 *
 * Tanya jawabnya dibuang: `page.url` membawa query string, sedangkan menu di
 * atas dibandingkan dengan jalur polos.
 */
const jalurSekarang = computed(() => page.url.split('?')[0]);
</script>

<template>
    <component :is="cangkang" v-bind="propCangkang">
        <!--
            Layout eksekutif sudah membawa jarak tepi dan lebar maksimalnya
            sendiri, jadi jarak di sini hanya dipasang untuk cangkang sidebar.
            Tanpa penjagaan ini isinya mendapat dua lapis jarak tepi.
        -->
        <div :class="['space-y-6', eksekutif ? '' : 'p-4']">
            <KopHalaman judul="Pengaturan akun" keterangan="Mengubah identitas, kata sandi, dan tampilan untuk akun Anda sendiri.">
                <template #aksi>
                    <!-- Inisial, bukan foto. Sistem tidak menyimpan avatar dan
                         tidak akan menyimpannya, jadi bidang berhuruf adalah
                         penanda paling jujur yang bisa dipasang di sini. -->
                    <div class="flex items-center gap-3 rounded-lg bg-white/10 px-3 py-2 ring-1 ring-white/20 ring-inset">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-white/15 text-sm font-semibold" aria-hidden="true">
                            {{ pengguna?.name?.charAt(0).toUpperCase() ?? '?' }}
                        </span>
                        <div class="min-w-0 text-sm leading-tight">
                            <p class="truncate font-medium">{{ pengguna?.name }}</p>
                            <p class="truncate text-xs text-white/70">{{ pengguna?.email }}</p>
                        </div>
                    </div>
                </template>

                <PilKop v-if="pengguna?.peran" :ikon="UserRound">
                    {{ SEBUTAN_PERAN[pengguna.peran] ?? pengguna.peran }}
                </PilKop>
            </KopHalaman>

            <div class="flex flex-col gap-8 lg:flex-row lg:gap-12">
                <!-- Menu jadi daftar bertautan, bukan tiga tombol ghost yang
                     bedanya hanya latar abu. Halaman yang sedang terbuka diberi
                     bidang navy lembut dan garis merek di sisi kirinya, penanda
                     yang sama dengan menu sidebar utama. -->
                <aside class="w-full lg:w-56 lg:shrink-0">
                    <nav class="flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible" aria-label="Menu pengaturan akun">
                        <Link
                            v-for="item in menu"
                            :key="item.href"
                            :href="item.href"
                            :aria-current="jalurSekarang === item.href ? 'page' : undefined"
                            class="tekan inline-flex shrink-0 items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
                            :class="
                                jalurSekarang === item.href
                                    ? 'bg-brand-lembut text-brand dark:text-white'
                                    : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                            "
                        >
                            <component :is="item.ikon" class="size-4 shrink-0" aria-hidden="true" />
                            {{ item.judul }}
                        </Link>
                    </nav>
                </aside>

                <div class="min-w-0 flex-1">
                    <section class="max-w-2xl space-y-8">
                        <slot />
                    </section>
                </div>
            </div>
        </div>
    </component>
</template>
