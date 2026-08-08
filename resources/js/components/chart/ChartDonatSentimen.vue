<script setup lang="ts">
import BaseChart from '@/components/chart/BaseChart.vue';
import { useFormatAngka } from '@/composables/useFormatAngka';
import { useTemaChart } from '@/composables/useTemaChart';
import { computed } from 'vue';

const props = defineProps<{
    negatif: number;
    netral: number;
    positif: number;
    perluReview: number;
    tinggi?: number;
    memuat?: boolean;
}>();

const { warnaSentimen, warna } = useTemaChart();
const { formatAngka, formatProporsi } = useFormatAngka();

const total = computed(() => props.negatif + props.netral + props.positif + props.perluReview);

const potongan = computed(() => [
    { name: 'Positif', value: props.positif, itemStyle: { color: warnaSentimen.value.positif } },
    { name: 'Netral', value: props.netral, itemStyle: { color: warnaSentimen.value.netral } },
    { name: 'Negatif', value: props.negatif, itemStyle: { color: warnaSentimen.value.negatif } },
    { name: 'Perlu review', value: props.perluReview, itemStyle: { color: warnaSentimen.value.perlu_review } },
]);

const opsi = computed(() => ({
    textStyle: { fontFamily: 'IBM Plex Sans, sans-serif', color: warna.value.teks },
    tooltip: { trigger: 'item', valueFormatter: (n: number) => formatAngka(n) },
    legend: { bottom: 0, textStyle: { color: warna.value.teksSamar } },
    series: [
        {
            type: 'pie',
            radius: ['52%', '76%'],
            center: ['50%', '44%'],
            avoidLabelOverlap: false,
            padAngle: 2,
            itemStyle: { borderRadius: 3 },
            // Angka di tengah donat: total artikel berlabel pada periode itu.
            label: {
                show: true,
                position: 'center',
                formatter: () => formatAngka(total.value),
                fontSize: 26,
                fontWeight: 600,
                color: warna.value.teks,
            },
            emphasis: { label: { show: true } },
            labelLine: { show: false },
            data: potongan.value,
        },
    ],
}));

const barisTabel = computed(() => potongan.value.map((p) => [p.name, p.value, formatProporsi(p.value, total.value)]));
</script>

<template>
    <BaseChart
        judul="Komposisi sentimen"
        :opsi="opsi"
        :tinggi="tinggi"
        :memuat="memuat"
        :kolom-tabel="['Sentimen', 'Jumlah', 'Proporsi']"
        :baris-tabel="total > 0 ? barisTabel : []"
    />
</template>
