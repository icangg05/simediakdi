<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import LayoutPortal from '@/layouts/LayoutPortal.vue';
import type { FilterDefinisi, KolomDefinisi, OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { Head } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { ExternalLink } from 'lucide-vue-next';

interface Baris {
    id: number;
    judul: string;
    url: string;
    diambil_at: string;
    dipublikasikan_at: string | null;
    jumlah_kata: number | null;
    status_dedup: string;
}

const props = defineProps<{
    artikel: { data: Baris[] } & PaginasiMeta;
    opsi: Record<string, OpsiFilter[]>;
}>();

const kolom: KolomDefinisi[] = [
    { kunci: 'judul', judul: 'Judul', bisaDiurutkan: true },
    { kunci: 'diambil_at', judul: 'Terpantau', bisaDiurutkan: true, lebar: 'w-40' },
    { kunci: 'jumlah_kata', judul: 'Kata', kelas: 'angka text-right', lebar: 'w-20' },
    { kunci: 'status_dedup', judul: 'Status', lebar: 'w-28' },
];

const filter: FilterDefinisi[] = [{ kunci: 'status_dedup', label: 'Status', opsi: props.opsi.status_dedup }];

const waktu = (n: string) => format(new Date(n), 'd MMM yyyy, HH:mm', { locale: id });
</script>

<template>
    <Head title="Berita saya" />

    <LayoutPortal judul="Berita saya" :breadcrumbs="[{ title: 'Berita saya', href: '/portal/berita' }]">
        <p class="text-sm text-muted-foreground">
            Berita media Anda yang tertangkap sistem. Berita berstatus salinan adalah rilis yang juga dimuat media
            lain lebih dulu, dan hanya dihitung sekali dalam analisis.
        </p>

        <DataTable
            :kolom="kolom"
            :data="props.artikel.data"
            :meta="props.artikel"
            :filter="filter"
            pencarian
            url-basis="/portal/berita"
            judul-kosong="Belum ada berita yang tertangkap"
            keterangan-kosong="Sistem membaca RSS media Anda tiap 15 menit. Kalau berita baru tidak muncul juga dalam sehari, kabari Diskominfo agar sumber feed-nya diperiksa."
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

            <template #sel-diambil_at="{ baris }">
                <span class="text-xs text-muted-foreground">{{ waktu(baris.diambil_at) }}</span>
            </template>

            <template #sel-status_dedup="{ baris }">
                <Badge :variant="baris.status_dedup === 'asli' ? 'outline' : 'secondary'" class="capitalize">
                    {{ baris.status_dedup }}
                </Badge>
            </template>
        </DataTable>
    </LayoutPortal>
</template>
