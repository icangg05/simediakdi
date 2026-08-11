<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItemType[];
        judul?: string;
        /**
         * Lebar kolom isi.
         *
         * `sempit` untuk halaman satu tugas: tambah berita, dan daftar yang
         * dibaca dari atas ke bawah. `sedang` untuk beranda, yang menaruh angka
         * bersebelahan dan butuh ruang mendatar supaya ketiganya terbaca dalam
         * satu tarikan mata, bukan menumpuk.
         */
        lebar?: 'sempit' | 'sedang';
    }>(),
    {
        breadcrumbs: () => [],
        lebar: 'sempit',
    },
);

const kelasLebar = computed(() => (props.lebar === 'sedang' ? 'max-w-5xl' : 'max-w-3xl'));
</script>

<template>
    <!-- Sederhana: satu tugas utama per halaman. -->
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full space-y-4 p-5 text-sm" :class="kelasLebar">
            <h1 v-if="judul" class="text-xl font-semibold">{{ judul }}</h1>
            <slot />
        </div>
    </AppLayout>
</template>
