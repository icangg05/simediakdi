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
 * Porsi nada per satuan waktu, bukan jumlahnya.
 *
 * Grafik garis di sebelahnya menjawab "berapa banyak berita bernada ini", dan
 * batang komposisi di kop menjawab "berapa porsinya sepanjang rentang". Tidak
 * ada satu pun yang menjawab "apakah porsi negatif membesar dari pekan ke
 * pekan", karena rentang yang ramai kegiatan menaikkan ketiga garis sekaligus
 * dan porsinya bisa saja tidak bergerak sama sekali.
 *
 * Tiap batang selalu setinggi seratus persen, jadi yang dibandingkan antar
 * batang hanya pembagian warnanya.
 *
 * Di atas batang itu ada garis waktu yang berjalan sendiri. Batang menjawab
 * bentuk perubahan sepanjang rentang, garis waktu menyorot satu periode dan
 * memecahnya jadi pai di kanan atas. Keduanya membaca data yang sama, jadi
 * animasinya tidak menambah satu pun angka baru, hanya menuntun mata dari
 * periode paling lama ke paling baru.
 */
const props = withDefaults(defineProps<{ data: Baris[]; satuan?: SatuanDeret; tinggi?: number; memuat?: boolean; judul?: string }>(), {
    satuan: 'harian',
    judul: 'Porsi tiap nada',
});

const { warnaSentimen, dasar, warna, sumbuKategori, gelap } = useTemaChart();

const polaLabel: Record<SatuanDeret, string> = {
    harian: 'd MMM',
    mingguan: 'd MMM',
    bulanan: 'MMM yyyy',
};

const judulKolom = computed(() => ({ harian: 'Tanggal', mingguan: 'Pekan mulai', bulanan: 'Bulan' })[props.satuan]);

const tanggal = computed(() => props.data.map((b) => format(new Date(b.tanggal), polaLabel[props.satuan], { locale: id })));

const deret = [
    { kunci: 'jumlah_positif', nama: 'Positif', warna: 'positif' },
    { kunci: 'jumlah_netral', nama: 'Netral', warna: 'netral' },
    { kunci: 'jumlah_negatif', nama: 'Negatif', warna: 'negatif' },
] as const;

/** Total berlabel per titik. Nol berarti tidak ada berita, batangnya kosong. */
const total = computed(() => props.data.map((b) => b.jumlah_positif + b.jumlah_netral + b.jumlah_negatif));

/**
 * Persen mentah, tidak dibulatkan.
 *
 * Pembulatan dipakai hanya saat mencetak angka. Kalau nilainya sendiri
 * dibulatkan, tiga potongan bisa berjumlah 101 dan batangnya melewati garis
 * seratus persen.
 */
const persen = computed(() => deret.map((d) => props.data.map((b, i) => (total.value[i] ? (b[d.kunci] / total.value[i]) * 100 : 0))));

/**
 * Putar otomatis dimatikan kalau sistem pengguna meminta gerak dikurangi.
 *
 * Dibaca sekali saat setup, bukan lewat listener. Nilainya kosong di server,
 * dan itu tidak jadi masalah: setup dijalankan ulang di peramban saat hidrasi,
 * dan renderer grafiknya sendiri memang tidak pernah hidup di server.
 */
const kurangiGerak = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Gaya penggeser zoom.
 *
 * Warnanya ditulis rgba, bukan token CSS aplikasi. Token sentimen bernilai
 * oklch. Kanvas sanggup menggambarnya apa adanya, tapi begitu nilainya perlu
 * dicampur, misalnya diberi alfa untuk isian penggeser, hasilnya bukan warna
 * yang bisa dibaca zrender dan penggesernya hilang tanpa pesan error.
 *
 * Bayangan data dimatikan. Untuk batang bertumpuk seratus persen, bayangannya
 * hanya kotak penuh dari ujung ke ujung, tidak menyiratkan bentuk apa pun.
 */
const gayaZoom = computed(() => ({
    borderColor: 'transparent',
    backgroundColor: gelap.value ? 'rgba(255,255,255,0.04)' : 'rgba(0,0,0,0.03)',
    fillerColor: gelap.value ? 'rgba(255,255,255,0.10)' : 'rgba(22,63,108,0.10)',
    handleStyle: { color: warna.value.latarTooltip, borderColor: warna.value.teksSamar, borderWidth: 1 },
    moveHandleStyle: { color: warna.value.teksSamar, opacity: 0.5 },
    emphasis: { moveHandleStyle: { color: warna.value.teksSamar, opacity: 0.8 } },
    dataBackground: { lineStyle: { opacity: 0 }, areaStyle: { opacity: 0 } },
    selectedDataBackground: { lineStyle: { opacity: 0 }, areaStyle: { opacity: 0 } },
    brushSelect: false,
    showDetail: false,
}));

/**
 * Kerangka yang tetap sepanjang animasi.
 *
 * Batang, sumbu, dan pai didefinisikan sekali di sini. Tiap bingkai di
 * `options` hanya menimpa bagian yang memang berubah, yaitu judul periode,
 * sorotan batang, dan isi pai. Menyalin seluruh opsi ke tiap bingkai berarti
 * mengirim ulang seluruh deret sebanyak jumlah periode, dan rentang harian tiga
 * bulan sudah cukup untuk membuat opsinya puluhan kali lebih besar dari datanya.
 */
