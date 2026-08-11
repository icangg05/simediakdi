<script setup lang="ts">
import ChartDonatSentimen from '@/components/chart/ChartDonatSentimen.vue';
import ChartTrenSentimen from '@/components/chart/ChartTrenSentimen.vue';
import KartuArtikel from '@/components/domain/KartuArtikel.vue';
import PemilihRentangTanggal from '@/components/domain/PemilihRentangTanggal.vue';
import SentimenBelumTersedia from '@/components/domain/SentimenBelumTersedia.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useFormatAngka } from '@/composables/useFormatAngka';
import { useGerbangSentimen } from '@/composables/useGerbangSentimen';
import { usePeriodeEksekutif } from '@/composables/usePeriodeEksekutif';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import type { DeretTren } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { ArrowRight, Info, ThumbsUp, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';

interface Berita {
    id: number;
    judul: string;
    url: string;
    media: string | null;
    diambil_at: string;
    /** Alasan model atas label yang diberikannya. Kosong pada baris analisis lama. */
    ringkasan_ai: string | null;
}

const props = defineProps<{
    periode: { dari: string; sampai: string };
    kpi: {
        berlabel: number;
        negatif: number;
        netral: number;
        positif: number;
        negatif_persen: number;
    };
    deret: DeretTren;
    beritaNegatif: Berita[];
    beritaNetral: Berita[];
    beritaPositif: Berita[];
}>();

const { formatAngka, formatPersen } = useFormatAngka();
const { sentimenTersedia, alasanSentimen } = useGerbangSentimen();
const { pindah, kueri } = usePeriodeEksekutif(props.periode, '/eksekutif/sentimen');

/**
 * Rentang dalam kalimat, bukan dua tanggal ISO.
 *
 * Disalin bentuknya dari dashboard supaya kop kedua halaman terbaca sama.
 * Pimpinan berpindah antara keduanya lewat menu di kop, dan judul yang
 * bentuknya berbeda membuat perpindahan itu terasa seperti masuk aplikasi lain.
 */
const rentangTerbaca = computed(
    () =>
        `${format(new Date(props.periode.dari), 'd MMMM', { locale: id })} sampai ` +
        `${format(new Date(props.periode.sampai), 'd MMMM yyyy', { locale: id })}`,
);

/**
 * Legenda donat, dipindah keluar dari grafik menjadi daftar yang bisa diklik.
 *
 * Legenda bawaan ECharts hanya teks mati di bawah lingkaran, dan angkanya harus
 * dicari dengan menyorot potongannya satu per satu. Sebagai daftar, tiap nada
 * langsung menyebutkan jumlah dan porsinya, lalu membuka arsip yang sudah
 * tersaring ke nada itu. Itu memenuhi prinsip produk nomor dua: tiap angka di
 * layar punya jalan untuk dicek asalnya.
 */
const nada = computed(() =>
    [
        {
            kunci: 'positif',
            nama: 'Positif',
            arti: 'memberitakan hal baik',
            jumlah: props.kpi.positif,
            titik: 'bg-sentimen-positif',
        },
        {
            kunci: 'netral',
            nama: 'Netral',
            arti: 'menyampaikan informasi',
            jumlah: props.kpi.netral,
            titik: 'bg-sentimen-netral',
        },
        {
            kunci: 'negatif',
            nama: 'Negatif',
            arti: 'menyoroti masalah atau kritik',
            jumlah: props.kpi.negatif,
            titik: 'bg-sentimen-negatif',
        },
    ].filter((n) => n.jumlah > 0),
);

/**
 * Penyebutnya berita berlabel, penjumlahan tiga nada itu saja. Angka yang sama
 * dipakai donat di sebelahnya, jadi porsi di daftar ini selalu cocok dengan
 * besar potongan yang dilihat pembaca.
 */
function porsi(jumlah: number): number {
    return props.kpi.berlabel === 0 ? 0 : Math.round((jumlah / props.kpi.berlabel) * 100);
}

/**
 * Tiga daftar berita, satu per nada.
 *
 * Disusun sebagai data, bukan tiga blok markup yang saling menyalin. Ketiganya
 * berbeda hanya pada warna, judul, dan sumber datanya, dan menuliskannya tiga
 * kali berarti perbaikan pada satu kartu cepat atau lambat lupa diterapkan ke
 * dua lainnya.
 *
 * Urutannya positif, netral, negatif, mengikuti urutan yang sama dengan batang
 * komposisi dan legenda donat di halaman ini. Pembaca yang berpindah dari
 * grafik ke daftar menemukan nada pada posisi yang sama.
 */
const daftarNada = computed(() => [
    {
        kunci: 'positif' as const,
        judul: 'Berita bernada positif',
        ikon: ThumbsUp,
        berita: props.beritaPositif,
        kartu: 'border-sentimen-positif/25 bg-sentimen-positif-lembut/45',
        tile: 'bg-sentimen-positif',
        teks: 'text-sentimen-positif',
        jumlah: props.kpi.positif,
        kosong: 'Tidak ada berita bernada positif pada rentang ini.',
    },
    {
        kunci: 'netral' as const,
        judul: 'Berita bernada netral',
        ikon: Info,
        berita: props.beritaNetral,
        kartu: 'border-sentimen-netral/25 bg-sentimen-netral-lembut/45',
        tile: 'bg-sentimen-netral',
        teks: 'text-sentimen-netral',
        jumlah: props.kpi.netral,
        kosong: 'Tidak ada berita bernada netral pada rentang ini.',
    },
    {
        kunci: 'negatif' as const,
        judul: 'Berita bernada negatif',
        ikon: TriangleAlert,
        berita: props.beritaNegatif,
        kartu: 'border-sentimen-negatif/25 bg-sentimen-negatif-lembut/50',
        tile: 'bg-sentimen-negatif',
        teks: 'text-sentimen-negatif',
        jumlah: props.kpi.negatif,
        kosong: 'Tidak ada berita bernada negatif pada rentang ini.',
    },
]);
</script>

<template>
    <Head title="Analisis sentimen" />

    <LayoutEksekutif>
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-primary dark:text-aksen-biru">Analisis sentimen</h1>
                <p class="text-sm text-muted-foreground">Rincian nada pemberitaan tentang Pemerintah Kota, {{ rentangTerbaca }}</p>
            </div>

            <PemilihRentangTanggal :dari="periode.dari" :sampai="periode.sampai" inline @ubah="(dari, sampai) => pindah({ dari, sampai })" />
        </header>

        <SentimenBelumTersedia v-if="!sentimenTersedia" :alasan="alasanSentimen" />

        <template v-else>
            <!--
                Dua panel grafik, bobotnya tidak sama. Tren menjawab "berubah ke
                mana", komposisi menjawab "sekarang seperti apa", dan pertanyaan
                pertama yang lebih sering dibawa pembaca ke halaman ini. Karena
                itu tren mendapat dua kolom dan komposisi satu.
            -->
            <div class="grid gap-4 lg:grid-cols-3">
                <!--
                    Kedua kartu grafik tidak memakai kepala bertinta seperti tiga
                    kartu daftar di bawahnya. Judulnya sudah dicetak BaseChart
                    sebaris dengan lencana "lihat sebagai tabel" dan "unduh",
                    dan menambah kepala kartu di atasnya menghasilkan dua judul
                    untuk satu grafik yang sama. Perbedaan bentuk itu sekaligus
                    memberi halaman irama: dua panel analisis di atas, tiga panel
                    daftar di bawah.
                -->
                <Card class="muncul overflow-hidden lg:col-span-2" style="animation-delay: 60ms">
                    <CardContent class="space-y-3 p-4">
                        <ChartTrenSentimen judul="Perubahan dari waktu ke waktu" :data="deret.baris as never" :satuan="deret.satuan" :tinggi="280" />
                        <p class="text-xs text-muted-foreground">
                            Warnanya ditumpuk, jadi tinggi seluruh tumpukan berarti jumlah berita berlabel pada titik itu.
                        </p>
                    </CardContent>
                </Card>

                <Card class="muncul overflow-hidden" style="animation-delay: 120ms">
                    <CardContent class="space-y-3 p-4">
                        <ChartDonatSentimen :negatif="kpi.negatif" :netral="kpi.netral" :positif="kpi.positif" :tinggi="220" />

                        <!--
                            Legenda yang bisa dibuka, menggantikan legenda mati
                            bawaan grafik. Tiap baris membawa jumlah dan porsinya
                            sendiri, jadi angkanya tidak perlu dicari dengan
                            menyorot potongan donat satu per satu.
                        -->
                        <ul v-if="nada.length" class="divide-y rounded-xl border">
                            <li v-for="n in nada" :key="n.kunci">
                                <Link
                                    :href="`/eksekutif/berita?${kueri({ sentimen: n.kunci })}`"
                                    class="tekan group flex items-center gap-3 px-3 py-2.5 hover:bg-muted"
                                >
                                    <span :class="n.titik" class="h-2.5 w-2.5 shrink-0 rounded-full" aria-hidden="true"></span>

                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium">{{ n.nama }}</span>
                                        <span class="block truncate text-xs text-muted-foreground">{{ n.arti }}</span>
                                    </span>

                                    <span class="shrink-0 text-right">
                                        <span class="angka block text-sm font-semibold leading-tight">{{ formatAngka(n.jumlah) }}</span>
                                        <span class="angka block text-xs text-muted-foreground">{{ porsi(n.jumlah) }} dari 100</span>
                                    </span>

                                    <ArrowRight
                                        class="ease-[cubic-bezier(0.32,0.72,0,1)] h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-300 group-hover:translate-x-1"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </li>
                        </ul>

                        <p v-else class="rounded-xl border border-dashed px-3 py-6 text-center text-sm text-muted-foreground">
                            Belum ada berita yang selesai dianalisis pada rentang ini.
                        </p>
                    </CardContent>
                </Card>
            </div>

            <!--
                Tiga baris, satu per nada, masing-masing selebar halaman.

                Sebelumnya ketiga nada berdampingan sebagai tiga kolom sempit,
                dan pada lebar itu ringkasan model terpotong di baris kedua
                sehingga kalimatnya berhenti sebelum menjelaskan apa pun. Satu
                nada satu baris memberi tiap berita selebar halaman, dan
                ringkasannya utuh.

                Seluruh kartu diberi warna nada isinya, bukan hanya kepalanya,
                supaya tiga blok yang bertumpuk tidak terbaca sebagai satu
                daftar panjang.
            -->
            <template v-for="(d, urutan) in daftarNada" :key="d.kunci">
                <Card :class="d.kartu" class="muncul overflow-hidden" :style="{ animationDelay: `${180 + urutan * 60}ms` }">
                    <CardHeader class="flex-row items-start justify-between gap-3 py-3.5">
                        <CardTitle :class="d.teks" class="flex items-center gap-2.5 text-base">
                            <span
                                :class="d.tile"
                                class="grid h-8 w-8 shrink-0 place-items-center rounded-xl text-white shadow-sm dark:text-background"
                            >
                                <component :is="d.ikon" class="h-[18px] w-[18px]" aria-hidden="true" />
                            </span>
                            {{ d.judul }}
                        </CardTitle>

                        <!--
                            Hitungan di lencana adalah jumlah seluruh berita
                            bernada itu pada rentangnya, bukan jumlah baris yang
                            tampil. Daftarnya dibatasi sepuluh, dan tanpa angka
                            ini pembaca akan mengira sepuluh itulah seluruhnya.
                        -->
                        <span
                            v-if="d.jumlah > 0"
                            :class="d.tile"
                            class="angka shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold text-white dark:text-background"
                        >
                            {{ formatAngka(d.jumlah) }}
                            <template v-if="d.kunci === 'negatif'">&middot; {{ formatPersen(kpi.negatif_persen) }}</template>
                        </span>
                    </CardHeader>

                    <CardContent class="space-y-3">
                        <!--
                            Lini masa, bentuk yang sama dengan kartu berita
                            terbaru di dashboard. Garis menurun dengan titik
                            menyatakan urutan waktu sebelum satu keterangan
                            tanggal pun dibaca, dan pembaca yang datang dari
                            dashboard menemukan bentuk yang sudah dikenalnya.

                            Titiknya tidak perlu diberi warna per baris seperti
                            di dashboard. Seluruh isi kartu ini satu nada, dan
                            warnanya sudah dinyatakan kepala kartu, tepi, serta
                            latarnya. Lencana sentimen per baris juga dilepas
                            karena alasan yang sama.

                            Tingginya dibatasi dan sisanya digulir. Tiga daftar
                            berisi sepuluh berita yang ditumpuk penuh membuat
                            halaman ini panjang sekali, sedangkan yang dicari
                            pembaca biasanya beberapa baris teratas tiap nada.
                        -->
                        <div v-if="d.berita.length" class="max-h-[22rem] overflow-y-auto pr-1">
                            <ol class="relative">
                                <span class="absolute bottom-4 left-[5px] top-4 w-px bg-border" aria-hidden="true"></span>

                                <li v-for="b in d.berita" :key="b.id" class="tekan relative rounded-lg py-1 pl-7 pr-2 hover:bg-background/60">
                                    <span :class="d.tile" class="absolute left-0 top-[18px] h-2.5 w-2.5 rounded-full" aria-hidden="true"></span>

                                    <KartuArtikel
                                        :judul="b.judul"
                                        :url="b.url"
                                        :media="b.media"
                                        :diambil-at="b.diambil_at"
                                        :ringkasan-ai="b.ringkasan_ai"
                                        :label="d.kunci"
                                    />
                                </li>
                            </ol>
                        </div>

                        <p v-else class="rounded-xl border border-dashed px-3 py-8 text-center text-sm text-muted-foreground">
                            {{ d.kosong }}
                        </p>

                        <!--
                            Tautan ke arsip hanya muncul kalau memang ada yang
                            tidak tertampung. Tombol "lihat semua" di bawah
                            daftar yang sudah lengkap hanya menambah satu
                            keputusan tanpa menambah satu pun berita.
                        -->
                        <Link
                            v-if="d.jumlah > d.berita.length"
                            :href="`/eksekutif/berita?${kueri({ sentimen: d.kunci })}`"
                            :class="d.teks"
                            class="tekan group inline-flex items-center gap-1.5 rounded-full bg-background/70 px-3 py-1.5 text-xs font-semibold"
                        >
                            Lihat {{ formatAngka(d.jumlah) }} berita lengkapnya
                            <ArrowRight
                                class="ease-[cubic-bezier(0.32,0.72,0,1)] h-3 w-3 transition-transform duration-300 group-hover:translate-x-1"
                                aria-hidden="true"
                            />
                        </Link>
                    </CardContent>
                </Card>
            </template>
        </template>
    </LayoutEksekutif>
</template>
