<script setup lang="ts">
// Toast pesan hasil aksi tidak lagi tinggal di sini. Ia dipasang sekali di akar
// aplikasi lewat components/NotifikasiFlash.vue, karena layout ini ikut
// dibongkar setiap kali Inertia mengganti halaman dan toast yang sedang tampil
// ikut terbuang bersamanya.
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppLayout>
</template>
