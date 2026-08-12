<script setup lang="ts">
import type { Component } from 'vue';
import { computed } from 'vue';

/**
 * Pil keterangan di dalam kop navy.
 *
 * Satu-satunya tempat di aplikasi yang boleh memakai palet Tailwind mentah
 * untuk warna berarti, dan itu perkecualian yang perlu dijelaskan sekali di
 * sini supaya tidak tersebar sebagai tujuh salinan alasan yang sama.
 *
 * Token sentimen dirancang untuk latar terang. Pada mode terang
 * `--color-sentimen-positif` berada di L 0,51, dan hijau setua itu di atas navy
 * kop hanya mencapai rasio 1,9, jauh di bawah ambang 4,5 WCAG AA. Rona di sini
 * sama persis dengan arti yang sudah ditetapkan seluruh panel admin, hanya
 * dicerahkan sampai terbaca di atas navy. Kalau arti sebuah rona berubah,
 * berkas ini dan token sentimennya harus diubah bersamaan.
 *
 * | Nada     | Arti                                  | Rona   |
 * |----------|---------------------------------------|--------|
 * | `netral` | Keterangan biasa, bukan keadaan       | Putih  |
 * | `baik`   | Sehat, berhasil, berlaku              | Hijau  |
 * | `tunggu` | Menunggu, perlu diperiksa             | Kuning |
 * | `buruk`  | Mati, gagal, rusak                    | Merah  |
 * | `kerja`  | Model atau pekerjaan sedang berjalan  | Ungu   |
 *
 * Warna tidak pernah jadi penanda tunggal. Pemanggil wajib mengisi `ikon`
 * kecuali untuk nada `netral`, yang memang tidak menyatakan keadaan apa pun.
 */
const props = withDefaults(
    defineProps<{
        nada?: 'netral' | 'baik' | 'tunggu' | 'buruk' | 'kerja';
        ikon?: Component;
        /** Ikon yang berputar, untuk pekerjaan yang sedang berjalan. */
        berputar?: boolean;
    }>(),
    { nada: 'netral', ikon: undefined, berputar: false },
);

const kelas = computed(
    () =>
        ({
            netral: 'bg-white/10 text-white/85 ring-white/20',
            baik: 'bg-emerald-300/15 text-emerald-200 ring-emerald-300/40',
            tunggu: 'bg-amber-300/15 text-amber-200 ring-amber-300/40',
            buruk: 'bg-red-300/15 text-red-200 ring-red-300/40',
            kerja: 'bg-violet-300/15 text-violet-200 ring-violet-300/40',
        })[props.nada],
);
</script>

<template>
    <span class="inline-flex max-w-full items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium ring-1 ring-inset" :class="kelas">
        <component
            :is="ikon"
            v-if="ikon"
            class="size-3.5 shrink-0"
            :class="berputar ? 'animate-spin motion-reduce:animate-none' : ''"
            aria-hidden="true"
        />
        <span class="truncate"><slot /></span>
    </span>
</template>
