<script setup lang="ts">
import BaseChart from '@/components/chart/BaseChart.vue';
import { useTemaChart } from '@/composables/useTemaChart';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { computed } from 'vue';

interface Baris {
    tanggal: string;
    jumlah_negatif: number;
    jumlah_netral: number;
    jumlah_positif: number;
    jumlah_perlu_review: number;
}

const props = defineProps<{ data: Baris[]; tinggi?: number; memuat?: boolean }>();

const { warnaSentimen, dasar, sumbuNilai, sumbuKategori } = useTemaChart();

const tanggal = computed(() => props.data.map((b) => format(new Date(b.tanggal), 'd MMM', { locale: id })));

/**
 * Urutan tetap dari bawah: positif, netral, negatif. Urutan yang berubah antar
 * halaman membuat pembaca salah baca.
 *
 * "Perlu review" jadi deret tersendiri, bukan dilebur ke netral. Menyembunyikan
 * ketidakpastian di dalam netral adalah cara termudah membuat dashboard yang
 * menyesatkan.
 */
const deret = [
    { kunci: 'jumlah_positif', nama: 'Positif', warna: 'positif' },
    { kunci: 'jumlah_netral', nama: 'Netral', warna: 'netral' },
    { kunci: 'jumlah_negatif', nama: 'Negatif', warna: 'negatif' },
    { kunci: 'jumlah_perlu_review', nama: 'Perlu review', warna: 'perlu_review' },
] as const;

const opsi = computed(() => ({
    ...dasar.value,
    xAxis: { ...sumbuKategori.value, data: tanggal.value },
    yAxis: sumbuNilai.value,
    series: deret.map((d) => ({
        name: d.nama,
        type: 'line',
        stack: 'total',
        smooth: false,
        showSymbol: false,
        areaStyle: { opacity: 0.75 },
        lineStyle: { width: 1 },
        itemStyle: { color: warnaSentimen.value[d.warna] },
        data: props.data.map((b) => b[d.kunci]),
    })),
}));

const barisTabel = computed(() =>
    props.data.map((b) => [
        format(new Date(b.tanggal), 'd MMM yyyy', { locale: id }),
        b.jumlah_positif,
        b.jumlah_netral,
        b.jumlah_negatif,
        b.jumlah_perlu_review,
    ]),
);
</script>

<template>
    <BaseChart
        judul="Tren sentimen"
        :opsi="opsi"
        :tinggi="tinggi"
        :memuat="memuat"
        :kolom-tabel="['Tanggal', 'Positif', 'Netral', 'Negatif', 'Perlu review']"
        :baris-tabel="barisTabel"
    />
</template>
