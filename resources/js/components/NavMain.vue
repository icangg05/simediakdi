<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    items: NavItem[];
}>();

const page = usePage<SharedData>();

/**
 * Menu yang disorot: href terpanjang yang cocok dengan halaman sekarang.
 *
 * Bukan pembandingan persis, karena `/admin/media/create` harus tetap menyorot
 * "Media", dan tabel di sini memakai filter sisi server sehingga `page.url`
 * kerap membawa query string. Terpanjang yang menang supaya "Dashboard"
 * (`/admin`) tidak ikut menyala di setiap halaman admin.
 */
const hrefAktif = computed(() => {
    const jalur = page.url.split('?')[0];

    return props.items
        .filter((item) => jalur === item.href || jalur.startsWith(`${item.href}/`))
        .sort((a, b) => b.href.length - a.href.length)[0]?.href;
});
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>Platform</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton as-child :is-active="item.href === hrefAktif">
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
