<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { hrefAktif } from '@/nav';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    items: NavItem[];
}>();

const page = usePage<SharedData>();

const aktif = computed(() => hrefAktif(props.items, page.url));

/**
 * Item berurutan dengan `kelompok` yang sama disatukan, urutannya dijaga apa
 * adanya dari nav.ts.
 *
 * Dikelompokkan di sini, bukan disimpan sebagai daftar bersarang di nav.ts,
 * karena tiga pemakai lain (header eksekutif, footer, penyorotan menu aktif)
 * membaca daftar yang sama sebagai satu baris datar. Bentuk bersarang memaksa
 * ketiganya meratakannya kembali sebelum bisa dipakai.
 *
 * Peran yang itemnya tidak memakai `kelompok` sama sekali menghasilkan satu
 * kelompok tanpa label, persis seperti sebelum pengelompokan ada.
 */
const kelompok = computed(() => {
    const hasil: { label?: string; items: NavItem[] }[] = [];

    for (const item of props.items) {
        const terakhir = hasil.at(-1);

        if (terakhir && terakhir.label === item.kelompok) {
            terakhir.items.push(item);
        } else {
            hasil.push({ label: item.kelompok, items: [item] });
        }
    }

    return hasil;
});
</script>

<template>
    <!--
        Label kelompok ikut hilang sendiri saat sidebar diciutkan ke mode ikon,
        aturannya ada di SidebarGroupLabel. Kelompok tanpa label tidak menyisakan
        ruang kosong di tempat judulnya.
    -->
    <SidebarGroup v-for="(grup, i) in kelompok" :key="grup.label ?? i" class="px-2 py-0">
        <SidebarGroupLabel v-if="grup.label">{{ grup.label }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in grup.items" :key="item.title">
                <SidebarMenuButton as-child :is-active="item.href === aktif">
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
