<script setup lang="ts">
import BaseChart from '@/components/chart/BaseChart.vue';
import { useTemaChart } from '@/composables/useTemaChart';
import { computed } from 'vue';

interface Baris {
    nama: string;
    jumlah_artikel: number;
    jumlah_negatif: number;
    jumlah_netral: number;
    jumlah_positif: number;
}

const props = defineProps<{ data: Baris[]; tinggi?: number; memuat?: boolean }>();

const { warnaSentimen, dasar, warna } = useTemaChart();

// Bar horizontal digambar dari bawah ke atas, jadi urutannya dibalik supaya
// media dengan artikel terbanyak tampil di puncak.
const urut = computed(() => [...props.data].reverse());

const opsi = computed(() => ({
    ...dasar.value,
    grid: { left: 8, right: 16, top: 8, bottom: 28, containLabel: true },
    tooltip: { ...dasar.value.tooltip, axisPointer: { type: 'shadow' } },
    xAxis: {
        type: 'value',
        min: 0,
        splitLine: { lineStyle: { color: warna.value.garis } },
        axisLabel: { color: warna.value.teksSamar },
    },
    yAxis: {
        type: 'category',
        data: urut.value.map((b) => b.nama),
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: warna.value.teksSamar },
    },
    series: [
        { kunci: 'jumlah_positif', nama: 'Positif', warna: 'positif' },
        { kunci: 'jumlah_netral', nama: 'Netral', warna: 'netral' },
        { kunci: 'jumlah_negatif', nama: 'Negatif', warna: 'negatif' },
    ].map((d) => ({
        name: d.nama,
        type: 'bar',
        stack: 'total',
        itemStyle: { color: warnaSentimen.value[d.warna] },
        data: urut.value.map((b) => b[d.kunci as keyof Baris]),
    })),
}));

const barisTabel = computed(() =>
    props.data.map((b) => [b.nama, b.jumlah_artikel, b.jumlah_positif, b.jumlah_netral, b.jumlah_negatif]),
);
</script>

<template>
    <BaseChart
        judul="Peringkat media"
        :opsi="opsi"
        :tinggi="tinggi ?? 320"
        :memuat="memuat"
        :kolom-tabel="['Media', 'Total', 'Positif', 'Netral', 'Negatif']"
        :baris-tabel="barisTabel"
        keterangan-kosong="Belum ada media yang memuat berita pada rentang ini."
    />
</template>
