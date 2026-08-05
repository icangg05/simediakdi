<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { FilterDefinisi, KolomDefinisi, PaginasiMeta } from '@/types/tabel';
import { Head, router } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { ExternalLink, Sparkles, ThumbsDown, ThumbsUp } from 'lucide-vue-next';
import { ref } from 'vue';

type LabelSentimen = 'negatif' | 'netral' | 'positif';

interface Analisis {
    id: number;
    relevan: boolean;
    relevan_manual: boolean | null;
    label_model: LabelSentimen | null;
    label_manual: LabelSentimen | null;
    label_efektif: LabelSentimen | null;
    perlu_review: boolean;
    provider: string | null;
    reason_code: string | null;
    reason_summary: string | null;
    evidence: string[] | null;
}

interface BarisArtikel {
    id: number;
    judul: string;
    url: string;
    media: string | null;
    diambil_at: string;
    status_proses: string;
    analisis: Analisis | null;
}

const props = defineProps<{
    status: string;
    saringan: { nilai: string; label: string; jumlah: number }[];
    pantauan: string;
    artikel: { data: BarisArtikel[] } & PaginasiMeta;
}>();

const kolom: KolomDefinisi[] = [
    { kunci: 'judul', judul: 'Berita' },
    { kunci: 'media', judul: 'Media', lebar: 'w-36' },
    { kunci: 'diambil_at', judul: 'Masuk', lebar: 'w-28' },
    { kunci: 'hasil', judul: 'Hasil AI', lebar: 'w-72' },
    { kunci: 'aksi', judul: '', lebar: 'w-40' },
];

const filter: FilterDefinisi[] = [
    {
        kunci: 'status',
        label: 'Tahap',
        opsi: props.saringan.map((s) => ({
            nilai: s.nilai,
            label: `${s.label} (${s.jumlah})`,
        })),
    },
];

/** Id artikel yang sedang dikirim, supaya tombolnya terkunci satu per satu. */
const sedangJalan = ref<number | null>(null);

function klasifikasi(baris: BarisArtikel) {
    sedangJalan.value = baris.id;

    router.post(
        `/admin/review/${baris.id}/klasifikasi`,
        {},
        {
            preserveScroll: true,
            onFinish: () => (sedangJalan.value = null),
        },
    );
}

function putuskan(analisis: Analisis, relevan: boolean) {
    router.post('/admin/review', { analisis_id: analisis.id, relevan }, { preserveScroll: true });
}

const warnaSentimen: Record<LabelSentimen, string> = {
    positif: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    netral: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    negatif: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
};

const waktu = (n: string) => format(new Date(n), 'd MMM yyyy', { locale: id });
</script>

<template>
    <Head title="Antrean Klasifikasi" />

    <LayoutAdmin>
        <div class="space-y-4 p-4">
            <div>
                <h1 class="text-xl font-semibold">Antrean Klasifikasi</h1>
                <p class="text-sm text-muted-foreground">
                    Berita masuk dinilai Gemini lewat tombol Klasifikasi, satu artikel satu klik.
                    Hasilnya relevan atau tidak, lalu sentimen negatif, netral, atau positif.
                    Yang dinilai: {{ pantauan }}.
                </p>
            </div>

            <DataTable
                :kolom="kolom"
                :data="artikel.data"
                :meta="artikel"
                :filter="filter"
                pencarian
                url-basis="/admin/review"
                judul-kosong="Tidak ada berita pada tahap ini"
                keterangan-kosong="Pilih tahap lain di filter, atau tunggu crawler mengambil berita baru."
            >
                <template #sel-judul="{ baris }">
                    <a
                        :href="baris.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-start gap-1 font-medium hover:underline"
                    >
                        {{ baris.judul }}
                        <ExternalLink class="mt-1 size-3 shrink-0 opacity-60" />
                    </a>
                </template>

                <template #sel-media="{ baris }">
                    <span class="text-sm text-muted-foreground">{{ baris.media ?? '-' }}</span>
                </template>

                <template #sel-diambil_at="{ baris }">
                    <span class="text-sm text-muted-foreground">{{ waktu(baris.diambil_at) }}</span>
                </template>

                <template #sel-hasil="{ baris }">
                    <div v-if="baris.analisis === null" class="text-sm text-muted-foreground">
                        Belum dinilai
                    </div>

                    <div v-else class="space-y-1.5">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <Badge variant="outline">
                                {{ baris.analisis.relevan ? 'Relevan' : 'Tidak relevan' }}
                            </Badge>

                            <Badge
                                v-if="baris.analisis.label_efektif"
                                :class="warnaSentimen[baris.analisis.label_efektif]"
                            >
                                {{ baris.analisis.label_efektif }}
                            </Badge>

                            <Badge v-if="baris.analisis.perlu_review" variant="secondary">
                                Perlu review
                            </Badge>

                            <!-- Penanda bahwa nilainya keputusan manusia, bukan Gemini.
                                 Tanpa ini admin tidak bisa membedakan baris yang sudah
                                 diperiksa dari baris yang kebetulan sependapat. -->
                            <Badge
                                v-if="baris.analisis.relevan_manual !== null || baris.analisis.label_manual"
                                variant="secondary"
                            >
                                Dikoreksi
                            </Badge>
                        </div>

                        <p v-if="baris.analisis.reason_summary" class="text-xs text-muted-foreground">
                            {{ baris.analisis.reason_summary }}
                        </p>

                        <details v-if="baris.analisis.evidence?.length" class="text-xs">
                            <summary class="cursor-pointer text-muted-foreground hover:text-foreground">
                                Bukti ({{ baris.analisis.evidence.length }})
                            </summary>
                            <ul class="mt-1 space-y-1 border-l pl-2 text-muted-foreground">
                                <li v-for="(kutipan, i) in baris.analisis.evidence" :key="i">
                                    &ldquo;{{ kutipan }}&rdquo;
                                </li>
                            </ul>
                        </details>
                    </div>
                </template>

                <template #sel-aksi="{ baris }">
                    <div class="flex flex-col gap-1.5">
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="sedangJalan === baris.id"
                            @click="klasifikasi(baris)"
                        >
                            <Sparkles class="size-3.5" />
                            {{ sedangJalan === baris.id ? 'Menilai...' : 'Klasifikasi' }}
                        </Button>

                        <!-- Koreksi relevansi hanya muncul kalau barisnya sudah ada.
                             Menandai relevan sebelum Gemini menilai tidak punya baris
                             untuk ditulisi. -->
                        <div v-if="baris.analisis" class="flex gap-1">
                            <Button
                                size="sm"
                                variant="ghost"
                                class="flex-1"
                                title="Tandai relevan"
                                @click="putuskan(baris.analisis, true)"
                            >
                                <ThumbsUp class="size-3.5" />
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                class="flex-1"
                                title="Tandai tidak relevan"
                                @click="putuskan(baris.analisis, false)"
                            >
                                <ThumbsDown class="size-3.5" />
                            </Button>
                        </div>
                    </div>
                </template>
            </DataTable>
        </div>
    </LayoutAdmin>
</template>
