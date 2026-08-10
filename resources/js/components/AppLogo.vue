<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';

interface Props {
    class?: string;
}

defineProps<Props>();
</script>

<template>
    <!--
        Kotak lambang mengambil warna dari induknya lewat currentColor, bukan
        warna tetap. Komponen ini dipakai di dua tempat dengan latar berlawanan,
        kop eksekutif yang navy dan sidebar admin yang terang, dan warna tetap
        akan hilang di salah satunya.
    -->
    <div class="flex aspect-square size-9 shrink-0 items-center justify-center rounded-lg bg-[color-mix(in_srgb,currentColor_14%,transparent)]">
        <AppLogoIcon class="size-5 fill-current" />
    </div>
    <div class="ml-2 grid flex-1 text-left leading-tight">
        <span class="truncate text-sm font-semibold">Pemerintah Kota Kendari</span>
        <span class="truncate text-xs opacity-70">Media Monitoring</span>
    </div>
</template>
