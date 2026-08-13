/**
 * Registrasi modul ECharts yang benar-benar dipakai.
 *
 * Impor selektif, bukan `echarts` utuh: bundel penuh sekitar satu megabyte,
 * dan dashboard eksekutif harus selesai render di bawah dua detik pada koneksi
 * 4G. Tambahkan modul di sini saat ada jenis grafik baru, jangan beralih ke
 * impor utuh demi cepat.
 *
 * Lupa mendaftar gagal tanpa suara. ECharts melewatkan series bertipe tidak
 * dikenal tanpa melempar error, tanpa peringatan konsol, dan tanpa memengaruhi
 * build. Yang terlihat hanya kartu grafik kosong yang legendanya ikut hilang,
 * lengkap dengan sumbu yang tergambar rapi. `BarChart` sempat lolos begitu.
 *
 * Berkas ini juga yang mengekspor komponennya, dan itu disengaja. `BaseChart`
 * memuatnya lewat satu impor dinamis, sehingga registrasi dan komponen jatuh
 * ke potongan berkas yang sama. Waktu keduanya diimpor terpisah, Rollup
 * menyalin inti ECharts ke dua potongan sekaligus, 599 kB untuk isi yang
 * seharusnya 330 kB.
 */
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import {
    DataZoomComponent,
    GridComponent,
    LegendComponent,
    MarkAreaComponent,
    TimelineComponent,
    TitleComponent,
    TooltipComponent,
} from 'echarts/components';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import VChart from 'vue-echarts';

/*
 * `DataZoomComponent` mencakup penggeser dan zoom di dalam kanvas sekaligus,
 * jadi tidak perlu mendaftarkan `DataZoomSliderComponent` terpisah.
 *
 * Tiga terakhir dipakai bersama oleh grafik porsi sentimen: `TimelineComponent`
 * untuk animasi antar periode, `TitleComponent` untuk label periode yang
 * berganti tiap bingkai, dan `MarkAreaComponent` untuk sorotan batang periode
 * yang sedang diputar.
 */
use([
    CanvasRenderer,
    LineChart,
    BarChart,
    PieChart,
    GridComponent,
    TooltipComponent,
    LegendComponent,
    DataZoomComponent,
    TimelineComponent,
    TitleComponent,
    MarkAreaComponent,
]);

export default VChart;
