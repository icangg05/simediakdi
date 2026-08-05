<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import PemilihRentangTanggal from '@/components/domain/PemilihRentangTanggal.vue';
import { Button } from '@/components/ui/button';
import { usePeriodeEksekutif } from '@/composables/usePeriodeEksekutif';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import type { FilterDefinisi, KolomDefinisi, OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { Head } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { ExternalLink, X } from 'lucide-vue-next';

type Label = 'negatif' | 'netral' | 'positif';

interface Baris {
    id: number;
    judul: string;
    url: string;
    media: string | null;
    diambil_at: string;
    label: Label | null;
    perlu_review: boolean;
}

const props = defineProps<{
    periode: { dari: string; sampai: string };
    artikel: { data: Baris[] } & PaginasiMeta;
    istilah: string | null;
    opsi: Record<string, OpsiFilter[]>;
}>();

const { pindah } = usePeriodeEksekutif(props.periode, '/eksekutif/berita');

const kolom: KolomDefinisi[] = [
    { kunci: 'judul', judul: 'Judul', bisaDiurutkan: true },
    { kunci: 'media', judul: 'Media', lebar: 'w-40' },
    { kunci: 'diambil_at', judul: 'Diambil', bisaDiurutkan: true, lebar: 'w-32' },
    { kunci: 'label', judul: 'Nada', lebar: 'w-36' },
];

const filter: FilterDefinisi[] = [
    { kunci: 'media', label: 'Media', opsi: props.opsi.media },
    {
        kunci: 'sentimen',
        label: 'Nada',
        opsi: [
            { nilai: 'negatif', label: 'Negatif' },
            { nilai: 'netral', label: 'Netral' },
            { nilai: 'positif', label: 'Positif' },
        ],
    },
];

const waktu = (n: string) => format(new Date(n), 'd MMM yyyy, HH:mm', { locale: id });
</script>

<template>
    <Head title="Arsip berita" />

    <LayoutEksekutif>
        <header class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold">Arsip berita</h1>
            <div class="flex flex-wrap items-center gap-2">
                <PemilihRentangTanggal
                    :dari="periode.dari"
                    :sampai="periode.sampai"
                    @ubah="(dari, sampai) => pindah({ dari, sampai })"
                />
            </div>
        </header>

        <p v-if="istilah" class="flex items-center gap-2 text-sm">
            <span class="text-muted-foreground">Disaring istilah</span>
            <span class="rounded-md bg-muted px-2 py-0.5 font-medium">{{ istilah }}</span>
            <Button variant="ghost" size="sm" class="h-6 px-1.5 text-xs" @click="pindah({ istilah: null })">
                <X class="mr-1 h-3 w-3" /> Hapus
            </Button>
        </p>

        <DataTable
            :kolom="kolom"
            :data="artikel.data"
            :meta="artikel"
            :filter="filter"
            pencarian
            url-basis="/eksekutif/berita"
            judul-kosong="Belum ada berita pada rentang ini"
            keterangan-kosong="Perlebar rentang tanggal, atau longgarkan filternya."
        >
            <template #sel-judul="{ baris }">
                <a
                    :href="baris.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-start gap-1 font-medium hover:underline"
                >
                    <span class="line-clamp-2">{{ baris.judul }}</span>
                    <ExternalLink class="mt-0.5 h-3 w-3 shrink-0 text-muted-foreground" aria-hidden="true" />
                </a>
            </template>

            <template #sel-media="{ baris }">
                <span :class="baris.media ? '' : 'text-muted-foreground'">
                    {{ baris.media ?? 'Belum ditautkan' }}
                </span>
            </template>

            <template #sel-diambil_at="{ baris }">
                <span class="text-muted-foreground">{{ waktu(baris.diambil_at) }}</span>
            </template>

            <template #sel-label="{ baris }">
                <BadgeSentimen :label="baris.label" :perlu-review="baris.perlu_review" />
            </template>
        </DataTable>
    </LayoutEksekutif>
</template>
