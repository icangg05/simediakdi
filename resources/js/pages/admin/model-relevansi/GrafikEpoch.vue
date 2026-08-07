<script setup lang="ts">
import BaseChart from '@/components/chart/BaseChart.vue';
import { useTemaChart } from '@/composables/useTemaChart';
import { computed } from 'vue';
import type { Epoch } from './tipe';

/**
 * Training loss, validation loss, dan F1 validasi dalam satu grafik.
 *
 * Memakai BaseChart yang sama dengan grafik dashboard, bukan SVG sendiri.
 * Pembungkus itu sudah membawa keadaan kosong, tema gelap, unduh PNG, dan yang
 * paling penting tampilan tabel, satu-satunya cara angka per epoch ini bisa
 * dibaca pembaca layar dan disalin ke laporan.
 */
const props = defineProps<{ riwayat: Epoch[]; tinggi?: number }>();

const { warna, dasar, sumbuKategori } = useTemaChart();

const opsi = computed(() => ({
    ...dasar.value,
    grid: { ...dasar.value.grid, bottom: 24 },
    xAxis: { ...sumbuKategori.value, data: props.riwayat.map((b) => `Epoch ${b.epoch}`) },
    // Dua sumbu Y karena loss dan F1 berbeda satuan. Menumpuknya pada satu
    // sumbu membuat kurva loss yang bergerak antara 0,1 dan 0,7 terlihat datar
    // di sebelah F1 yang hampir menyentuh 1.
    yAxis: [
        {
            type: 'value',
            name: 'Loss',
            min: 0,
            axisLine: { show: false },
            axisTick: { show: false },
            splitLine: { lineStyle: { color: warna.value.garis } },
            axisLabel: { color: warna.value.teksSamar },
            nameTextStyle: { color: warna.value.teksSamar },
        },
        {
            type: 'value',
            name: 'F1',
            min: 0,
            max: 1,
            axisLine: { show: false },
            axisTick: { show: false },
            splitLine: { show: false },
            axisLabel: { color: warna.value.teksSamar },
            nameTextStyle: { color: warna.value.teksSamar },
        },
    ],
    series: [
        {
            name: 'Training loss',
            type: 'line',
            showSymbol: false,
            itemStyle: { color: warna.value.teks },
            data: props.riwayat.map((b) => b.train_loss),
        },
        {
            name: 'Validation loss',
            type: 'line',
            showSymbol: false,
            itemStyle: { color: warna.value.negatif },
            data: props.riwayat.map((b) => b.val_loss),
        },
        {
            name: 'F1 validasi',
            type: 'line',
            yAxisIndex: 1,
            showSymbol: false,
            lineStyle: { type: 'dashed' },
            itemStyle: { color: warna.value.positif },
            data: props.riwayat.map((b) => b.val_f1),
        },
    ],
}));

const barisTabel = computed(() => props.riwayat.map((b) => [`Epoch ${b.epoch}`, b.train_loss, b.val_loss, b.val_accuracy, b.val_f1]));
</script>

<template>
    <BaseChart
        judul="Loss dan metrik per epoch"
        :opsi="opsi"
        :tinggi="tinggi ?? 200"
        :kolom-tabel="['Epoch', 'Training loss', 'Validation loss', 'Accuracy validasi', 'F1 validasi']"
        :baris-tabel="barisTabel"
        keterangan-kosong="Angka per epoch muncul begitu epoch pertama selesai."
    />
</template>
