<script setup lang="ts">
import BaseChart from '@/components/chart/BaseChart.vue';
import { useTemaChart } from '@/composables/useTemaChart';
import type { DeretMedia, SatuanDeret } from '@/types';
import { useMediaQuery } from '@vueuse/core';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { computed } from 'vue';

/**
 * Nada tiap media, satu periode pada satu waktu.
 *
 * Sumbu mendatarnya nama media dan tidak pernah berubah. Yang berubah tinggi
 * batangnya, mengikuti periode yang sedang disorot garis waktu di dasar kartu.
 * Susunan itu menjawab pertanyaan yang tidak bisa dijawab grafik tren di
 * sebelahnya: bukan "berita negatif naik atau turun", tapi "naiknya di media
 * yang mana".
 *
 * Sumbu tegaknya dikunci ke batang tertinggi sepanjang rentang, bukan
 * disesuaikan ulang tiap periode. Sumbu yang ikut menyusut membuat periode
 * sepi tergambar setinggi periode ramai, dan animasinya berhenti menyatakan
 * apa pun.
 */
const props = withDefaults(defineProps<{ deret: DeretMedia; tinggi?: number; memuat?: boolean; judul?: string }>(), {
    judul: 'Sentimen tiap media',
});

const { warnaSentimen, dasar, warna, sumbuKategori, gelap } = useTemaChart();

/**
 * Di layar ponsel grafik ini bukan sekadar mengecil, susunannya harus berbeda.
 *
 * Kartunya selebar layar, jadi lebar kanvas praktis sama dengan lebar viewport
 * dan `useMediaQuery` cukup, tidak perlu mengukur elemennya. Ambangnya 640
 * piksel, sama dengan ambang `sm` Tailwind, supaya titik patah grafik dan titik
 * patah tata letak halaman tidak pernah berbeda.
 *
 * Yang berubah pada layar sempit, semuanya karena ruang mendatar habis:
 *
 * - Media dipotong menjadi enam teramai. Dua belas nama pada lebar 390 piksel
 *   berarti batang setipis dua piksel dan nama yang saling tindih.
 * - Legenda turun ke barisnya sendiri di bawah judul periode. Berdampingan,
 *   keduanya bertabrakan dan terbaca sebagai satu baris huruf yang kacau.
 * - Pai porsi disembunyikan. Ia menumpang di atas batang, dan pada lebar itu
 *   yang ditumpanginya sepertiga grafik.
 * - Penggeser jumlah di kanan dilepas. Menyeret pita selebar 14 piksel dengan
 *   jari tidak pernah berhasil, dan cubit dua jari sudah menggantikannya.
 */
const sempit = useMediaQuery('(max-width: 640px)');

/**
 * Media yang benar-benar digambar, dan angkanya ikut dipotong sepanjang ini.
 *
 * Daftar dari server sudah urut dari yang paling banyak menerbitkan, jadi
 * memotong ekornya berarti membuang yang paling sepi, bukan memilih acak.
 */
const media = computed(() => (sempit.value ? props.deret.media.slice(0, 6) : props.deret.media));

const polaLabel: Record<SatuanDeret, string> = {
    harian: 'd MMM',
    mingguan: 'd MMM',
    dua_mingguan: 'd MMM',
    bulanan: 'MMM yyyy',
};

const namaPeriode: Record<SatuanDeret, string> = {
    harian: 'Tanggal',
    mingguan: 'Pekan mulai',
    dua_mingguan: 'Dua pekan mulai',
    bulanan: 'Bulan',
};

const tanggal = computed(() => props.deret.baris.map((b) => format(new Date(b.tanggal), polaLabel[props.deret.satuan], { locale: id })));

const nada = [
    { kunci: 'positif', nama: 'Positif' },
    { kunci: 'netral', nama: 'Netral' },
    { kunci: 'negatif', nama: 'Negatif' },
] as const;

