/**
 * Registrasi modul ECharts yang benar-benar dipakai.
 *
 * Impor selektif, bukan `echarts` utuh: bundel penuh sekitar satu megabyte,
 * dan dashboard eksekutif harus selesai render di bawah dua detik pada koneksi
 * 4G. Tambahkan modul di sini saat ada jenis grafik baru, jangan beralih ke
 * impor utuh demi cepat.
 */
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import { DataZoomComponent, GridComponent, LegendComponent, TitleComponent, TooltipComponent } from 'echarts/components';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';

use([CanvasRenderer, LineChart, BarChart, PieChart, GridComponent, TooltipComponent, LegendComponent, TitleComponent, DataZoomComponent]);
