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
import { BarChart3, FileText, LayoutGrid, Newspaper, Rss, ScrollText, Tags, Target } from 'lucide-vue-next';
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
        { title: 'Media', href: '/admin/media', icon: Newspaper },
        { title: 'Sumber Feed', href: '/admin/sumber-feed', icon: Rss },
        { title: 'Log Crawl', href: '/admin/log-crawl', icon: ScrollText },
    ],
    walikota: [{ title: 'Ringkasan', href: '/eksekutif', icon: BarChart3 }],
    media: [{ title: 'Beranda', href: '/portal', icon: FileText }],
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
