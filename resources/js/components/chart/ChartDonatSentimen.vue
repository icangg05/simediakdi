<script setup lang="ts">
import BaseChart from '@/components/chart/BaseChart.vue';
import { useFormatAngka } from '@/composables/useFormatAngka';
import { useTemaChart } from '@/composables/useTemaChart';
import { computed } from 'vue';

/**
 * Tiga nada saja. "Perlu review" bukan nada pemberitaan, melainkan pernyataan
 * tentang keyakinan model, dan menaruhnya sebagai potongan keempat membuat
 * lingkaran ini menjawab dua pertanyaan sekaligus. Angka di tengah donat pun
 * berhenti berarti "berita berlabel".
 */
const props = defineProps<{
    negatif: number;
    netral: number;
    positif: number;
    tinggi?: number;
    memuat?: boolean;
}>();

const { warnaSentimen, warna } = useTemaChart();
const { formatAngka, formatProporsi } = useFormatAngka();

const total = computed(() => props.negatif + props.netral + props.positif);

const potongan = computed(() => [
    { name: 'Positif', value: props.positif, itemStyle: { color: warnaSentimen.value.positif } },
    { name: 'Netral', value: props.netral, itemStyle: { color: warnaSentimen.value.netral } },
    { name: 'Negatif', value: props.negatif, itemStyle: { color: warnaSentimen.value.negatif } },
]);

const opsi = computed(() => ({
    textStyle: { fontFamily: 'IBM Plex Sans, sans-serif', color: warna.value.teks },
    tooltip: { trigger: 'item', valueFormatter: (n: number) => formatAngka(n) },
    /*
     * Legenda bawaan dimatikan. Pemanggilnya mencetak legendanya sendiri
     * sebagai daftar yang bisa dibuka, lengkap dengan jumlah dan porsi tiap
     * nada, dan dua legenda untuk satu grafik hanya memakan ruang. Nama tiap
     * potongan tetap terbaca lewat tooltip dan lewat tombol "lihat sebagai
     * tabel".
     */
    series: [
        {
            type: 'pie',
            radius: ['52%', '76%'],
            // Tanpa legenda di bawah, lingkarannya duduk di tengah bidang.
            center: ['50%', '50%'],
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
