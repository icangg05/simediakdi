<script setup lang="ts">
import BaseChart from '@/components/chart/BaseChart.vue';
import { useTemaChart } from '@/composables/useTemaChart';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { computed } from 'vue';

interface Baris {
    tanggal: string;
    jumlah_artikel: number;
}

const props = defineProps<{ data: Baris[]; tinggi?: number; memuat?: boolean; zoom?: boolean }>();

const { warna, dasar, sumbuNilai, sumbuKategori } = useTemaChart();

const tanggal = computed(() => props.data.map((b) => format(new Date(b.tanggal), 'd MMM', { locale: id })));

const opsi = computed(() => ({
    ...dasar.value,
    // dataZoom hanya di desktop; di layar sempit ia memakan ruang grafik.
    ...(props.zoom ? { dataZoom: [{ type: 'inside' }, { type: 'slider', height: 18, bottom: 22 }] } : {}),
    grid: { ...dasar.value.grid, bottom: props.zoom ? 52 : 8 },
    xAxis: { ...sumbuKategori.value, data: tanggal.value },
    yAxis: sumbuNilai.value,
    series: [
        {
            name: 'Berita',
            type: 'line',
            smooth: false,
            showSymbol: false,
            areaStyle: { opacity: 0.2 },
            itemStyle: { color: warna.value.teks },
            data: props.data.map((b) => b.jumlah_artikel),
        },
    ],
}));

const barisTabel = computed(() => props.data.map((b) => [format(new Date(b.tanggal), 'd MMM yyyy', { locale: id }), b.jumlah_artikel]));
</script>

<template>
    <BaseChart
        judul="Tren volume berita"
        :opsi="opsi"
        :tinggi="tinggi"
        :memuat="memuat"
        :kolom-tabel="['Tanggal', 'Berita']"
        :baris-tabel="barisTabel"
    />
</template>
