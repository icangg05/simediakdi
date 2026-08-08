<script setup lang="ts">
import ChartDonatSentimen from '@/components/chart/ChartDonatSentimen.vue';
import ChartTrenSentimen from '@/components/chart/ChartTrenSentimen.vue';
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import KartuArtikel from '@/components/domain/KartuArtikel.vue';
import KartuKpi from '@/components/domain/KartuKpi.vue';
import PemilihRentangTanggal from '@/components/domain/PemilihRentangTanggal.vue';
import SentimenBelumTersedia from '@/components/domain/SentimenBelumTersedia.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useFormatAngka } from '@/composables/useFormatAngka';
import { useGerbangSentimen } from '@/composables/useGerbangSentimen';
import { usePeriodeEksekutif } from '@/composables/usePeriodeEksekutif';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import type { DeretTren, LabelSentimen } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    periode: { dari: string; sampai: string };
    kpi: {
        berlabel: number;
        berlabel_selisih: number;
        artikel: number;
        cakupan_persen: number;
        negatif: number;
        negatif_selisih: number;
        negatif_persen: number;
        positif: number;
        positif_selisih: number;
        netral: number;
        perlu_review: number;
        media_aktif: number;
    };
    deret: DeretTren;
    isuTeratas: Array<{
        istilah: string;
        jumlah_artikel: number;
        skor_lonjakan: number | null;
        sentimen_dominan: LabelSentimen | null;
    }>;
    peringkatMedia: Array<{
        id: number;
        nama: string;
        jumlah_artikel: number;
        jumlah_negatif: number;
    }>;
    beritaTerbaru: Array<{
        id: number;
        judul: string;
        url: string;
        media: string | null;
        diambil_at: string;
        label: LabelSentimen | null;
        perlu_review: boolean;
    }>;
    peringatan: { jumlah: number; terbaru: string; dipicu_at: string } | null;
}>();

const { formatAngka, formatPersen } = useFormatAngka();
const { pindah, kueri } = usePeriodeEksekutif(props.periode, '/eksekutif');

/**
 * Sentimen tidak tersedia selama GEMINI_API_KEY belum diisi.
 *
 * Sejak angka utama panel ini adalah berita yang sudah berlabel, seluruh isinya
 * bergantung pada model. Halaman menjadi hampir kosong dalam keadaan itu, dan
 * itu benar. Angka nol dibaca sebagai "tidak ada berita negatif", dan itu
 * pernyataan yang tidak dimiliki siapa pun.
 */
const { sentimenTersedia, alasanSentimen } = useGerbangSentimen();

/**
 * Kartu negatif berubah latar saat proporsinya melewati 40%. Perubahan halus,
 * bukan merah menyala dan bukan berkedip, sudah cukup menarik perhatian tanpa
 * terasa mengancam.
 */
const negatifMenonjol = computed(() => props.kpi.negatif_persen > 40);

const rentangTerbaca = computed(
    () =>
        `${format(new Date(props.periode.dari), 'd MMMM', { locale: id })} - ` +
        `${format(new Date(props.periode.sampai), 'd MMMM yyyy', { locale: id })}`,
);

const namaSatuan = computed(() => ({ harian: 'per hari', mingguan: 'per pekan', bulanan: 'per bulan' })[props.deret.satuan]);

/**
 * Bar isu digambar relatif terhadap isu teratas, bukan terhadap total berita.
 * Perbandingan antar isu yang jadi pertanyaan, bukan porsinya dari keseluruhan.
 */
const puncakIsu = computed(() => Math.max(1, ...props.isuTeratas.map((i) => i.jumlah_artikel)));
const puncakMedia = computed(() => Math.max(1, ...props.peringkatMedia.map((m) => m.jumlah_artikel)));
</script>

