<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItemType } from '@/types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItemType[]; judul?: string }>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <!-- Padat: pengguna melihat halaman ini setiap hari dan menghargai sedikit scroll. -->
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4 text-sm">
            <h1 v-if="judul" class="text-xl font-semibold">{{ judul }}</h1>
            <slot />
        </div>
    </AppLayout>
</template>
