<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

/**
 * Lambang aplikasi: tugu di antara dua bidang, di atas riak teluk.
 *
 * Menggantikan lambang Laravel bawaan starter kit. Panel ini dibuka pimpinan
 * daerah dan lambang kerangka kerja di kop halaman resmi Pemerintah Kota adalah
 * kekeliruan yang terlihat oleh siapa pun yang mengenalinya.
 *
 * Bukan lambang resmi Pemerintah Kota Kendari. Kalau berkas lambang resminya
 * tersedia, berkas itu yang dipakai dan komponen ini dibuang.
 */
defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" fill="currentColor" :class="className" v-bind="$attrs">
        <!-- Tugu. -->
        <path d="M16 2.5a2.6 2.6 0 0 1 2.6 2.6A2.6 2.6 0 0 1 16 7.7a2.6 2.6 0 0 1-2.6-2.6A2.6 2.6 0 0 1 16 2.5Z" />
        <path d="M14.6 9.2h2.8l1.1 13.4h-5Z" />
        <!-- Dua bidang bangunan mengapit tugu. -->
        <path d="M4 13.6h6.6v9H4Z" opacity=".65" />
        <path d="M21.4 13.6H28v9h-6.6Z" opacity=".65" />
        <!-- Dasar. -->
        <path d="M2.5 24.2h27v2.2h-27Z" />
        <!-- Riak teluk. -->
        <path
            d="M3.4 28.6c1.6-1.1 3.2-1.1 4.8 0 1.6 1.1 3.2 1.1 4.8 0 1.6-1.1 3.2-1.1 4.8 0 1.6 1.1 3.2 1.1 4.8 0 1.6-1.1 3.2-1.1 4.8 0"
            fill="none"
            stroke="currentColor"
            stroke-width="1.6"
            stroke-linecap="round"
            opacity=".55"
        />
    </svg>
</template>