const dasarOpsi = computed(() => ({
    ...dasar.value,
    title: { left: 'center', top: 0, textStyle: { color: warna.value.teksSamar, fontSize: 12, fontWeight: 'normal' as const } },
    /*
     * Ruang bawah untuk garis waktu, ruang kanan untuk penggeser persen. Tanpa
     * ini `containLabel` hanya menghitung label sumbu dan keduanya digambar
     * menimpa batang terluar.
     */
    grid: { ...dasar.value.grid, right: 34, bottom: 74 },
    timeline: {
        axisType: 'category',
        data: tanggal.value,
        // Diam di periode terbaru kalau animasi dimatikan, karena itu yang
        // paling sering dicari. Saat berjalan, mulai dari yang paling lama
        // supaya gerakannya searah dengan batang di bawahnya.
        currentIndex: kurangiGerak ? props.data.length - 1 : 0,
        autoPlay: !kurangiGerak,
        playInterval: 1500,
        loop: true,
        left: 52,
        right: 28,
        bottom: 4,
        height: 42,
        symbol: 'circle',
        symbolSize: 6,
        lineStyle: { color: warna.value.garis },
        itemStyle: { color: warna.value.teksSamar, opacity: 0.55 },
        checkpointStyle: { color: warna.value.aksen, borderColor: warna.value.latarTooltip, borderWidth: 2, animationDuration: 300 },
        controlStyle: { color: warna.value.teksSamar, borderColor: warna.value.teksSamar, itemSize: 14, itemGap: 8 },
        emphasis: {
            itemStyle: { color: warna.value.aksen, opacity: 1 },
            controlStyle: { color: warna.value.aksen, borderColor: warna.value.aksen },
            label: { color: warna.value.teks },
        },
        label: { color: warna.value.teksSamar, fontSize: 10 },
    },
    tooltip: {
        ...dasar.value.tooltip,
        axisPointer: { type: 'shadow' },
        // Persen dan jumlah asli sekaligus. Porsi tanpa jumlah menyesatkan pada
        // pekan sepi: tiga berita negatif dari empat berita terbaca 75 persen.
        valueFormatter: undefined,
        formatter: (titik: Array<{ dataIndex: number; seriesIndex: number; marker: string; seriesName: string }>) => {
            const i = titik[0].dataIndex;

            if (!total.value[i]) return `${tanggal.value[i]}<br>Tidak ada berita berlabel`;

            const baris = titik
                .map((t) => {
                    const jumlah = props.data[i][deret[t.seriesIndex].kunci];

                    return `${t.marker} ${t.seriesName}: <b>${Math.round(persen.value[t.seriesIndex][i])}%</b> (${jumlah} berita)`;
                })
                .join('<br>');

            return `${tanggal.value[i]}<br>${baris}<br><span style="opacity:.7">Total ${total.value[i]} berita</span>`;
        },
    },
    xAxis: { ...sumbuKategori.value, boundaryGap: true, data: tanggal.value },
    /*
     * Batas atas tidak dipatok di sini, tapi lewat `dataZoom` sumbu tegak di
     * bawah. Sumbu yang punya `max` sendiri mengabaikan rentang dari dataZoom,
     * dan penggesernya jadi bisa ditarik tanpa mengubah apa pun.
     */
    yAxis: {
        type: 'value',
        axisLine: { show: false },
        axisTick: { show: false },
        splitLine: { lineStyle: { color: warna.value.garis, type: 'dashed' } },
        axisLabel: { color: warna.value.teksSamar, formatter: '{value}%' },
    },
    /*
     * Penggeser mendatar dilepas, garis waktu yang menempati dasar kartu. Yang
     * tersisa penggeser persen di kanan, karena potongan negatif duduk di ujung
     * atas batang dan sering hanya beberapa persen. Menarik jendelanya ke 80-100
     * membesarkan pita itu tanpa mengubah datanya.
     *
     * `filterMode: 'none'` pada sumbu tegak, bukan 'empty'. Deretnya bertumpuk,
     * dan 'empty' akan menghapus seluruh potongan yang keluar jendela sehingga
     * tumpukannya runtuh ke dasar. 'none' hanya memotong tampilannya.
     */
    dataZoom: [
        {
            /*
             * Zoom di dalam kanvas butuh Ctrl. Roda tetikus polos di atas
             * grafik harus tetap menggulir halaman. Grafik ini duduk di tengah
             * halaman panjang, dan menyita roda tetikus di sana berarti gulir
             * halaman berhenti setiap kali kursor melewati kartunya.
             */
            type: 'inside',
            zoomOnMouseWheel: 'ctrl',
            moveOnMouseWheel: false,
            moveOnMouseMove: false,
        },
        {
            ...gayaZoom.value,
            type: 'slider',
            yAxisIndex: 0,
            filterMode: 'none',
            startValue: 0,
            endValue: 100,
            width: 14,
            right: 8,
            top: 44,
            bottom: 74,
        },
    ],
    series: [
        ...deret.map((d, urutan) => ({
            name: d.nama,
            type: 'bar',
            stack: 'porsi',
            // Batang tetap ramping saat rentangnya hanya berisi beberapa titik.
            // Tanpa batas ini satu bulan data menghasilkan balok selebar kartu.
            barMaxWidth: 28,
            /*
             * Potongan dipisah dua piksel berwarna permukaan kartu, bukan
             * ditempelkan. Tiga bidang pekat yang bersentuhan langsung membuat
             * batasnya bergetar, dan potongan tipis di ujung batang, yang justru
             * paling sering negatif, hilang tertelan tetangganya.
             */
            itemStyle: { color: warnaSentimen.value[d.warna], borderColor: warna.value.latarTooltip, borderWidth: 2 },
            emphasis: { focus: 'series' },
            animationDuration: 700,
            animationDelay: urutan * 100,
            animationEasing: 'cubicOut',
            data: persen.value[urutan],
        })),
        {
            /*
             * Pai periode terpilih, menumpang di atas batang seperti pada contoh
             * "Finance Indices" milik ECharts. Isinya jumlah berita, bukan
             * persen, karena persennya sudah terbaca dari tinggi potongan di
             * batang dan yang belum terjawab justru "ini porsi dari berapa
             * berita".
             *
             * Tooltipnya dipasang di deret, bukan di akar opsi. Akar sudah
             * dipakai tooltip sumbu untuk batang, dan pai tidak punya sumbu.
             */
            name: 'Porsi periode',
            type: 'pie',
            center: ['84%', '28%'],
            radius: 44,
            // Tepi setebal batang, warna permukaan kartu. Pai ini digambar tepat
            // di atas batang, dan tanpa tepi potongannya menyatu dengan batang
            // di belakangnya sehingga lingkarannya berhenti terbaca sebagai satu
            // bidang yang terpisah.
            itemStyle: { borderColor: warna.value.latarTooltip, borderWidth: 2 },
            // Persen dibulatkan. `{d}` mencetak nilai mentah, dan "69.44%" di
            // dalam potongan selebar jempol hanya menambah angka yang tidak
            // dipakai siapa pun.
            label: {
                position: 'inside',
                formatter: ({ percent }: { percent: number }) => `${Math.round(percent)}%`,
                color: '#ffffff',
                fontSize: 10,
                fontWeight: 'bold' as const,
            },
            labelLayout: { hideOverlap: true },
            emphasis: { scale: false },
            tooltip: {
                trigger: 'item',
                backgroundColor: warna.value.latarTooltip,
                borderWidth: 0,
                padding: [8, 12],
                textStyle: { color: warna.value.teks, fontSize: 12 },
                extraCssText: 'border-radius:10px;box-shadow:0 12px 28px -12px rgb(0 0 0 / 0.25);',
                formatter: '{b}: <b>{c} berita</b> ({d}%)',
            },
            animationDuration: 500,
            data: [],
        },
    ],
}));

