<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItemType } from '@/types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItemType[]; judul?: string }>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <!-- Sederhana: satu tugas utama per halaman. -->
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-3xl space-y-4 p-5 text-sm">
            <h1 v-if="judul" class="text-xl font-semibold">{{ judul }}</h1>
            <slot />
        </div>
    </AppLayout>
</template>