/** Jumlah berita satu media pada satu periode, ketiga nada dijumlahkan. */
function totalMedia(baris: DeretMedia['baris'][number], kolom: number): number {
    return baris.positif[kolom] + baris.netral[kolom] + baris.negatif[kolom];
}

/**
 * Batang tertinggi sepanjang rentang, jadi batas sumbu tegak.
 *
 * Dihitung dari media yang benar-benar digambar saja. Kalau ekor daftar yang
 * disembunyikan di layar ponsel ikut dihitung, sumbunya bisa disiapkan untuk
 * batang yang tidak pernah muncul.
 *
 * Satu supaya sumbunya tetap punya skala saat belum ada berita berlabel sama
 * sekali. Jendela nol sampai nol membuat ECharts menggambar sumbu tanpa angka.
 */
const puncak = computed(() => Math.max(1, ...props.deret.baris.flatMap((b) => nada.flatMap((n) => b[n.kunci].slice(0, media.value.length)))));

/** Porsi seluruh media pada satu periode, isi pai di kanan atas. */
const porsi = computed(() =>
    props.deret.baris.map((b) => nada.map((n) => ({ ...n, jumlah: b[n.kunci].reduce((jumlah, angka) => jumlah + angka, 0) }))),
);

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
 * Warnanya tinta netral, bukan warna nada. Penggeser adalah alat, bukan data,
 * dan memberinya hijau atau merah membuat pembaca menyangka ia menyatakan
 * sesuatu tentang pemberitaan.
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
 * Sumbu, penggeser, dan bentuk deret didefinisikan sekali di sini. Tiap bingkai
 * di `options` hanya mengirim angka periodenya, jadi opsinya tidak menggandakan
 * sumbu dan gaya sebanyak jumlah periode.
 */
