<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { Component } from 'vue';
import { computed } from 'vue';

/**
 * Kartu seksi bertanda rona untuk panel eksekutif.
 *
 * Sengaja bukan `KartuSeksi`, walaupun bentuknya bersaudara. Komponen itu
 * membawa tabel arti sendiri yang dipakai halaman Pengaturan dan Model
 * Relevansi, tempat ungu berarti pekerjaan yang memakai kuota Gemini dan toska
 * berarti penilaian yang dikerjakan di server sendiri. Panel eksekutif memakai
 * rona yang sama untuk arti yang berbeda, dan menumpuk dua tabel arti pada satu
 * komponen berarti suatu hari seseorang mengubah rona di satu halaman lalu
 * merusak arti di halaman lain tanpa menyadarinya.
 *
 * **Arti rona di panel eksekutif:**
 *
 * | Rona      | Isi kartu                                        |
 * |-----------|--------------------------------------------------|
 * | `biru`    | Berita dan arsipnya, tanpa saringan nada         |
 * | `toska`   | Media dan jangkauannya                           |
 * | `ungu`    | Apa pun yang disusun model                       |
 * | `positif` | Berita bernada positif                           |
 * | `netral`  | Berita bernada netral                            |
 * | `negatif` | Berita bernada negatif                           |
 * | `review`  | Peringatan dan hal yang perlu dicek ulang        |
 *
 * Sama persis dengan tabel `TautanTujuan`, dan itu wajib. Kartu bernada toska
 * yang tautannya biru membuat pembaca menduga tautan itu membawanya ke tempat
 * lain daripada yang sebenarnya.
 *
 * Warna tidak pernah menjadi penanda tunggal. Setiap kartu wajib mengisi
 * `ikon`, dan judulnya menyebutkan sendiri apa isinya.
 */
const props = withDefaults(
    defineProps<{
        judul: string;
        /** Kalimat penjelas di bawah judul. Boleh kosong untuk kartu pendek. */
        catatan?: string;
        ikon: Component;
        rona: 'biru' | 'toska' | 'ungu' | 'positif' | 'netral' | 'negatif' | 'review';
        /**
         * Seluruh badan kartu ikut bernada, bukan hanya kepalanya.
         *
         * Hanya untuk kartu yang isinya memang satu nada, misalnya daftar
         * berita negatif. Dua daftar berdampingan dengan badan putih yang sama
         * terbaca seperti satu daftar yang terpotong dua, dan pembaca kehilangan
         * bahwa keduanya menjawab pertanyaan yang berlawanan.
         */
        bertinta?: boolean;
        /** Isi menempel ke tepi kartu, untuk daftar yang punya pembatasnya sendiri. */
        padat?: boolean;
        /**
         * Aksi mengapung di pojok kanan atas, di luar aliran kepala kartu.
         *
         * Untuk aksi sekecil lencana angka. Di layar sempit kepala kartu
         * menurunkan aksinya ke baris sendiri, dan satu lencana dua digit yang
         * menghabiskan satu baris penuh adalah ruang yang dibayar terlalu mahal.
         * Aksi selebar grup tombol tetap harus mengalir, karena yang mengapung
         * di pojok akan menutupi judulnya sendiri.
         */
        aksiPojok?: boolean;
    }>(),
    { catatan: undefined, bertinta: false, padat: false, aksiPojok: false },
);

/*
 * Kelas ditulis utuh, tidak dirakit dari nama rona. Tailwind memindai berkas
 * sebagai teks, jadi kelas yang baru terbentuk saat program berjalan tidak
 * pernah ikut dibangun.
 *
 * `warna` menunjuk variabel CSS yang sama dengan yang dibaca kelas di
 * sebelahnya, sehingga kabut dan tile tidak pernah bergeser satu sama lain saat
 * token dicerahkan untuk mode gelap.
 */