<template>
    <Head title="Ringkasan eksekutif" />

    <LayoutEksekutif>
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Pantauan Media Kota Kendari</h1>
                <p class="text-sm text-muted-foreground">{{ rentangTerbaca }}</p>
            </div>

            <PemilihRentangTanggal :dari="periode.dari" :sampai="periode.sampai" inline @ubah="(dari, sampai) => pindah({ dari, sampai })" />
        </header>

        <SentimenBelumTersedia v-if="!sentimenTersedia" :alasan="alasanSentimen" />

        <template v-else>
            <!--
                Angka utama adalah berita yang relevan dan sudah berlabel, bukan
                seluruh hasil crawl. Cakupan analisisnya ditulis di bawahnya
                supaya tidak ada yang mengira seluruh berita sudah terbaca.
            -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Card class="col-span-2 lg:col-span-1">
                    <CardContent class="p-4">
                        <p class="text-[13px] font-medium text-muted-foreground">Berita relevan berlabel</p>
                        <p class="angka mt-1 text-4xl font-semibold lg:text-5xl">{{ formatAngka(kpi.berlabel) }}</p>
                        <p v-if="kpi.berlabel_selisih !== 0" class="mt-1 text-xs text-muted-foreground">
                            {{ kpi.berlabel_selisih > 0 ? 'naik' : 'turun' }}
                            {{ formatAngka(Math.abs(kpi.berlabel_selisih)) }} dari periode sebelumnya
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ formatPersen(kpi.cakupan_persen) }} dari {{ formatAngka(kpi.artikel) }} berita terpantau
                        </p>
                    </CardContent>
                </Card>

                <Card :class="negatifMenonjol ? 'bg-sentimen-negatif-lembut' : ''">
                    <CardContent class="p-4">
                        <p class="text-[13px] font-medium text-muted-foreground">Sentimen negatif</p>
                        <p class="angka mt-1 text-4xl font-semibold">{{ formatAngka(kpi.negatif) }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">{{ formatPersen(kpi.negatif_persen) }} dari berita berlabel</p>
                        <p v-if="kpi.negatif_selisih !== 0" class="text-xs text-muted-foreground">
                            {{ kpi.negatif_selisih > 0 ? 'naik' : 'turun' }}
                            {{ formatAngka(Math.abs(kpi.negatif_selisih)) }} dari periode sebelumnya
                        </p>
                    </CardContent>
                </Card>

                <KartuKpi label="Sentimen positif" :nilai="kpi.positif" :selisih="kpi.positif_selisih" />
                <KartuKpi label="Media aktif memuat" :nilai="kpi.media_aktif" />
            </div>

            <!-- Hanya dirender kalau ada isinya. -->
            <Card v-if="peringatan" class="border-sentimen-negatif/30 bg-sentimen-negatif-lembut">
                <CardContent class="flex items-start gap-3 p-4">
                    <TriangleAlert class="mt-0.5 h-5 w-5 shrink-0 text-sentimen-negatif" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-sentimen-negatif">
                            {{ formatAngka(peringatan.jumlah) }} peringatan belum dibaca dalam 24 jam terakhir
                        </p>
                        <p class="truncate text-xs text-muted-foreground">{{ peringatan.terbaru }}</p>
                        <Link :href="`/eksekutif/sentimen?${kueri()}`" class="text-xs underline"> Lihat analisis sentimen </Link>
                    </div>
                </CardContent>
            </Card>

            <div class="grid gap-4 lg:grid-cols-3">
                <Card class="lg:col-span-2">
                    <CardContent class="p-4">
                        <p class="mb-1 text-xs text-muted-foreground">Dikelompokkan {{ namaSatuan }}</p>
                        <ChartTrenSentimen :data="deret.baris as never" :satuan="deret.satuan" :tinggi="260" />
                    </CardContent>
                </Card>
                <Card>
                    <CardContent class="p-4">
                        <ChartDonatSentimen
                            :negatif="kpi.negatif"
                            :netral="kpi.netral"
                            :positif="kpi.positif"
                            :perlu-review="kpi.perlu_review"
                            :tinggi="260"
                        />
                    </CardContent>
                </Card>
            </div>

            <!--
                Isu hangat adalah bagian yang paling menjelaskan kondisi
                pemerintah kota. Tiap baris membawa tiga hal sekaligus: seberapa
                banyak dibicarakan, seberapa cepat naik, dan bernada apa.
            -->
            <div class="grid gap-4 lg:grid-cols-3">
                <Card class="lg:col-span-2">
                    <CardHeader class="flex-row items-center justify-between pb-2">
                        <CardTitle class="text-base">Isu yang paling dibicarakan</CardTitle>
                        <Link :href="`/eksekutif/isu?${kueri()}`" class="text-xs underline">Semua isu</Link>
                    </CardHeader>
                    <CardContent>
                        <ul v-if="isuTeratas.length" class="space-y-2.5">
                            <li v-for="isu in isuTeratas" :key="isu.istilah">
                                <Link
                                    :href="`/eksekutif/berita?${kueri({ istilah: isu.istilah })}`"
                                    class="block rounded-md px-1.5 py-1 hover:bg-muted"
                                >
                                    <div class="flex items-center gap-3">
                                        <span class="min-w-0 flex-1 truncate text-sm font-medium capitalize">
                                            {{ isu.istilah }}
                                        </span>
                                        <span
                                            v-if="(isu.skor_lonjakan ?? 0) >= 2"
                                            class="angka shrink-0 rounded bg-sentimen-review-lembut px-1.5 py-0.5 text-xs font-medium text-sentimen-review"
                                            title="Naik dibanding periode sebelumnya"
                                        >
                                            {{ isu.skor_lonjakan }}x
                                        </span>
                                        <BadgeSentimen :label="isu.sentimen_dominan" ringkas class="shrink-0" />
                                        <span class="angka w-16 shrink-0 text-right text-xs text-muted-foreground">
                                            {{ formatAngka(isu.jumlah_artikel) }} berita
                                        </span>
                                    </div>
                                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                                        <div
                                            class="h-full rounded-full bg-foreground/40"
                                            :style="{ width: `${(isu.jumlah_artikel / puncakIsu) * 100}%` }"
                                        ></div>
                                    </div>
                                </Link>
                            </li>
                        </ul>
                        <p v-else class="py-4 text-sm text-muted-foreground">
                            Belum ada isu terhitung pada rentang ini. Isu dihitung dari berita yang sudah selesai diklasifikasi.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex-row items-center justify-between pb-2">
                        <CardTitle class="text-base">Media paling aktif</CardTitle>
                        <Link :href="`/eksekutif/media?${kueri()}`" class="text-xs underline">Peringkat</Link>
                    </CardHeader>
                    <CardContent>
                        <ul v-if="peringkatMedia.length" class="space-y-2.5">
                            <li v-for="m in peringkatMedia" :key="m.id">
                                <div class="flex items-center gap-3">
                                    <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ m.nama }}</span>
                                    <span class="angka shrink-0 text-xs text-muted-foreground">
                                        {{ formatAngka(m.jumlah_artikel) }}
                                    </span>
                                </div>
                                <!--
                                    Porsi negatif ditumpuk di bar yang sama.
                                    Media yang banyak memuat tapi hampir semuanya
                                    negatif adalah keadaan yang berbeda dari
                                    media yang banyak memuat dan netral.
                                -->
                                <div class="mt-1 flex h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div class="h-full bg-sentimen-negatif" :style="{ width: `${(m.jumlah_negatif / puncakMedia) * 100}%` }"></div>
                                    <div
                                        class="h-full bg-foreground/25"
                                        :style="{
                                            width: `${((m.jumlah_artikel - m.jumlah_negatif) / puncakMedia) * 100}%`,
                                        }"
                                    ></div>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="py-4 text-sm text-muted-foreground">Belum ada media yang memuat pada rentang ini.</p>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader class="flex-row items-center justify-between pb-2">
                    <CardTitle class="text-base">Berita terbaru</CardTitle>
                    <Link :href="`/eksekutif/berita?${kueri()}`" class="text-xs underline">Arsip berita</Link>
                </CardHeader>
                <CardContent>
                    <div v-if="beritaTerbaru.length" class="divide-y">
                        <KartuArtikel
                            v-for="berita in beritaTerbaru"
                            :key="berita.id"
                            v-bind="{
                                judul: berita.judul,
                                url: berita.url,
                                media: berita.media,
                                diambilAt: berita.diambil_at,
                                label: berita.label,
                                perluReview: berita.perlu_review,
                            }"
                            tampilkan-sentimen
                        />
                    </div>
                    <p v-else class="py-4 text-sm text-muted-foreground">Belum ada berita relevan yang berlabel pada rentang ini.</p>
                </CardContent>
            </Card>

            <p class="pt-2 text-center text-xs text-muted-foreground">
                Seluruh angka di halaman ini dihitung dari berita yang dinyatakan relevan dan sudah punya label sentimen. Label dihasilkan Gemini dan
                dapat dikoreksi admin. Akurasinya belum diukur terhadap kumpulan uji berlabel manusia, jadi angka di halaman ini masih perlu dibaca
                dengan hati-hati.
            </p>
        </template>
    </LayoutEksekutif>
</template>
