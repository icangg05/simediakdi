<script setup lang="ts">
import BaseChart from '@/components/chart/BaseChart.vue';
import { useTemaChart } from '@/composables/useTemaChart';
import type { SatuanDeret } from '@/types';
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

const props = withDefaults(
    defineProps<{ data: Baris[]; satuan?: SatuanDeret; tinggi?: number; memuat?: boolean }>(),
    { satuan: 'harian' },
);

const { warnaSentimen, dasar, sumbuNilai, sumbuKategori } = useTemaChart();

/**
 * Label sumbu mengikuti satuan yang dipilih server dari panjang rentang.
 *
 * Rentang tiga bulan yang diberi label harian menghasilkan sembilan puluh
 * tanggal yang saling tindih dan tidak terbaca satu pun.
 */
const polaLabel: Record<SatuanDeret, string> = {
    harian: 'd MMM',
    mingguan: 'd MMM',
    bulanan: 'MMM yyyy',
};

const judulKolom = computed(
    () => ({ harian: 'Tanggal', mingguan: 'Pekan mulai', bulanan: 'Bulan' })[props.satuan],
);

const tanggal = computed(() =>
    props.data.map((b) => format(new Date(b.tanggal), polaLabel[props.satuan], { locale: id })),
);

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
        format(new Date(b.tanggal), props.satuan === 'bulanan' ? 'MMMM yyyy' : 'd MMM yyyy', { locale: id }),
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
        :kolom-tabel="[judulKolom, 'Positif', 'Netral', 'Negatif', 'Perlu review']"
        :baris-tabel="barisTabel"
    />
</template>
