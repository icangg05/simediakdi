<script setup lang="ts">
import ChartTrenSentimen from '@/components/chart/ChartTrenSentimen.vue';
import ChartDonatSentimen from '@/components/chart/ChartDonatSentimen.vue';
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import KartuArtikel from '@/components/domain/KartuArtikel.vue';
import PemilihKonteks from '@/components/domain/PemilihKonteks.vue';
import PemilihRentangTanggal from '@/components/domain/PemilihRentangTanggal.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';

type Label = 'negatif' | 'netral' | 'positif';

const props = defineProps<{
    periode: { dari: string; sampai: string };
    konteksId: number | null;
    daftarKonteks: Array<{ id: number; nama: string; utama: boolean }>;
    kpi: {
        artikel: number;
        artikel_selisih: number;
        negatif: number;
        negatif_selisih: number;
        negatif_persen: number;
        positif: number;
        positif_selisih: number;
        netral: number;
        perlu_review: number;
        media_aktif: number;
    };
    deret: Array<Record<string, number | string>>;
    isuTeratas: Array<{
        istilah: string;
        jumlah_artikel: number;
        skor_lonjakan: number | null;
        sentimen_dominan: Label | null;
    }>;
    beritaTerbaru: Array<{
        id: number;
        judul: string;
        url: string;
        media: string | null;
        diambil_at: string;
        label: Label | null;
        perlu_review: boolean;
    }>;
    peringatan: { jumlah: number; terbaru: string; dipicu_at: string } | null;
    evaluasi: { f1_macro: number; jumlah_sampel: number; dievaluasi_at: string } | null;
}>();

const { formatAngka, formatPersen } = useFormatAngka();

