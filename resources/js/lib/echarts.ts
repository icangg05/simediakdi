/**
 * Registrasi modul ECharts yang benar-benar dipakai.
 *
 * Impor selektif, bukan `echarts` utuh: bundel penuh sekitar satu megabyte,
 * dan dashboard eksekutif harus selesai render di bawah dua detik pada koneksi
 * 4G. Tambahkan modul di sini saat ada jenis grafik baru, jangan beralih ke
 * impor utuh demi cepat.
 *
 * Berkas ini juga yang mengekspor komponennya, dan itu disengaja. `BaseChart`
 * memuatnya lewat satu impor dinamis, sehingga registrasi dan komponen jatuh
 * ke potongan berkas yang sama. Waktu keduanya diimpor terpisah, Rollup
 * menyalin inti ECharts ke dua potongan sekaligus, 599 kB untuk isi yang
 * seharusnya 330 kB.
 */
import { LineChart, PieChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import VChart from 'vue-echarts';

use([CanvasRenderer, LineChart, PieChart, GridComponent, TooltipComponent, LegendComponent]);

export default VChart;
