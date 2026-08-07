<script setup lang="ts">
import AppHeaderLayout from '@/layouts/app/AppHeaderLayout.vue';
</script>

<template>
    <!--
        Navigasi mendatar, bukan sidebar. Halaman ini dilihat tiga menit dan
        harus paham tanpa membaca label kecil, jadi lebar layar diberikan
        seluruhnya ke isi. Admin dan portal media tetap memakai sidebar.
    -->
    <AppHeaderLayout>
        <div class="space-y-5 px-4 py-6 text-sm md:px-6">
            <slot />
        </div>
    </AppHeaderLayout>
</template>
