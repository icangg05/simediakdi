<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed } from 'vue';

/**
 * Pil tautan ke halaman lain, warnanya menyebut tujuan tautannya.
 *
 * **Panel eksekutif tidak punya satu pun aksi tulis.** Peran walikota memang
 * tidak diizinkan menulis apa pun, jadi setiap tombol di panel ini selalu
 * berupa perpindahan halaman. Komponen ini merender `Link`, bukan `button`,
 * dan itu bukan detail teknis: pembaca layar mengumumkannya sebagai tautan,
 * dan pengguna bisa membukanya di tab baru seperti tautan mana pun.
 *
 * **Ronanya bukan selera, ia menyebut apa yang akan dibuka.** Arti yang
 * berlaku di seluruh panel eksekutif:
 *
 * | Rona      | Membuka                                          |
 * |-----------|--------------------------------------------------|
 * | `biru`    | Arsip berita, daftar berita apa pun tanpa saringan nada |
 * | `toska`   | Media dan jangkauannya                           |
 * | `ungu`    | Apa pun yang disusun model, ulasan dan topiknya  |
 * | `positif` | Daftar berita bernada positif                    |
 * | `netral`  | Daftar berita bernada netral                     |
 * | `negatif` | Daftar berita bernada negatif                    |
 *
 * Ketiga rona nada persis sama dengan token sentimen, karena di panel ini
 * warna nada tidak pernah berarti hal lain. Tiga rona pertama sengaja
 * dijauhkan dari palet sentimen, supaya tautan ke arsip tidak pernah terbaca
 * seolah menyatakan nada pemberitaan.
 *
 * Warna tidak pernah menjadi penanda tunggal. Labelnya selalu menyebut sendiri
 * apa yang dibuka, dan panah di ujung menyatakan bahwa ini perpindahan.
 */
const props = withDefaults(
    defineProps<{
        href: string;
        rona: 'biru' | 'toska' | 'ungu' | 'positif' | 'netral' | 'negatif';
        /** Ikon di kepala pil. Boleh kosong untuk tautan pendek di kepala kartu. */
        ikon?: Component;
        ukuran?: 'kecil' | 'sedang';
    }>(),
    { ikon: undefined, ukuran: 'kecil' },
);

/*
 * Kelasnya ditulis utuh, bukan disusun dari potongan nama rona.
 *
 * Tailwind memindai berkas sebagai teks biasa, jadi kelas yang dirakit saat
 * program berjalan tidak pernah ikut terbangun dan pil ini akan tampil tanpa
 * warna sama sekali di produksi.
 */
const VARIAN = {
    biru: 'bg-aksen-biru/10 text-aksen-biru ring-aksen-biru/25 hover:bg-aksen-biru/20 focus-visible:outline-aksen-biru',
    toska: 'bg-aksen-toska/10 text-aksen-toska ring-aksen-toska/25 hover:bg-aksen-toska/20 focus-visible:outline-aksen-toska',
    ungu: 'bg-aksen-ungu/10 text-aksen-ungu ring-aksen-ungu/25 hover:bg-aksen-ungu/20 focus-visible:outline-aksen-ungu',
    positif:
        'bg-sentimen-positif/10 text-sentimen-positif ring-sentimen-positif/25 hover:bg-sentimen-positif/20 focus-visible:outline-sentimen-positif',
    netral: 'bg-sentimen-netral/10 text-sentimen-netral ring-sentimen-netral/25 hover:bg-sentimen-netral/20 focus-visible:outline-sentimen-netral',
    negatif:
        'bg-sentimen-negatif/10 text-sentimen-negatif ring-sentimen-negatif/25 hover:bg-sentimen-negatif/20 focus-visible:outline-sentimen-negatif',
} as const;

const varian = computed(() => VARIAN[props.rona]);

/**
 * Dua ukuran saja.
 *
 * `kecil` untuk tautan di kepala kartu, yang berdiri sebaris dengan judul dan
 * tidak boleh lebih berat daripada judulnya. `sedang` untuk tautan yang berdiri
 * sendiri di kaki kartu, dan itu satu-satunya tempat pil ini menjadi ajakan.
 */
const ukur = computed(() =>
    props.ukuran === 'sedang'
        ? { pil: 'gap-2 py-1.5 pl-4 pr-1.5 text-sm', ikon: 'size-4', lingkar: 'size-7', panah: 'size-3.5' }
        : { pil: 'gap-1.5 py-1 pl-3 pr-1 text-xs', ikon: 'size-3.5', lingkar: 'size-5', panah: 'size-3' },
);
</script>

<template>
    <!--
        Panah tidak pernah berdiri telanjang di sebelah teks. Ia duduk di dalam
        lingkarannya sendiri, rata dengan tepi kanan pil, sehingga pil ini punya
        dua lapis dan tidak terbaca sebagai lencana yang kebetulan bisa diklik.
        Lingkarannya yang bergerak saat kursor menyentuh, bukan seluruh pil,
        supaya arah perpindahan yang ditegaskan, bukan pilnya sendiri.
    -->
    <Link
        :href="href"
        class="tekan group inline-flex max-w-full shrink-0 items-center rounded-full font-semibold ring-1 ring-inset focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
        :class="[varian, ukur.pil]"
    >
        <component :is="ikon" v-if="ikon" :class="ukur.ikon" class="shrink-0" aria-hidden="true" />
        <span class="truncate"><slot /></span>
        <!-- Lingkarannya bernada netral, bukan rona pilnya sendiri. Rona di atas
             rona yang sama hanya menghasilkan bidang yang sedikit lebih pekat,
             dan lapis keduanya hilang justru pada ukuran terkecil. -->
        <span :class="ukur.lingkar" class="grid shrink-0 place-items-center rounded-full bg-black/[0.07] dark:bg-white/10" aria-hidden="true">
            <ArrowRight
                :class="ukur.panah"
                class="ease-[cubic-bezier(0.32,0.72,0,1)] transition-transform duration-300 group-hover:translate-x-0.5"
            />
        </span>
    </Link>
</template>
