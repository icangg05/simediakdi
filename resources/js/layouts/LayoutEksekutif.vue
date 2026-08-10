<script setup lang="ts">
import AppHeaderLayout from '@/layouts/app/AppHeaderLayout.vue';
</script>

<template>
    <!--
        Navigasi mendatar, bukan sidebar. Halaman ini dilihat tiga menit dan
        harus paham tanpa membaca label kecil, jadi lebar layar diberikan
        seluruhnya ke isi. Admin dan portal media tetap memakai sidebar.

        Latar bertinta dipasang AppHeaderLayout supaya rata sampai tepi layar.
        Yang dibatasi di sini hanya lebar isinya.
    -->
    <AppHeaderLayout>
        <div class="mx-auto w-full max-w-[1400px] space-y-5 px-4 py-6 text-sm md:px-6 md:py-8">
            <slot />
        </div>
    </AppHeaderLayout>
</template>