const dasarOpsi = computed(() => ({
    ...dasar.value,
    title: { left: 'center', top: 0, textStyle: { color: warna.value.teksSamar, fontSize: 12, fontWeight: 'normal' as const } },
    /*
     * Legenda turun ke barisnya sendiri di layar sempit.
     *
     * Bawaannya rata kanan sebaris dengan judul periode, dan itu benar selama
     * ada ruang. Pada lebar ponsel judulnya sendiri hampir selebar kartu, jadi
     * keduanya saling menimpa persis di tengah.
     */
    legend: sempit.value ? { ...dasar.value.legend, top: 20, left: 'center', right: 'auto', itemGap: 12 } : dasar.value.legend,
    // Ruang bawah menampung nama media yang dimiringkan sekaligus garis waktu,
    // ruang kanan menampung penggeser jumlah.
    grid: { ...dasar.value.grid, top: sempit.value ? 62 : 44, right: sempit.value ? 8 : 34, bottom: sempit.value ? 96 : 100 },
    timeline: {
        axisType: 'category',
        data: tanggal.value,
        // Diam di periode terbaru kalau animasi dimatikan, karena itu yang
        // paling sering dicari. Saat berjalan, mulai dari yang paling lama.
        currentIndex: kurangiGerak ? props.deret.baris.length - 1 : 0,
        autoPlay: !kurangiGerak,
        playInterval: 1800,
        loop: true,
        left: sempit.value ? 40 : 52,
        right: sempit.value ? 16 : 28,
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
        label: { color: warna.value.teksSamar, fontSize: sempit.value ? 9 : 10 },
    },
    tooltip: {
        ...dasar.value.tooltip,
        axisPointer: { type: 'shadow' },
        valueFormatter: undefined,
        formatter: (titik: Array<{ dataIndex: number; marker: string; seriesName: string; value: number; name: string }>) => {
            const total = titik.reduce((jumlah, t) => jumlah + t.value, 0);

            if (!total) return `${titik[0].name}<br>Tidak menerbitkan berita berlabel pada periode ini`;

            const baris = titik.map((t) => `${t.marker} ${t.seriesName}: <b>${t.value}</b>`).join('<br>');

            return `${titik[0].name}<br>${baris}<br><span style="opacity:.7">Total ${total} berita</span>`;
        },
    },
    xAxis: {
        ...sumbuKategori.value,
        boundaryGap: true,
        data: media.value,
        /*
         * Semua nama dicetak, tidak ada yang dilewati. Sumbu media yang
         * melewatkan satu dari dua nama membuat pembaca membaca batang milik
         * media di sebelahnya. Konsekuensinya nama harus dimiringkan dan yang
         * kepanjangan dipotong, dan itu ditebus tooltip yang menyebut nama
         * lengkapnya.
         */
        axisLabel: {
            color: warna.value.teksSamar,
            interval: 0,
            rotate: sempit.value ? 45 : 32,
            fontSize: sempit.value ? 9 : 10,
            width: sempit.value ? 58 : 84,
            overflow: 'truncate' as const,
        },
    },
    yAxis: {
        type: 'value',
        axisLine: { show: false },
        axisTick: { show: false },
        // Jumlah berita selalu bilangan bulat. Tanpa ini, periode yang batang
        // tertingginya tiga diberi sumbu 0, 0,5, 1, 1,5, dan setengah berita
        // adalah satuan yang tidak ada.
        minInterval: 1,
        splitLine: { lineStyle: { color: warna.value.garis, type: 'dashed' } },
        axisLabel: { color: warna.value.teksSamar, formatter: (nilai: number) => new Intl.NumberFormat('id-ID').format(nilai) },
    },
    /*
     * Jendela awal nol sampai batang tertinggi, dipatok lewat dataZoom dan
     * bukan lewat `yAxis.max`. Sumbu yang punya `max` sendiri mengabaikan
     * rentang dari dataZoom, dan penggesernya jadi bisa ditarik tanpa mengubah
     * apa pun.
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
            // Geser dengan menyeret dimatikan supaya satu jari di atas grafik
            // tetap menggulir halaman. Di ponsel, cubit dua jari yang menzoom.
            moveOnMouseMove: false,
        },
        // Penggeser jumlah hanya di layar lebar. Pita selebar 14 piksel tidak
        // bisa ditarik dengan jari, dan pada lebar ponsel ia memakan ruang yang
        // dibutuhkan batangnya.
        ...(sempit.value
            ? []
            : [
                  {
                      ...gayaZoom.value,
                      type: 'slider',
                      yAxisIndex: 0,
                      filterMode: 'none',
                      startValue: 0,
                      endValue: puncak.value,
                      width: 14,
                      right: 8,
                      top: 44,
                      bottom: 100,
                  },
              ]),
    ],
    series: [
        ...nada.map((n, urutan) => ({
            name: n.nama,
            type: 'bar',
            /*
             * Lebar dan rapat, sengaja mengisi hampir seluruh slot medianya.
             *
             * Tiga batang berdampingan berarti dua dari tiga slot kosong pada
             * media yang hanya menerbitkan berita positif, dan itu sudah cukup
             * banyak ruang kosong. Menambahkan jarak antar batang di atas itu
             * membuat kelompoknya berhenti terbaca sebagai satu media dan
             * berubah jadi lidi-lidi berjauhan.
             *
             * `barMaxWidth` hanya batas atas. Pada kartu sempit ECharts
             * menyempitkan sendiri batangnya, jadi angka ini tidak pernah
             * membuat kelompok batang melebihi slotnya.
             */
            barMaxWidth: 30,
            barGap: '2%',
            barCategoryGap: '16%',
            itemStyle: { color: warnaSentimen.value[n.kunci] },
            emphasis: { focus: 'series' },
            animationDuration: 600,
            animationDelay: urutan * 80,
            animationEasing: 'cubicOut',
            data: [],
        })),
        /*
         * Pai periode terpilih, menumpang di atas batang seperti pada contoh
         * "Finance Indices" milik ECharts. Isinya seluruh media digabung, jadi
         * ia menjawab porsi nada periode itu secara keseluruhan, sementara
         * batang di bawahnya menjawab pembagiannya per media.
         *
         * Tidak digambar di layar sempit. Menumpang di atas batang hanya bisa
         * diterima selama yang ditumpangi sedikit, dan pada lebar ponsel
         * lingkaran ini menutupi sepertiga grafiknya. Angka porsinya tidak
         * hilang: donat komposisi di halaman yang sama tetap menyebutkannya.
         *
         * Tooltipnya dipasang di deret, bukan di akar opsi. Akar sudah dipakai
         * tooltip sumbu untuk batang, dan pai tidak punya sumbu.
         */
        ...(sempit.value
            ? []
            : [
                  {
                      name: 'Porsi periode',
                      type: 'pie',
                      center: ['86%', '26%'],
                      radius: 42,
                      itemStyle: { borderColor: warna.value.latarTooltip, borderWidth: 2 },
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
              ]),
    ],
}));

