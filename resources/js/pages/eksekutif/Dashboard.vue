<script setup lang="ts">
import ChartDonatSentimen from '@/components/chart/ChartDonatSentimen.vue';
import ChartTrenSentimen from '@/components/chart/ChartTrenSentimen.vue';
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import KartuArtikel from '@/components/domain/KartuArtikel.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import { Head, Link } from '@inertiajs/vue3';
import { format, parseISO } from 'date-fns';
import { id } from 'date-fns/locale';
import { ArrowDownRight, ArrowUpRight, Clock3, Minus, Sparkles, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';

type Label = 'positif' | 'netral' | 'negatif';

interface SentimentDatum {
    count: number;
    percentage: number;
}

interface Topic {
    id: number;
    title: string;
    summary: string | null;
    article_count: number;
    source_count: number;
    sentiment: Record<Label, number>;
    dominant_sentiment: Label | null;
    trend: 'baru' | 'stabil' | 'meningkat' | 'menurun' | null;
    priority_score: number;
    priority_level: 'rendah' | 'sedang' | 'tinggi' | null;
}

interface Article {
    id: number;
    judul: string;
    url: string;
    media: string | null;
    diambil_at: string;
    label: Label | null;
}

interface Dashboard {
    period: {
        type: 'today' | '7d' | '30d' | '90d';
        label: string;
        start: string;
        end: string;
        previous_start: string;
        previous_end: string;
    };
    updated_at: string | null;
    generation_status: {
        state: 'tanpa_data' | 'belum_mulai' | 'berjalan' | 'macet' | 'gagal' | 'menunggu_ringkasan' | 'siap';
        task: 'topics' | 'summary' | null;
        message: string;
        error: string | null;
        last_activity_at: string | null;
        topics_ready: boolean;
        summary_ready: boolean;
        tasks: Record<'topics' | 'summary', null | { status: string; input_article_count: number; duration_ms: number | null; updated_at: string }>;
    };
    summary: null | {
        overall_tone: Label | 'campuran';
        headline: string;
        summary: string;
        key_points: string[];
        attention_required: Array<{ topic: string; reason: string }>;
        sentiment_summary: Record<Label, string>;
        generated_at: string | null;
        stale: boolean;
    };
    metrics: { total_articles: number; active_sources: number; average_articles_per_day: number };
    sentiment: Record<Label, SentimentDatum>;
    comparison: {
        total_articles: { difference: number; percentage: number | null; previous: number };
        active_sources: { difference: number; percentage: number | null; previous: number };
        average_articles_per_day: { difference: number; percentage: number | null; previous: number };
        sentiment: Record<Label, { percentage_points: number; count_difference: number }>;
    };
    sentiment_trend: Array<Record<string, string | number>>;
    topics: Topic[];
    attention_items: Topic[];
    top_sources: Array<{
        id: number;
        nama: string;
        jumlah_artikel: number;
        jumlah_positif: number;
        jumlah_netral: number;
        jumlah_negatif: number;
    }>;
    representative_articles: Record<'perlu_diperhatikan' | 'positif_utama' | 'terbaru', Article[]>;
}

const props = defineProps<{ dashboard: Dashboard }>();
const { formatAngka, formatPersen } = useFormatAngka();

const periods = [
    { value: 'today', label: 'Hari Ini' },
    { value: '7d', label: '7 Hari' },
    { value: '30d', label: '30 Hari' },
    { value: '90d', label: '3 Bulan' },
];

const archiveQuery = computed(() => `dari=${props.dashboard.period.start}&sampai=${props.dashboard.period.end}`);
const readableRange = computed(() => {
    const start = format(parseISO(props.dashboard.period.start), 'd MMMM', { locale: id });
    const end = format(parseISO(props.dashboard.period.end), 'd MMMM yyyy', { locale: id });
    return props.dashboard.period.start === props.dashboard.period.end ? end : `${start} – ${end}`;
});

const updatedAt = computed(() =>
    props.dashboard.updated_at ? format(new Date(props.dashboard.updated_at), "d MMMM yyyy, HH:mm 'WITA'", { locale: id }) : 'Belum tersedia',
);

const overallTone = computed(() => {
    const tone = props.dashboard.summary?.overall_tone;
    if (tone === 'campuran') return 'Campuran';
    if (tone) return tone.charAt(0).toUpperCase() + tone.slice(1);
    const entries = Object.entries(props.dashboard.sentiment) as Array<[Label, SentimentDatum]>;
    const dominant = entries.sort((a, b) => b[1].count - a[1].count)[0];
    return dominant && dominant[1].count > 0 ? dominant[0].charAt(0).toUpperCase() + dominant[0].slice(1) : 'Belum tersedia';
});

const dominantTopic = computed(() => props.dashboard.topics[0]?.title ?? 'Sedang diperbarui');
const mainAttention = computed(() => props.dashboard.attention_items[0]?.title ?? 'Tidak ada perhatian khusus');
const sourcePeak = computed(() => Math.max(1, ...props.dashboard.top_sources.map((source) => source.jumlah_artikel)));

const deltaText = (value: number, unit = '') => {
    if (value === 0) return 'Tidak berubah';
    return `${value > 0 ? 'Naik' : 'Turun'} ${formatAngka(Math.abs(value))}${unit}`;
};

const percentageDelta = (value: number | null) => {
    if (value === null) return 'Belum ada data pembanding';
    if (value === 0) return 'Tidak berubah';
    return `${value > 0 ? 'Naik' : 'Turun'} ${formatPersen(Math.abs(value))}`;
};

const sentimentClass: Record<Label, string> = {
    positif: 'bg-sentimen-positif',
    netral: 'bg-sentimen-netral',
    negatif: 'bg-sentimen-negatif',
};

const priorityClass = (level: Topic['priority_level']) =>
    level === 'tinggi'
        ? 'border-sentimen-negatif/30 bg-sentimen-negatif-lembut text-sentimen-negatif'
        : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300';

const generationTone = computed(() => {
    const state = props.dashboard.generation_status.state;
    if (state === 'siap') return 'bg-emerald-500';
    if (state === 'gagal' || state === 'macet') return 'bg-red-500';
    if (state === 'berjalan') return 'animate-pulse bg-blue-500';
    return 'bg-amber-500';
});

const generationLabel = computed(
    () =>
        ({
            tanpa_data: 'Tanpa data',
            belum_mulai: 'Belum mulai',
            berjalan: 'Sedang diproses',
            macet: 'Proses macet',
            gagal: 'Gagal',
            menunggu_ringkasan: 'Menunggu ringkasan',
            siap: 'Siap',
        })[props.dashboard.generation_status.state],
);
</script>

<template>
    <Head title="Dashboard Eksekutif" />

    <LayoutEksekutif>
        <header class="rounded-xl border bg-card p-5 shadow-sm md:p-6">
            <div class="flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
                <div class="space-y-1">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-primary">SIMEDIA Kendari</p>
                    <h1 class="text-2xl font-semibold tracking-tight md:text-3xl">Dashboard Eksekutif</h1>
                    <p class="text-sm text-muted-foreground">Kondisi pemberitaan Pemerintah Kota Kendari · {{ readableRange }}</p>
                </div>

                <nav class="grid grid-cols-4 rounded-lg bg-muted p-1" aria-label="Pilih periode dashboard">
                    <Link
                        v-for="item in periods"
                        :key="item.value"
                        :href="`/eksekutif?period=${item.value}`"
                        preserve-scroll
                        :class="[
                            'rounded-md px-3 py-2 text-center text-xs font-medium transition-colors sm:text-sm',
                            dashboard.period.type === item.value
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                    >
                        {{ item.label }}
                    </Link>
                </nav>
            </div>
            <div class="mt-4 flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Clock3 class="h-3.5 w-3.5" aria-hidden="true" /> Data statistik diperbarui: {{ updatedAt }}
                </p>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <Badge variant="outline" class="gap-1.5 bg-background">
                        <span :class="['h-2 w-2 rounded-full', generationTone]"></span>
                        AI: {{ generationLabel }}
                    </Badge>
                    <span class="text-muted-foreground">{{ dashboard.generation_status.message }}</span>
                </div>
            </div>
            <p
                v-if="dashboard.generation_status.error"
                class="mt-3 rounded-md border border-red-500/20 bg-red-500/5 px-3 py-2 text-xs text-red-700 dark:text-red-300"
            >
                {{ dashboard.generation_status.error }}
            </p>
        </header>

        <Card class="overflow-hidden border-primary/20 bg-gradient-to-br from-primary/10 via-card to-card">
            <CardContent class="p-5 md:p-6">
                <div class="flex items-start gap-3">
                    <span class="rounded-lg bg-primary/10 p-2 text-primary"><Sparkles class="h-5 w-5" aria-hidden="true" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-wider text-primary">Ringkasan Eksekutif</p>
                        <template v-if="dashboard.summary">
                            <h2 class="mt-1 text-lg font-semibold md:text-xl">{{ dashboard.summary.headline }}</h2>
                            <p class="mt-3 max-w-5xl whitespace-pre-line text-sm leading-6 text-muted-foreground">{{ dashboard.summary.summary }}</p>
                            <ul v-if="dashboard.summary.key_points.length" class="mt-4 grid gap-2 md:grid-cols-2">
                                <li v-for="point in dashboard.summary.key_points" :key="point" class="flex gap-2 text-sm">
                                    <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary"></span>{{ point }}
                                </li>
                            </ul>
                            <p v-if="dashboard.summary.stale" class="mt-4 text-xs text-amber-700 dark:text-amber-300">
                                Ringkasan berasal dari pembaruan terakhir; statistik pada bagian berikut sudah menggunakan data terbaru.
                            </p>
                        </template>
                        <template v-else>
                            <h2 class="mt-1 text-lg font-semibold">Ringkasan sedang diperbarui berdasarkan berita terbaru</h2>
                            <p class="mt-2 text-sm text-muted-foreground">Statistik dashboard tetap tersedia dan tidak bergantung pada proses AI.</p>
                        </template>
                    </div>
                </div>

                <div class="mt-5 grid gap-3 border-t border-primary/10 pt-4 md:grid-cols-3">
                    <div>
                        <p class="text-xs text-muted-foreground">Nada umum</p>
                        <p class="mt-0.5 font-medium">Cenderung {{ overallTone }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Topik dominan</p>
                        <p class="mt-0.5 line-clamp-2 font-medium">{{ dominantTopic }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Perlu perhatian</p>
                        <p class="mt-0.5 line-clamp-2 font-medium">{{ mainAttention }}</p>
                    </div>
                </div>
            </CardContent>
        </Card>

        <section aria-labelledby="kpi-heading">
            <h2 id="kpi-heading" class="sr-only">Indikator utama</h2>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-6">
                <Link :href="`/eksekutif/berita?${archiveQuery}`" class="col-span-2 lg:col-span-1">
                    <Card class="h-full transition-colors hover:border-primary/40"
                        ><CardContent class="p-4">
                            <p class="text-xs font-medium text-muted-foreground">Berita Relevan</p>
                            <p class="angka mt-2 text-3xl font-semibold">{{ formatAngka(dashboard.metrics.total_articles) }}</p>
                            <p class="mt-2 text-xs text-muted-foreground">
                                {{ percentageDelta(dashboard.comparison.total_articles.percentage) }} dari sebelumnya
                            </p>
                        </CardContent></Card
                    >
                </Link>

                <Link
                    v-for="label in ['positif', 'netral', 'negatif'] as Label[]"
                    :key="label"
                    :href="`/eksekutif/berita?${archiveQuery}&sentimen=${label}`"
                >
                    <Card class="h-full transition-colors hover:border-primary/40"
                        ><CardContent class="p-4">
                            <div class="flex items-center gap-2">
                                <span :class="['h-2 w-2 rounded-full', sentimentClass[label]]"></span>
                                <p class="text-xs font-medium capitalize text-muted-foreground">{{ label }}</p>
                            </div>
                            <p class="angka mt-2 text-3xl font-semibold">{{ formatPersen(dashboard.sentiment[label].percentage) }}</p>
                            <p class="mt-2 text-xs text-muted-foreground">
                                {{ deltaText(dashboard.comparison.sentiment[label].percentage_points, ' poin persentase') }}
                            </p>
                        </CardContent></Card
                    >
                </Link>

                <Card
                    ><CardContent class="p-4">
                        <p class="text-xs font-medium text-muted-foreground">Media Aktif</p>
                        <p class="angka mt-2 text-3xl font-semibold">{{ formatAngka(dashboard.metrics.active_sources) }}</p>
                        <p class="mt-2 text-xs text-muted-foreground">
                            {{ deltaText(dashboard.comparison.active_sources.difference) }} dari sebelumnya
                        </p>
                    </CardContent></Card
                >
                <Card
                    ><CardContent class="p-4">
                        <p class="text-xs font-medium text-muted-foreground">Intensitas Harian</p>
                        <p class="angka mt-2 text-3xl font-semibold">{{ formatAngka(dashboard.metrics.average_articles_per_day) }}</p>
                        <p class="mt-2 text-xs text-muted-foreground">Rata-rata berita per hari</p>
                    </CardContent></Card
                >
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-5" aria-label="Grafik sentimen pemberitaan">
            <Card class="lg:col-span-2"
                ><CardContent class="p-4">
                    <ChartDonatSentimen
                        :positif="dashboard.sentiment.positif.count"
                        :netral="dashboard.sentiment.netral.count"
                        :negatif="dashboard.sentiment.negatif.count"
                        :perlu-review="0"
                        :tinggi="280"
                    /> </CardContent
            ></Card>
            <Card class="lg:col-span-3"
                ><CardContent class="p-4">
                    <ChartTrenSentimen :data="dashboard.sentiment_trend as never" satuan="harian" :tinggi="280" /> </CardContent
            ></Card>
        </section>

        <section aria-labelledby="topics-heading">
            <div class="mb-3 flex items-end justify-between gap-3">
                <div>
                    <h2 id="topics-heading" class="text-lg font-semibold">Topik Utama</h2>
                    <p class="text-xs text-muted-foreground">Pembahasan yang paling mewakili periode ini</p>
                </div>
            </div>
            <div v-if="dashboard.topics.length" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <Link v-for="topic in dashboard.topics" :key="topic.id" :href="`/eksekutif/topik/${topic.id}`">
                    <Card class="h-full transition-colors hover:border-primary/40"
                        ><CardContent class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <BadgeSentimen :label="topic.dominant_sentiment" ringkas />
                                <Badge variant="secondary" class="capitalize">{{ topic.trend }}</Badge>
                            </div>
                            <h3 class="mt-3 line-clamp-3 font-semibold leading-snug">{{ topic.title }}</h3>
                            <p class="mt-2 line-clamp-2 text-xs leading-5 text-muted-foreground">{{ topic.summary }}</p>
                            <div class="mt-4 flex gap-4 text-xs text-muted-foreground">
                                <span
                                    ><b class="angka text-foreground">{{ formatAngka(topic.article_count) }}</b> berita</span
                                >
                                <span
                                    ><b class="angka text-foreground">{{ formatAngka(topic.source_count) }}</b> media</span
                                >
                            </div>
                            <div class="mt-3 flex h-1.5 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                                <span class="bg-sentimen-positif" :style="{ width: `${topic.sentiment.positif}%` }"></span>
                                <span class="bg-sentimen-netral" :style="{ width: `${topic.sentiment.netral}%` }"></span>
                                <span class="bg-sentimen-negatif" :style="{ width: `${topic.sentiment.negatif}%` }"></span>
                            </div>
                            <div class="mt-1.5 flex justify-between text-[11px] text-muted-foreground">
                                <span>Positif {{ formatPersen(topic.sentiment.positif) }}</span
                                ><span>Netral {{ formatPersen(topic.sentiment.netral) }}</span
                                ><span>Negatif {{ formatPersen(topic.sentiment.negatif) }}</span>
                            </div>
                        </CardContent></Card
                    >
                </Link>
            </div>
            <Card v-else
                ><CardContent class="p-6 text-sm text-muted-foreground"
                    >Topik sedang disusun dari berita relevan terbaru. Statistik di atas tetap merupakan data terkini.</CardContent
                ></Card
            >
        </section>

        <section aria-labelledby="attention-heading">
            <div class="mb-3">
                <h2 id="attention-heading" class="text-lg font-semibold">Isu yang Perlu Diperhatikan</h2>
                <p class="text-xs text-muted-foreground">Prioritas berdasarkan volume negatif, sebaran media, pertumbuhan, dan kemunculan beruntun</p>
            </div>
            <div v-if="dashboard.attention_items.length" class="grid gap-3 md:grid-cols-2">
                <Link v-for="topic in dashboard.attention_items" :key="topic.id" :href="`/eksekutif/topik/${topic.id}`">
                    <Card :class="['h-full border', priorityClass(topic.priority_level)]"
                        ><CardContent class="p-4">
                            <div class="flex gap-3">
                                <TriangleAlert class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <h3 class="font-semibold">{{ topic.title }}</h3>
                                        <Badge variant="outline" class="capitalize">Prioritas {{ topic.priority_level }}</Badge>
                                    </div>
                                    <p class="mt-2 text-xs opacity-80">
                                        {{ formatPersen(topic.sentiment.negatif) }} bernada negatif · {{ formatAngka(topic.source_count) }} media ·
                                        tren {{ topic.trend }}
                                    </p>
                                </div>
                            </div>
                        </CardContent></Card
                    >
                </Link>
            </div>
            <Card v-else class="border-emerald-500/20 bg-emerald-500/5"
                ><CardContent class="p-5 text-sm text-muted-foreground"
                    >Belum ada isu yang memenuhi ambang prioritas pada periode ini.</CardContent
                ></Card
            >
        </section>

        <section v-if="dashboard.summary?.sentiment_summary" aria-labelledby="tone-heading">
            <div class="mb-3"><h2 id="tone-heading" class="text-lg font-semibold">Rangkuman Nada Pemberitaan</h2></div>
            <div class="grid gap-3 md:grid-cols-3">
                <Card v-for="label in ['positif', 'netral', 'negatif'] as Label[]" :key="label"
                    ><CardContent class="p-4">
                        <div class="flex items-center gap-2">
                            <span :class="['h-2.5 w-2.5 rounded-full', sentimentClass[label]]"></span>
                            <h3 class="font-semibold capitalize">{{ label }}</h3>
                        </div>
                        <p class="mt-3 text-sm leading-6 text-muted-foreground">{{ dashboard.summary.sentiment_summary[label] }}</p>
                    </CardContent></Card
                >
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-5" aria-label="Perbandingan periode dan sumber media">
            <Card class="lg:col-span-2">
                <CardHeader><CardTitle class="text-base">Dibanding Periode Sebelumnya</CardTitle></CardHeader>
                <CardContent class="space-y-3">
                    <div
                        v-for="item in [
                            {
                                label: 'Total pemberitaan',
                                value: dashboard.comparison.total_articles.difference,
                                text: percentageDelta(dashboard.comparison.total_articles.percentage),
                            },
                            {
                                label: 'Nada positif',
                                value: dashboard.comparison.sentiment.positif.percentage_points,
                                text: deltaText(dashboard.comparison.sentiment.positif.percentage_points, ' poin persentase'),
                            },
                            {
                                label: 'Nada netral',
                                value: dashboard.comparison.sentiment.netral.percentage_points,
                                text: deltaText(dashboard.comparison.sentiment.netral.percentage_points, ' poin persentase'),
                            },
                            {
                                label: 'Nada negatif',
                                value: dashboard.comparison.sentiment.negatif.percentage_points,
                                text: deltaText(dashboard.comparison.sentiment.negatif.percentage_points, ' poin persentase'),
                            },
                        ]"
                        :key="item.label"
                        class="flex items-center justify-between gap-3 border-b pb-3 last:border-0 last:pb-0"
                    >
                        <span class="text-sm">{{ item.label }}</span>
                        <span class="flex items-center gap-1 text-xs text-muted-foreground">
                            <ArrowUpRight v-if="item.value > 0" class="h-3.5 w-3.5" /><ArrowDownRight
                                v-else-if="item.value < 0"
                                class="h-3.5 w-3.5"
                            /><Minus v-else class="h-3.5 w-3.5" />{{ item.text }}
                        </span>
                    </div>
                </CardContent>
            </Card>

            <Card class="lg:col-span-3">
                <CardHeader class="flex-row items-center justify-between"
                    ><CardTitle class="text-base">Sumber Media Paling Aktif</CardTitle
                    ><Link :href="`/eksekutif/media?${archiveQuery}`" class="text-xs underline">Lihat peringkat</Link></CardHeader
                >
                <CardContent>
                    <ul v-if="dashboard.top_sources.length" class="space-y-3">
                        <li v-for="source in dashboard.top_sources" :key="source.id">
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span class="truncate font-medium">{{ source.nama }}</span
                                ><span class="angka text-xs text-muted-foreground">{{ formatAngka(source.jumlah_artikel) }} berita</span>
                            </div>
                            <div class="mt-1.5 flex h-1.5 overflow-hidden rounded-full bg-muted">
                                <span class="bg-sentimen-positif" :style="{ width: `${(source.jumlah_positif / sourcePeak) * 100}%` }"></span>
                                <span class="bg-sentimen-netral" :style="{ width: `${(source.jumlah_netral / sourcePeak) * 100}%` }"></span>
                                <span class="bg-sentimen-negatif" :style="{ width: `${(source.jumlah_negatif / sourcePeak) * 100}%` }"></span>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">Belum ada media aktif pada periode ini.</p>
                </CardContent>
            </Card>
        </section>

        <section aria-labelledby="news-heading">
            <div class="mb-3 flex items-end justify-between">
                <div>
                    <h2 id="news-heading" class="text-lg font-semibold">Berita Representatif</h2>
                    <p class="text-xs text-muted-foreground">Dipilih berdasarkan nada dan keterkinian, bukan sekadar urutan terbaru</p>
                </div>
                <Link :href="`/eksekutif/berita?${archiveQuery}`" class="text-xs underline">Buka arsip</Link>
            </div>
            <div class="grid gap-4 lg:grid-cols-3">
                <Card
                    v-for="group in [
                        { key: 'perlu_diperhatikan', title: 'Perlu Diperhatikan' },
                        { key: 'positif_utama', title: 'Pemberitaan Positif Utama' },
                        { key: 'terbaru', title: 'Berita Terbaru' },
                    ] as const"
                    :key="group.key"
                >
                    <CardHeader class="pb-2"
                        ><CardTitle class="text-sm">{{ group.title }}</CardTitle></CardHeader
                    >
                    <CardContent>
                        <div v-if="dashboard.representative_articles[group.key].length" class="divide-y">
                            <KartuArtikel
                                v-for="article in dashboard.representative_articles[group.key]"
                                :key="article.id"
                                :judul="article.judul"
                                :url="article.url"
                                :media="article.media"
                                :diambil-at="article.diambil_at"
                                :label="article.label"
                                tampilkan-sentimen
                            />
                        </div>
                        <p v-else class="py-3 text-xs text-muted-foreground">Belum ada berita dalam kelompok ini.</p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <p class="pt-2 text-center text-xs leading-5 text-muted-foreground">
            Statistik hanya menghitung berita yang dinyatakan relevan dan memiliki label sentimen. AI digunakan untuk menamai topik dan menyusun
            ringkasan, bukan untuk menghitung angka.
        </p>
    </LayoutEksekutif>
</template>
