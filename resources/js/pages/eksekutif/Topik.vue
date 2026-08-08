<script setup lang="ts">
import ChartDonatSentimen from '@/components/chart/ChartDonatSentimen.vue';
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import KartuArtikel from '@/components/domain/KartuArtikel.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import { Head, Link } from '@inertiajs/vue3';
import { format, parseISO } from 'date-fns';
import { id } from 'date-fns/locale';
import { ArrowLeft } from 'lucide-vue-next';

type Label = 'positif' | 'netral' | 'negatif';

const props = defineProps<{
    topik: {
        id: number;
        title: string;
        summary: string | null;
        period_type: string;
        start_date: string;
        end_date: string;
        article_count: number;
        source_count: number;
        positive_count: number;
        neutral_count: number;
        negative_count: number;
        dominant_sentiment: Label | null;
        trend: string | null;
        priority_level: string | null;
        generated_at: string | null;
    };
    artikel: Array<{ id: number; judul: string; url: string; media: string | null; diambil_at: string; label: Label | null }>;
    media: Array<{ id: number | null; nama: string; jumlah: number }>;
}>();

const { formatAngka } = useFormatAngka();
const range = `${format(parseISO(props.topik.start_date), 'd MMMM', { locale: id })} – ${format(parseISO(props.topik.end_date), 'd MMMM yyyy', { locale: id })}`;
</script>

<template>
    <Head :title="topik.title" />
    <LayoutEksekutif>
        <Link
            :href="`/eksekutif?period=${topik.period_type}`"
            class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="h-4 w-4" /> Kembali ke dashboard
        </Link>

        <header class="space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <BadgeSentimen :label="topik.dominant_sentiment" />
                <Badge variant="secondary" class="capitalize">Tren {{ topik.trend }}</Badge>
                <Badge v-if="topik.priority_level !== 'rendah'" variant="outline" class="capitalize">Prioritas {{ topik.priority_level }}</Badge>
            </div>
            <h1 class="max-w-4xl text-2xl font-semibold leading-tight md:text-3xl">{{ topik.title }}</h1>
            <p class="max-w-4xl text-sm leading-6 text-muted-foreground">{{ topik.summary }}</p>
            <p class="text-xs text-muted-foreground">{{ range }}</p>
        </header>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <Card
                ><CardContent class="p-4"
                    ><p class="text-xs text-muted-foreground">Berita</p>
                    <p class="angka mt-1 text-3xl font-semibold">{{ formatAngka(topik.article_count) }}</p></CardContent
                ></Card
            >
            <Card
                ><CardContent class="p-4"
                    ><p class="text-xs text-muted-foreground">Media</p>
                    <p class="angka mt-1 text-3xl font-semibold">{{ formatAngka(topik.source_count) }}</p></CardContent
                ></Card
            >
            <Card
                ><CardContent class="p-4"
                    ><p class="text-xs text-muted-foreground">Negatif</p>
                    <p class="angka mt-1 text-3xl font-semibold">{{ formatAngka(topik.negative_count) }}</p></CardContent
                ></Card
            >
            <Card
                ><CardContent class="p-4"
                    ><p class="text-xs text-muted-foreground">Positif</p>
                    <p class="angka mt-1 text-3xl font-semibold">{{ formatAngka(topik.positive_count) }}</p></CardContent
                ></Card
            >
        </div>

        <div class="grid gap-4 lg:grid-cols-5">
            <Card class="lg:col-span-2"
                ><CardContent class="p-4"
                    ><ChartDonatSentimen
                        :positif="topik.positive_count"
                        :netral="topik.neutral_count"
                        :negatif="topik.negative_count"
                        :perlu-review="0"
                        :tinggi="280" /></CardContent
            ></Card>
            <Card class="lg:col-span-3">
                <CardHeader><CardTitle class="text-base">Sebaran Media</CardTitle></CardHeader>
                <CardContent
                    ><ul class="space-y-3">
                        <li
                            v-for="item in media"
                            :key="`${item.id}-${item.nama}`"
                            class="flex items-center justify-between border-b pb-2 text-sm last:border-0"
                        >
                            <span>{{ item.nama }}</span
                            ><span class="angka text-muted-foreground">{{ formatAngka(item.jumlah) }} berita</span>
                        </li>
                    </ul></CardContent
                >
            </Card>
        </div>

        <Card>
            <CardHeader><CardTitle class="text-base">Berita Terkait</CardTitle></CardHeader>
            <CardContent>
                <div v-if="artikel.length" class="divide-y">
                    <KartuArtikel
                        v-for="item in artikel"
                        :key="item.id"
                        :judul="item.judul"
                        :url="item.url"
                        :media="item.media"
                        :diambil-at="item.diambil_at"
                        :label="item.label"
                        tampilkan-sentimen
                    />
                </div>
                <p v-else class="text-sm text-muted-foreground">Artikel terkait tidak lagi tersedia.</p>
            </CardContent>
        </Card>
    </LayoutEksekutif>
</template>
