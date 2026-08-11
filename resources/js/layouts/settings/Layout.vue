<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/AppLayout.vue';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import { type BreadcrumbItem, type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

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

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: '/settings/profile',
    },
    {
        title: 'Password',
        href: '/settings/password',
    },
    {
        title: 'Appearance',
        href: '/settings/appearance',
    },
];

const currentPath = window.location.pathname;
</script>

<template>
    <component :is="cangkang" v-bind="propCangkang">
        <!--
            Layout eksekutif sudah membawa jarak tepi dan lebar maksimalnya
            sendiri, jadi jarak di sini hanya dipasang untuk cangkang sidebar.
            Tanpa penjagaan ini isinya mendapat dua lapis jarak tepi.
        -->
        <div :class="eksekutif ? '' : 'px-4 py-6'">
            <Heading title="Settings" description="Manage your profile and account settings" />

            <div class="flex flex-col space-y-8 md:space-y-0 lg:flex-row lg:space-x-12 lg:space-y-0">
                <aside class="w-full max-w-xl lg:w-48">
                    <nav class="flex flex-col space-x-0 space-y-1">
                        <Button
                            v-for="item in sidebarNavItems"
                            :key="item.href"
                            variant="ghost"
                            :class="['w-full justify-start', { 'bg-muted': currentPath === item.href }]"
                            as-child
                        >
                            <Link :href="item.href">
                                {{ item.title }}
                            </Link>
                        </Button>
                    </nav>
                </aside>

                <Separator class="my-6 md:hidden" />

                <div class="flex-1 md:max-w-2xl">
                    <section class="max-w-xl space-y-12">
                        <slot />
                    </section>
                </div>
            </div>
        </div>
    </component>
</template>
