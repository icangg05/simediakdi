<script setup lang="ts">
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useFormatAngka } from '@/composables/useFormatAngka';
import { useElementVisibility } from '@vueuse/core';
import { ChartLine, Download, Table as TableIcon } from 'lucide-vue-next';
import { computed, defineAsyncComponent, ref, watchEffect } from 'vue';

/**
 * Renderer grafik dimuat terpisah, setelah halaman tampil.
 *
 * ECharts plus vue-echarts sekitar 540 kB terminifikasi. Dulu modulnya
 * diregistrasi di `app.ts`, jadi setiap halaman ikut membayarnya, termasuk
 * login, arsip berita, dan peringkat media yang tidak punya satu grafik pun.
 * Sekarang berkasnya hanya diunduh saat komponen ini benar benar dirender.
 */
const VChart = defineAsyncComponent(() => import('@/lib/echarts'));

/**
 * Pembungkus tunggal seluruh grafik.
 *
 * Setiap grafik lewat sini supaya keadaan kosong, keadaan memuat, unduh PNG,
 * dan tampilan tabel berperilaku sama di semua halaman. Yang paling penting
 * yang terakhir: tanpa "lihat sebagai tabel", grafik tidak bisa dibaca pembaca
 * layar maupun dipakai orang yang perlu angka persisnya.
 */
const props = defineProps<{
    opsi: Record<string, unknown>;
    judul: string;
    tinggi?: number;
    memuat?: boolean;
    /** Kolom tabel alternatif. Tanpa ini tombol tabel tidak ditampilkan. */
    kolomTabel?: string[];
    barisTabel?: Array<Array<string | number>>;
    keteranganKosong?: string;
    /**
     * Diteruskan ke `setOption` ECharts. Default gabung, dan itu benar untuk
     * hampir semua grafik: nilai lama bertahan sampai ditimpa, jadi ganti tema
     * tidak menyetel ulang grafiknya.
     *
     * Grafik bergaris waktu perlu `{ notMerge: true }`. Opsinya berisi larik
     * bingkai per periode, dan penggabungan hanya menimpa bingkai menurut
     * indeks. Rentang tanggal yang dipersempit menyisakan bingkai lama di ekor
     * larik, lengkap dengan periode yang sudah tidak ada di datanya.
     */
    opsiPerbarui?: Record<string, unknown>;
}>();

const { formatAngka } = useFormatAngka();

const modeTabel = ref(false);
const grafik = ref<{ getDataURL: (opsi: Record<string, unknown>) => string } | null>(null);

/*
 * Grafik di bawah lipatan layar tidak ikut diunduh sampai digulir ke sana.
 *
 * Di ponsel hampir semua grafik berada di bawah lipatan, dan mengunduh lalu
 * menjalankan 540 kB ECharts saat muat awal menahan utas utama tepat ketika
 * halaman sedang berusaha tampil. `sekaliTerlihat` sengaja tidak pernah kembali
 * ke false: begitu grafik pernah dilihat, ia tetap hidup supaya menggulir naik
 * turun tidak membongkar pasang kanvasnya.
 */
const bingkai = ref<HTMLElement | null>(null);
const terlihat = useElementVisibility(bingkai);
const sekaliTerlihat = ref(false);

watchEffect(() => {
    if (terlihat.value) sekaliTerlihat.value = true;
});

const kosong = computed(() => !props.barisTabel?.length);

function unduhPng() {
    const url = grafik.value?.getDataURL({ pixelRatio: 2, backgroundColor: '#fff' });

    if (!url) return;

    const tautan = document.createElement('a');
    tautan.href = url;
    tautan.download = `${props.judul.toLowerCase().replace(/\s+/g, '-')}.png`;
    tautan.click();
}
</script>

<template>
    <figure class="space-y-2">
        <figcaption class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-bold text-primary dark:text-aksen-biru">{{ judul }}</h3>

            <!--
                Pil berwarna, bukan tombol hantu abu-abu. Keduanya duduk di
                sebelah judul grafik dan tombol hantu di sana terbaca seperti
                keterangan, bukan sesuatu yang bisa diklik. Warnanya diambil
                dari palet aksen, bukan palet sentimen, karena tombol ini
                mengatur tampilan dan tidak menyatakan nada pemberitaan apa pun.
            -->
            <div v-if="!kosong" class="flex flex-wrap gap-1.5">
                <button
                    v-if="kolomTabel?.length"
                    type="button"
                    class="tekan inline-flex items-center gap-1.5 rounded-full bg-aksen-biru/10 px-3 py-1 text-xs font-semibold text-aksen-biru hover:bg-aksen-biru/20 focus-visible:ring-2 focus-visible:ring-aksen-biru/50 focus-visible:outline-hidden"
                    :aria-pressed="modeTabel"
                    @click="modeTabel = !modeTabel"
                >
                    <component :is="modeTabel ? ChartLine : TableIcon" class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ modeTabel ? 'Lihat sebagai grafik' : 'Lihat sebagai tabel' }}
                </button>
                <button
                    v-if="!modeTabel"
                    type="button"
                    class="tekan inline-flex items-center gap-1.5 rounded-full bg-aksen-toska/10 px-3 py-1 text-xs font-semibold text-aksen-toska hover:bg-aksen-toska/20 focus-visible:ring-2 focus-visible:ring-aksen-toska/50 focus-visible:outline-hidden"
                    @click="unduhPng"
                >
                    <Download class="h-3.5 w-3.5" aria-hidden="true" />
                    Unduh PNG
                </button>
            </div>
        </figcaption>

        <Skeleton v-if="memuat" :style="{ height: `${tinggi ?? 240}px` }" class="w-full" />

        <KeadaanKosong
            v-else-if="kosong"
            judul="Belum ada data pada rentang ini"
            :keterangan="keteranganKosong ?? 'Coba perlebar rentang tanggal, atau tunggu crawler mengumpulkan berita.'"
        />

        <div v-else-if="modeTabel" class="max-h-80 overflow-auto rounded-md border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead v-for="(kolom, i) in kolomTabel" :key="kolom" :class="i > 0 ? 'text-right' : ''">
                            {{ kolom }}
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="(baris, i) in barisTabel" :key="i">
                        <TableCell v-for="(sel, j) in baris" :key="j" :class="j > 0 ? 'angka text-right' : 'font-medium'">
                            {{ typeof sel === 'number' ? formatAngka(sel) : sel }}
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <!--
            Tingginya dipasang di pembungkus, bukan di grafiknya. Renderer datang
            belakangan lewat impor dinamis, dan tanpa ruang yang sudah dipesan
            di sini isi halaman di bawahnya akan melompat saat grafik muncul.
        -->
        <!--
            `contain: paint` mengurung gambar ulang kanvas di dalam kotaknya,
            sehingga perubahan di dalam grafik tidak pernah membatalkan tata
            letak halaman di sekitarnya.

            `autoresize` diberi throttle karena di ponsel bilah URL menyusut dan
            membesar sepanjang gulir. Setiap perubahan tinggi viewport memicu
            `resize()` ECharts, dan tanpa jeda itu berarti tata letak penuh
            berkali-kali dalam satu gulir.
        -->
        <div v-else ref="bingkai" :style="{ height: `${tinggi ?? 240}px`, contain: 'paint' }">
            <VChart
                v-if="sekaliTerlihat"
                ref="grafik"
                :option="opsi"
                :update-options="opsiPerbarui"
                :autoresize="{ throttle: 200 }"
                class="h-full w-full"
            />
        </div>
    </figure>
</template>
