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
        <!--
            `flex-1` supaya latarnya mengisi sisa tinggi kotak isi, bukan hanya
            setinggi isinya. Tanpa itu halaman pendek menyisakan bidang putih di
            bawah, dan latar abunya berhenti di tengah layar seperti potongan.

            `md:rounded-b-xl` mengikuti sudut kotak isi. Kotak itu membulat di
            layar lebar, dan bidang berlatar yang bersudut siku di dalamnya akan
            menyembul melewati lengkungannya.

            Kopnya sendiri sengaja dibiarkan putih. Perbedaan itu yang membuat
            bilah kop terbaca sebagai chrome aplikasi, bukan bagian dari isi.
        -->
        <div class="flex-1 space-y-4 bg-latar-admin p-4 text-sm md:rounded-b-xl">
            <h1 v-if="judul" class="text-xl font-semibold">{{ judul }}</h1>
            <slot />
        </div>
    </AppLayout>
</template>
