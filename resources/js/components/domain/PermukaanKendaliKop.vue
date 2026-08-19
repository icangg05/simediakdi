<script setup lang="ts">
/*
 * `empty:hidden` supaya wadahnya ikut padam saat isinya padam.
 *
 * Pemilih bulan memadamkan dirinya sendiri ketika rentang yang berlaku
 * melintasi lebih dari satu bulan. Tanpa aturan ini yang tersisa adalah kotak
 * putih kosong di kop, dan pembaca akan menekannya sekali sebelum menyimpulkan
 * halamannya rusak.
 */
defineProps<{ tanpaPadding?: boolean }>();
</script>

<template>
    <div
        class="rounded-xl bg-background text-foreground shadow-lg ring-1 shadow-brand/30 ring-white/25 empty:hidden"
        :class="tanpaPadding ? 'p-0' : 'p-1'"
    >
        <slot />
    </div>
</template>
