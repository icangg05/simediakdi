<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <!--
            Bayangan kartu isi ditinta navy, bukan hitam. Varian `inset`
            meletakkan kartu putih ini di atas bidang sidebar yang sejak sekarang
            berwarna navy, dan bayangan hitam bawaan Tailwind praktis hilang di
            sana. Bayangannya punya sumbu turun dan sebaran lebar, jadi kartunya
            terbaca terangkat, bukan sekadar berhalo.
        -->
        <AppContent
            variant="sidebar"
            class="md:peer-data-[variant=inset]:shadow-[0_1px_2px_0_hsl(211_66%_10%/0.35),0_16px_36px_-16px_hsl(211_66%_8%/0.55)]"
        >
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
    </AppShell>
</template>
