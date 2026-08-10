<script setup lang="ts">
interface Props {
    class?: string;
}

defineProps<Props>();
</script>

<template>
    <!--
        Lambang resmi Pemerintah Kota Kendari, bukan lagi lambang gambar sendiri.
        Kotaknya tetap mengambil warna dari induknya lewat currentColor, karena
        komponen ini dipakai di dua latar berlawanan: kop eksekutif yang navy dan
        sidebar admin yang terang.

        Lambangnya berwarna penuh, jadi kotak di belakangnya dibuat sangat
        samar. Tanpa kotak itu, lambang berwarna langsung di atas navy pekat
        kehilangan tepinya.
    -->
    <div class="flex aspect-square size-9 shrink-0 items-center justify-center rounded-lg bg-[color-mix(in_srgb,currentColor_10%,transparent)]">
        <img src="/img/Lambang_Kota_Kendari.webp" alt="Lambang Kota Kendari" class="size-7 object-contain" width="28" height="28" />
    </div>
    <div class="ml-2 grid flex-1 text-left leading-tight">
        <span class="truncate text-sm font-semibold">Pemerintah Kota Kendari</span>
        <span class="truncate text-xs opacity-70">Media Monitoring</span>
    </div>
</template>