/**
 * Satu bingkai per periode, isinya hanya angka yang berubah.
 *
 * Jumlah deret di bingkai harus sama persis dengan jumlah deret di kerangka,
 * karena ECharts menggabungkannya menurut urutan. Karena itu pai di sini
 * mengikuti syarat yang sama dengan pai di kerangka, tidak ada satu pun yang
 * boleh muncul sendirian.
 */
const bingkai = computed(() =>
    props.deret.baris.map((b, i) => {
        const total = porsi.value[i].reduce((jumlah, p) => jumlah + p.jumlah, 0);

        return {
            title: { text: total ? `${tanggal.value[i]}, ${total} berita berlabel` : `${tanggal.value[i]}, belum ada berita berlabel` },
            series: [
                ...nada.map((n) => ({ data: b[n.kunci].slice(0, media.value.length) })),
                ...(sempit.value
                    ? []
                    : [{ data: porsi.value[i].map((p) => ({ name: p.nama, value: p.jumlah, itemStyle: { color: warnaSentimen.value[p.kunci] } })) }]),
            ],
        };
    }),
);

const opsi = computed(() => ({ baseOption: dasarOpsi.value, options: bingkai.value }));

/**
 * Satu baris per media per periode, media yang diam pada periode itu dilewati.
 *
 * Tabel ini satu-satunya jalan membaca grafik bagi pembaca layar dan bagi siapa
 * pun yang butuh angka persisnya, karena animasinya hanya bisa diikuti dengan
 * mata dan pai porsinya hanya bisa dibaca dengan tetikus.
 */
const barisTabel = computed(() =>
    props.deret.baris.flatMap((b) =>
        props.deret.media
            .map((media, kolom) => ({ media, kolom }))
            .filter(({ kolom }) => totalMedia(b, kolom) > 0)
            .map(({ media, kolom }) => [
                format(new Date(b.tanggal), props.deret.satuan === 'bulanan' ? 'MMMM yyyy' : 'd MMM yyyy', { locale: id }),
                media,
                b.positif[kolom],
                b.netral[kolom],
                b.negatif[kolom],
                totalMedia(b, kolom),
            ]),
    ),
);
</script>

<template>
    <!--
        Lebih pendek di ponsel. Kartunya selebar layar dan grafik setinggi 440
        piksel di sana menghabiskan hampir seluruh layar, jadi keterangan di
        bawahnya tidak pernah terlihat bersamaan dengan grafiknya.
    -->
    <BaseChart
        :judul="judul"
        :opsi="opsi"
        :tinggi="sempit ? 380 : tinggi"
        :memuat="memuat"
        :kolom-tabel="[namaPeriode[deret.satuan], 'Media', 'Positif', 'Netral', 'Negatif', 'Total berita']"
        :baris-tabel="barisTabel"
        :opsi-perbarui="{ notMerge: true }"
        keterangan-kosong="Belum ada media yang menerbitkan berita berlabel pada rentang ini. Coba perlebar rentang tanggal."
    />
</template>