const VARIAN = {
    biru: {
        tile: 'bg-aksen-biru text-white dark:text-background',
        teks: 'text-aksen-biru',
        tinta: 'border-aksen-biru/25 bg-aksen-biru/5',
        warna: 'var(--color-aksen-biru)',
    },
    toska: {
        tile: 'bg-aksen-toska text-white dark:text-background',
        teks: 'text-aksen-toska',
        tinta: 'border-aksen-toska/25 bg-aksen-toska/5',
        warna: 'var(--color-aksen-toska)',
    },
    ungu: {
        tile: 'bg-aksen-ungu text-white dark:text-background',
        teks: 'text-aksen-ungu',
        tinta: 'border-aksen-ungu/25 bg-aksen-ungu/5',
        warna: 'var(--color-aksen-ungu)',
    },
    positif: {
        tile: 'bg-sentimen-positif text-white dark:text-background',
        teks: 'text-sentimen-positif',
        tinta: 'border-sentimen-positif/25 bg-sentimen-positif-lembut/45',
        warna: 'var(--color-sentimen-positif)',
    },
    netral: {
        tile: 'bg-sentimen-netral text-white dark:text-background',
        teks: 'text-sentimen-netral',
        tinta: 'border-sentimen-netral/25 bg-sentimen-netral-bidang/85',
        warna: 'var(--color-sentimen-netral)',
    },
    negatif: {
        tile: 'bg-sentimen-negatif text-white dark:text-background',
        teks: 'text-sentimen-negatif',
        tinta: 'border-sentimen-negatif/25 bg-sentimen-negatif-lembut/50',
        warna: 'var(--color-sentimen-negatif)',
    },
    review: {
        tile: 'bg-sentimen-review text-white dark:text-background',
        teks: 'text-sentimen-review',
        tinta: 'border-sentimen-review/30 bg-sentimen-review-lembut/60',
        warna: 'var(--color-sentimen-review)',
    },
} as const;

const varian = computed(() => VARIAN[props.rona]);

/**
 * Kabut rona di puncak kartu.
 *
 * Satu sapuan yang memudar sebelum menyentuh isi, bukan bidang berwarna.
 * Halaman ini berjajar tujuh kartu ke bawah dengan bentuk yang sama, dan tanpa
 * penanda yang tertangkap dari sudut mata seluruhnya melebur jadi satu kolom
 * panjang. Kepekatannya rendah: yang dicari arah, bukan bidang kedua yang harus
 * dibaca.
 */
const kabut = computed(() => ({
    background: `radial-gradient(32rem 11rem at 6% -55%, color-mix(in oklab, ${varian.value.warna} 26%, transparent), transparent 72%)`,
}));

/** Garis rona di tepi atas kartu, tumbuh dari kiri saat halaman terbuka. */
const garis = computed(() => ({
    background: `linear-gradient(90deg, color-mix(in oklab, ${varian.value.warna} 85%, transparent), transparent 78%)`,
}));
</script>

<template>
    <Card class="relative overflow-hidden" :class="bertinta ? varian.tinta : ''">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-44" :style="kabut" aria-hidden="true"></div>
        <div class="tumbuh pointer-events-none absolute inset-x-0 top-0 h-[2px]" :style="garis" aria-hidden="true"></div>

        <!--
            Di layar sempit aksi turun ke baris sendiri. Sebelumnya kepala kartu
            selalu sebaris, dan aksi yang tidak bisa menyusut memeras judul
            sampai tersisa satu kata per baris lalu keluar dari tepi kartu.

            Aksi yang mengapung tidak ikut aturan itu. Ia keluar dari aliran,
            jadi yang perlu disiapkan untuknya cuma ruang kanan, supaya judul
            dan catatannya berhenti tepat sebelum lencana.
        -->
        <CardHeader
            class="relative flex-col items-stretch gap-3 space-y-0 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5"
            :class="aksiPojok ? 'pr-16 sm:pr-20' : ''"
        >
            <CardTitle class="flex min-w-0 items-center gap-3 text-base">
                <span :class="varian.tile" class="grid size-9 shrink-0 place-items-center rounded-xl shadow-xs">
                    <component :is="ikon" class="size-[18px]" aria-hidden="true" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate leading-tight">{{ judul }}</span>
                    <span v-if="catatan" class="mt-0.5 block text-xs leading-snug font-normal text-muted-foreground">{{ catatan }}</span>
                </span>
            </CardTitle>

            <div v-if="$slots.aksi && !aksiPojok" class="flex min-w-0 shrink-0 items-center gap-2">
                <slot name="aksi" />
            </div>
        </CardHeader>

        <div v-if="$slots.aksi && aksiPojok" class="absolute top-4 right-4 z-10 flex items-center gap-2 sm:top-5 sm:right-5">
            <slot name="aksi" />
        </div>

        <CardContent class="relative" :class="padat ? 'p-0' : 'p-4 pt-0 sm:p-5 sm:pt-0'">
            <slot />
        </CardContent>
    </Card>
</template>
