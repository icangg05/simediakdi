<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItemType[];
        /**
         * Lebar kolom isi.
         *
         * `sempit` untuk halaman satu tugas yang dibaca dari atas ke bawah.
         * `sedang` untuk halaman yang menaruh angka bersebelahan atau memuat
         * tabel berkolom banyak, dan karena itu butuh ruang mendatar supaya
         * isinya terbaca dalam satu tarikan mata alih-alih menumpuk.
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
        <!--
            Dua lapis, dan keduanya perlu.

            Lapis luar membawa latar dan mengisi sisa tinggi kotak isi. Tanpa
            `flex-1` halaman pendek menyisakan bidang putih di bawah, dan latar
            abunya berhenti di tengah layar seperti potongan. Lapis dalam yang
            membatasi lebar bacanya. Kalau keduanya digabung, latar abunya ikut
            menyempit menjadi satu pita di tengah layar lebar dan sisi kiri kanan
            kembali putih.

            Latarnya token yang sama dengan panel admin, bukan token baru.
            Sebelumnya bidang isi portal memakai `--background` yang putih murni,
            sama persis dengan `--card`, sehingga kartu putih berdiri di atas
            latar putih dan satu-satunya yang memisahkan keduanya adalah garis
            tepi setipis satu piksel. Nilai latar admin sudah ditimbang terhadap
            dua tetangganya sekaligus, dan alasannya tertulis lengkap di
            resources/css/app.css.

            `md:rounded-b-xl` mengikuti sudut kotak isi. Kotak itu membulat di
            layar lebar, dan bidang berlatar yang bersudut siku di dalamnya akan
            menyembul melewati lengkungannya.

            Judul halaman tidak lagi dicetak di sini. Ketiga halaman portal kini
            memakai KopHalaman, komponen kop navy yang sama dengan panel admin,
            dan prop `judul` yang dulu ada di sini tidak dipanggil satu halaman
            pun. Membiarkannya berarti ada dua cara menulis judul yang cepat atau
            lambat dipakai bergantian.
        -->
        <div class="flex-1 bg-latar-admin p-5 md:rounded-b-xl">
            <div class="mx-auto w-full space-y-4 text-sm" :class="kelasLebar">
                <slot />
            </div>
        </div>
    </AppLayout>
</template>
