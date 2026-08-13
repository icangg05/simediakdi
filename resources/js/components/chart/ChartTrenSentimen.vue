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

/**
 * `judul` bisa diganti pemanggil supaya halaman tidak mencetak dua judul untuk
 * satu grafik. Panel eksekutif menyebutnya "Perubahan dari waktu ke waktu",
 * karena "tren sentimen" belum tentu berarti apa pun bagi pembacanya.
 */
const props = withDefaults(defineProps<{ data: Baris[]; satuan?: SatuanDeret; tinggi?: number; memuat?: boolean; judul?: string }>(), {
    satuan: 'harian',
    judul: 'Tren sentimen',
});

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

const judulKolom = computed(() => ({ harian: 'Tanggal', mingguan: 'Pekan mulai', bulanan: 'Bulan' })[props.satuan]);

const tanggal = computed(() => props.data.map((b) => format(new Date(b.tanggal), polaLabel[props.satuan], { locale: id })));

/**
 * Urutan tetap: positif, netral, negatif. Urutan yang berubah antar halaman
 * membuat pembaca salah baca legendanya.
 *
 * Deretnya tidak ditumpuk. Tumpukan memaksa pembaca mengurangi garis atas dari
 * garis bawah untuk tahu nilai satu nada, dan itu terbukti membingungkan.
 * Sekarang tinggi tiap garis langsung dibaca dari sumbu kiri. Jumlah total per
 * titik hilang dari grafik, tapi angka itu sudah ada di kartu ringkasan dan di
 * tampilan tabel.
 *
 * "Perlu review" tidak digambar. Deret keempat itu tidak menyatakan nada
 * pemberitaan, hanya keyakinan model. Angkanya tetap disebut satu baris di
 * bawah batang komposisi pada halaman eksekutif, jadi tidak ada yang hilang
 * diam-diam.
 */
const deret = [
    { kunci: 'jumlah_positif', nama: 'Positif', warna: 'positif' },
    { kunci: 'jumlah_netral', nama: 'Netral', warna: 'netral' },
    { kunci: 'jumlah_negatif', nama: 'Negatif', warna: 'negatif' },
] as const;

const opsi = computed(() => ({
    ...dasar.value,
    xAxis: { ...sumbuKategori.value, data: tanggal.value },
    yAxis: sumbuNilai.value,
    series: deret.map((d, urutan) => ({
        name: d.nama,
        type: 'line',
        /*
         * Lengkung, bukan patah.
         *
         * Deretnya jumlah harian, jadi lengkungan menyiratkan nilai antara dua
         * tanggal yang sebenarnya tidak ada. Itu bisa diterima di sini karena
         * yang dibaca pimpinan bentuk pergerakannya, bukan nilai di antara dua
         * titik, dan angka persisnya selalu tersedia lewat tombol "lihat
         * sebagai tabel".
         *
         * `smoothMonotone` menahan lengkungan supaya tidak melewati nilai
         * tertinggi atau terendah titiknya. Tanpa itu garisnya bisa menukik ke
         * bawah nol di antara dua hari bernilai nol.
         */
        smooth: 0.4,
        smoothMonotone: 'x',
        showSymbol: false,
        // Simbol hanya muncul saat kursor menunjuk, jadi grafiknya tetap bersih.
        symbolSize: 7,
        emphasis: { focus: 'series' },
        /*
         * Tanpa isian di bawah garis. Deretnya tidak lagi ditumpuk, jadi tiga
         * area yang saling menimpa hanya menutupi deret yang nilainya kecil.
         */
        lineStyle: { width: 2.5 },
        itemStyle: { color: warnaSentimen.value[d.warna] },
        // Deret digambar berurutan dari bawah, mengikuti urutan legendanya.
        animationDuration: 900,
        animationDelay: urutan * 120,
        animationEasing: 'cubicOut',
        data: props.data.map((b) => b[d.kunci]),
    })),
}));

const barisTabel = computed(() =>
    props.data.map((b) => [
        format(new Date(b.tanggal), props.satuan === 'bulanan' ? 'MMMM yyyy' : 'd MMM yyyy', { locale: id }),
        b.jumlah_positif,
        b.jumlah_netral,
        b.jumlah_negatif,
    ]),
);
</script>

<template>
    <BaseChart
        :judul="judul"
        :opsi="opsi"
        :tinggi="tinggi"
        :memuat="memuat"
        :kolom-tabel="[judulKolom, 'Positif', 'Netral', 'Negatif']"
        :baris-tabel="barisTabel"
    />
</template>
