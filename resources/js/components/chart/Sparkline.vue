<script setup lang="ts">
import { computed } from 'vue';

/**
 * Garis mungil di dalam kartu angka, menggambar bentuk kasar satu deret.
 *
 * Bukan hiasan. Datanya deret yang sama dengan grafik besar di bawah halaman,
 * dan gunanya menjawab satu pertanyaan yang tidak bisa dijawab angka tunggal:
 * apakah angka itu hasil arus yang merata atau satu hari yang meledak. Kalau
 * suatu saat deretnya tidak tersedia, komponen ini tidak dirender sama sekali,
 * bukan digambar dengan angka karangan.
 *
 * Tanpa sumbu, tanpa label, tanpa tooltip. Yang perlu angka persis membuka
 * grafik penuhnya, dan menaruh sumbu di ruang selebar delapan puluh piksel
 * hanya menghasilkan teks yang tidak terbaca.
 */
const props = withDefaults(defineProps<{ nilai: number[]; lebar?: number; tinggi?: number }>(), {
    lebar: 96,
    tinggi: 32,
});

/** Disisakan sedikit di atas dan bawah supaya garisnya tidak terpotong tepi. */
const SISA = 3;

const titik = computed(() => {
    const n = props.nilai.length;

    if (n === 0) return [];

    const puncak = Math.max(...props.nilai);
    const dasar = Math.min(...props.nilai);
    const jarak = puncak - dasar || 1;

    // Satu titik saja tidak punya kemiringan. Digambar sebagai garis datar di
    // tengah, bukan dibagi nol.
    const langkah = n === 1 ? 0 : props.lebar / (n - 1);
    const tinggiPakai = props.tinggi - SISA * 2;

    return props.nilai.map((v, i) => ({
        x: n === 1 ? props.lebar / 2 : i * langkah,
        y: SISA + tinggiPakai - ((v - dasar) / jarak) * tinggiPakai,
    }));
});

const garis = computed(() => titik.value.map((t, i) => `${i === 0 ? 'M' : 'L'}${t.x.toFixed(1)} ${t.y.toFixed(1)}`).join(' '));

const bidang = computed(() =>
    titik.value.length === 0
        ? ''
        : `${garis.value} L${titik.value[titik.value.length - 1].x.toFixed(1)} ${props.tinggi} L${titik.value[0].x.toFixed(1)} ${props.tinggi} Z`,
);
</script>

<template>
    <!--
        aria-hidden: bentuk garis ini tidak menambah apa pun bagi pembaca layar
        yang sudah mendengar angkanya di sebelah, dan deret lengkapnya tersedia
        sebagai tabel di grafik utama.
    -->
    <svg
        v-if="titik.length"
        :viewBox="`0 0 ${lebar} ${tinggi}`"
        :width="lebar"
        :height="tinggi"
        fill="none"
        preserveAspectRatio="none"
        aria-hidden="true"
        class="overflow-visible"
    >
        <path :d="bidang" class="fill-foreground/10" />
        <path :d="garis" class="stroke-foreground/45" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
</template>