function pindah(parameter: Record<string, string | number | null>) {
    router.get('/eksekutif', { ...props.periode, konteks: props.konteksId, ...parameter }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

/**
 * Kartu negatif berubah latar saat proporsinya melewati 40%. Perubahan halus,
 * bukan merah menyala dan bukan berkedip, sudah cukup menarik perhatian tanpa
 * terasa mengancam.
 */
const negatifMenonjol = computed(() => props.kpi.negatif_persen > 40);

const kartu = computed(() => [
    { label: 'Berita masuk', nilai: props.kpi.artikel, selisih: props.kpi.artikel_selisih, sorot: false },
    {
        label: 'Sentimen negatif',
        nilai: props.kpi.negatif,
        selisih: props.kpi.negatif_selisih,
        keterangan: `${formatPersen(props.kpi.negatif_persen)} dari berita berlabel`,
        sorot: negatifMenonjol.value,
    },
    { label: 'Sentimen positif', nilai: props.kpi.positif, selisih: props.kpi.positif_selisih, sorot: false },
    { label: 'Media aktif memuat', nilai: props.kpi.media_aktif, sorot: false },
]);

const arah = (n: number) => (n > 0 ? 'naik' : n < 0 ? 'turun' : 'tetap');

const tautanPeriode = computed(() => ({
    dari: props.periode.dari,
    sampai: props.periode.sampai,
    konteks: props.konteksId,
}));
</script>

<template>
    <Head title="Ringkasan eksekutif" />

    <LayoutEksekutif>
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Pantauan Media Kota Kendari</h1>
                <p class="text-sm text-muted-foreground">
                    {{ format(new Date(periode.dari), 'd MMMM', { locale: id }) }} -
                    {{ format(new Date(periode.sampai), 'd MMMM yyyy', { locale: id }) }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <PemilihKonteks
                    :daftar="daftarKonteks"
                    :terpilih="konteksId"
                    @ubah="(id) => pindah({ konteks: id })"
                />
                <PemilihRentangTanggal
                    :dari="periode.dari"
                    :sampai="periode.sampai"
                    @ubah="(dari, sampai) => pindah({ dari, sampai })"
                />
            </div>
        </header>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Card v-for="k in kartu" :key="k.label" :class="k.sorot ? 'bg-sentimen-negatif-lembut' : ''">
                <CardContent class="p-4">
                    <p class="text-[13px] font-medium text-muted-foreground">{{ k.label }}</p>
                    <p class="angka mt-1 text-4xl font-semibold lg:text-5xl">{{ formatAngka(k.nilai) }}</p>
                    <p v-if="k.selisih !== undefined && k.selisih !== 0" class="mt-1 text-xs text-muted-foreground">
                        {{ arah(k.selisih) }} {{ formatAngka(Math.abs(k.selisih)) }} dari periode sebelumnya
                    </p>
                    <p v-else-if="k.keterangan" class="mt-1 text-xs text-muted-foreground">{{ k.keterangan }}</p>
                    <p v-else class="mt-1 text-xs text-muted-foreground">sama dengan periode sebelumnya</p>
                </CardContent>
            </Card>
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
                    <Link :href="`/eksekutif/sentimen?${new URLSearchParams(tautanPeriode as never).toString()}`" class="text-xs underline">
                        Lihat analisis sentimen
                    </Link>
                </div>
            </CardContent>
        </Card>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardContent class="p-4">
                    <ChartTrenSentimen :data="deret as never" :tinggi="240" />
                </CardContent>
            </Card>
            <Card>
                <CardContent class="p-4">
                    <ChartDonatSentimen
                        :negatif="kpi.negatif"
                        :netral="kpi.netral"
                        :positif="kpi.positif"
                        :perlu-review="kpi.perlu_review"
                        :tinggi="240"
                    />
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader class="flex-row items-center justify-between pb-2">
                    <CardTitle class="text-base">Isu teratas</CardTitle>
                    <Link :href="`/eksekutif/isu?${new URLSearchParams(tautanPeriode as never).toString()}`" class="text-xs underline">
                        Semua isu
                    </Link>
                </CardHeader>
                <CardContent>
                    <ul v-if="isuTeratas.length" class="divide-y">
                        <li v-for="isu in isuTeratas" :key="isu.istilah" class="flex items-center gap-3 py-2.5">
                            <span class="min-w-0 flex-1 truncate text-sm font-medium capitalize">{{ isu.istilah }}</span>
                            <span class="angka shrink-0 text-xs text-muted-foreground">
                                {{ formatAngka(isu.jumlah_artikel) }} berita
                            </span>
                            <span v-if="(isu.skor_lonjakan ?? 0) >= 2" class="angka shrink-0 text-xs font-medium">
                                {{ isu.skor_lonjakan }}×
                            </span>
                            <BadgeSentimen :label="isu.sentimen_dominan" ringkas class="shrink-0" />
                        </li>
                    </ul>
                    <p v-else class="py-4 text-sm text-muted-foreground">
                        Belum ada isu terhitung pada rentang ini.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="flex-row items-center justify-between pb-2">
                    <CardTitle class="text-base">Berita terbaru</CardTitle>
                    <Link :href="`/eksekutif/berita?${new URLSearchParams(tautanPeriode as never).toString()}`" class="text-xs underline">
                        Arsip berita
                    </Link>
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
                    <p v-else class="py-4 text-sm text-muted-foreground">Belum ada berita pada rentang ini.</p>
                </CardContent>
            </Card>
        </div>

        <p class="pt-2 text-center text-xs text-muted-foreground">
            <template v-if="evaluasi">
                Analisis otomatis. Akurasi terukur {{ evaluasi.f1_macro }} F1 macro pada
                {{ formatAngka(evaluasi.jumlah_sampel) }} artikel uji, dievaluasi
                {{ format(new Date(evaluasi.dievaluasi_at), 'd MMMM yyyy', { locale: id }) }}.
            </template>
            <template v-else>
                Analisis otomatis. Akurasi model belum diukur, angka di halaman ini belum dapat
                dipertanggungjawabkan.
            </template>
        </p>
    </LayoutEksekutif>
</template>