/**
 * Satu bingkai per periode.
 *
 * Deret kosong `{}` di posisi netral dan negatif disengaja. ECharts menggabung
 * `series` bingkai dengan `series` kerangka menurut urutan, jadi posisinya harus
 * tetap terisi supaya pai tidak bergeser ke indeks batang.
 */
const bingkai = computed(() =>
    props.data.map((b, i) => ({
        title: { text: total.value[i] ? `${tanggal.value[i]}, ${total.value[i]} berita berlabel` : `${tanggal.value[i]}, belum ada berita berlabel` },
        series: [
            {
                markArea: {
                    silent: true,
                    itemStyle: { color: gelap.value ? 'rgba(255,255,255,0.07)' : 'rgba(22,63,108,0.06)' },
                    // Indeks, bukan nama kategori. Rentang panjang bisa memuat
                    // dua label yang sama persis, misalnya "10 Agt" dari dua
                    // tahun berbeda, dan sorotannya akan mendarat di yang salah.
                    data: [[{ xAxis: i }, { xAxis: i }]],
                },
            },
            {},
            {},
            { data: deret.map((d) => ({ name: d.nama, value: b[d.kunci], itemStyle: { color: warnaSentimen.value[d.warna] } })) },
        ],
    })),
);

const opsi = computed(() => ({ baseOption: dasarOpsi.value, options: bingkai.value }));

const barisTabel = computed(() =>
    props.data.map((b, i) => [
        format(new Date(b.tanggal), props.satuan === 'bulanan' ? 'MMMM yyyy' : 'd MMM yyyy', { locale: id }),
        ...persen.value.map((deretPersen) => `${Math.round(deretPersen[i])}%`),
        total.value[i],
    ]),
);
</script>

<template>
    <BaseChart
        :judul="judul"
        :opsi="opsi"
        :tinggi="tinggi"
        :memuat="memuat"
        :kolom-tabel="[judulKolom, 'Positif', 'Netral', 'Negatif', 'Total berita']"
        :baris-tabel="barisTabel"
        :opsi-perbarui="{ notMerge: true }"
    />
</template>
