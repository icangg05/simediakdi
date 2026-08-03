<script setup lang="ts">
import ChartDonatSentimen from '@/components/chart/ChartDonatSentimen.vue';
import ChartTrenSentimen from '@/components/chart/ChartTrenSentimen.vue';
import KartuArtikel from '@/components/domain/KartuArtikel.vue';
import PemilihKonteks from '@/components/domain/PemilihKonteks.vue';
import PemilihRentangTanggal from '@/components/domain/PemilihRentangTanggal.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useFormatAngka } from '@/composables/useFormatAngka';
import { usePeriodeEksekutif } from '@/composables/usePeriodeEksekutif';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import { Head } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';

interface Berita {
    id: number;
    judul: string;
    url: string;
    media: string | null;
    diambil_at: string;
}

const props = defineProps<{
    periode: { dari: string; sampai: string };
    konteksId: number | null;
    daftarKonteks: Array<{ id: number; nama: string; utama: boolean }>;
    kpi: {
        negatif: number;
        netral: number;
        positif: number;
        perlu_review: number;
        negatif_persen: number;
    };
    deret: Array<Record<string, number | string>>;
    beritaNegatif: Berita[];
    perluReview: Berita[];
    evaluasi: { f1_macro: number; jumlah_sampel: number; dievaluasi_at: string } | null;
}>();

const { formatAngka, formatPersen } = useFormatAngka();
const { pindah } = usePeriodeEksekutif(props.periode, props.konteksId, '/eksekutif/sentimen');
</script>

<template>
    <Head title="Analisis sentimen" />

    <LayoutEksekutif>
        <header class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold">Analisis sentimen</h1>
            <div class="flex flex-wrap items-center gap-2">
                <PemilihKonteks :daftar="daftarKonteks" :terpilih="konteksId" @ubah="(id) => pindah({ konteks: id })" />
                <PemilihRentangTanggal
                    :dari="periode.dari"
                    :sampai="periode.sampai"
                    @ubah="(dari, sampai) => pindah({ dari, sampai })"
                />
            </div>
        </header>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardContent class="p-4">
                    <ChartTrenSentimen :data="deret as never" :tinggi="280" />
                </CardContent>
            </Card>
            <Card>
                <CardContent class="p-4">
                    <ChartDonatSentimen
                        :negatif="kpi.negatif"
                        :netral="kpi.netral"
                        :positif="kpi.positif"
                        :perlu-review="kpi.perlu_review"
                        :tinggi="280"
                    />
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-base">
                        Berita bernada negatif
                        <span class="angka font-normal text-muted-foreground">
                            ({{ formatAngka(kpi.negatif) }}, {{ formatPersen(kpi.negatif_persen) }})
                        </span>
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div v-if="beritaNegatif.length" class="max-h-96 divide-y overflow-y-auto">
                        <KartuArtikel
                            v-for="b in beritaNegatif"
                            :key="b.id"
                            :judul="b.judul"
                            :url="b.url"
                            :media="b.media"
                            :diambil-at="b.diambil_at"
                            label="negatif"
                            tampilkan-sentimen
                        />
                    </div>
                    <p v-else class="py-4 text-sm text-muted-foreground">
                        Tidak ada berita bernada negatif pada rentang ini.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-base">Perlu ditinjau manusia</CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="mb-2 text-xs text-muted-foreground">
                        Keyakinan model di bawah ambang. Ditampilkan terpisah, bukan dilebur ke netral,
                        sistem tidak boleh menyatakan hal yang tidak diketahuinya.
                    </p>
                    <div v-if="perluReview.length" class="max-h-80 divide-y overflow-y-auto">
                        <KartuArtikel
                            v-for="b in perluReview"
                            :key="b.id"
                            :judul="b.judul"
                            :url="b.url"
                            :media="b.media"
                            :diambil-at="b.diambil_at"
                            :label="null"
                            perlu-review
                            tampilkan-sentimen
                        />
                    </div>
                    <p v-else class="py-4 text-sm text-muted-foreground">
                        Tidak ada hasil yang meragukan pada rentang ini.
                    </p>
                </CardContent>
            </Card>
        </div>

        <p v-if="evaluasi" class="text-center text-xs text-muted-foreground">
            Analisis otomatis. Akurasi terukur {{ evaluasi.f1_macro }} F1 macro pada
            {{ formatAngka(evaluasi.jumlah_sampel) }} artikel uji, dievaluasi
            {{ format(new Date(evaluasi.dievaluasi_at), 'd MMMM yyyy', { locale: id }) }}.
        </p>
    </LayoutEksekutif>
</template>
