<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItemType } from '@/types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItemType[]; judul?: string }>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <!-- Lapang: dilihat tiga menit dan harus paham tanpa membaca label kecil. -->
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-5 p-6 text-sm">
            <h1 v-if="judul" class="text-2xl font-semibold">{{ judul }}</h1>
            <slot />
        </div>
    </AppLayout>
</template>
