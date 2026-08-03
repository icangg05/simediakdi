<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { type NavItem, type PeranPengguna, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    BarChart3, BellRing, ClipboardCheck, FileSignature, FileText, Flame,
    LayoutGrid, Network, Newspaper, Rss, ScrollText, Send, Settings, Tags,
    Target, Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

// Satu aplikasi, tiga grup route. Navigasi mengikuti peran, bukan sebaliknya.
const navPerPeran: Record<PeranPengguna, NavItem[]> = {
    superadmin: [
        { title: 'Dashboard', href: '/admin', icon: LayoutGrid },
        { title: 'Artikel', href: '/admin/artikel', icon: FileText },
        { title: 'Konteks', href: '/admin/konteks', icon: Target },
        { title: 'Pelabelan', href: '/admin/pelabelan', icon: Tags },
        { title: 'Evaluasi Model', href: '/admin/evaluasi', icon: BarChart3 },
        { title: 'Kontrak', href: '/admin/kontrak', icon: FileSignature },
        { title: 'Verifikasi Pemuatan', href: '/admin/pemuatan', icon: ClipboardCheck },
        { title: 'Entitas', href: '/admin/entitas', icon: Network },
        { title: 'Alert', href: '/admin/alert', icon: BellRing },
        { title: 'Media', href: '/admin/media', icon: Newspaper },
        { title: 'Sumber Feed', href: '/admin/sumber-feed', icon: Rss },
        { title: 'Pengguna', href: '/admin/pengguna', icon: Users },
        { title: 'Log Crawl', href: '/admin/log-crawl', icon: ScrollText },
        { title: 'Pengaturan', href: '/admin/pengaturan', icon: Settings },
    ],
    walikota: [
        { title: 'Ringkasan', href: '/eksekutif', icon: LayoutGrid },
        { title: 'Sentimen', href: '/eksekutif/sentimen', icon: BarChart3 },
        { title: 'Isu Hangat', href: '/eksekutif/isu', icon: Flame },
        { title: 'Peringkat Media', href: '/eksekutif/media', icon: Newspaper },
        { title: 'Arsip Berita', href: '/eksekutif/berita', icon: FileText },
    ],
    media: [
        { title: 'Beranda', href: '/portal', icon: LayoutGrid },
        { title: 'Berita Saya', href: '/portal/berita', icon: FileText },
        { title: 'Kontrak Saya', href: '/portal/kontrak', icon: FileSignature },
        { title: 'Lapor Pemuatan', href: '/portal/lapor', icon: Send },
    ],
};

const mainNavItems = computed(() => navPerPeran[page.props.auth.user?.peran] ?? []);
const beranda = computed(() => mainNavItems.value[0]?.href ?? '/dashboard');
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="beranda">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
