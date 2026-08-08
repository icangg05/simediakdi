<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import LayoutPortal from '@/layouts/LayoutPortal.vue';
import type { KolomDefinisi, PaginasiMeta } from '@/types/tabel';
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
}

const props = defineProps<{
    artikel: { data: Baris[] } & PaginasiMeta;
}>();

const kolom: KolomDefinisi[] = [
    { kunci: 'judul', judul: 'Judul', bisaDiurutkan: true },
    { kunci: 'diambil_at', judul: 'Terpantau', bisaDiurutkan: true, lebar: 'w-40' },
    { kunci: 'jumlah_kata', judul: 'Kata', kelas: 'angka text-right', lebar: 'w-20' },
];

const waktu = (n: string) => format(new Date(n), 'd MMM yyyy, HH:mm', { locale: id });
</script>

<template>
    <Head title="Berita saya" />

    <LayoutPortal judul="Berita saya" :breadcrumbs="[{ title: 'Berita saya', href: '/portal/berita' }]">
        <p class="text-sm text-muted-foreground">
            Berita media Anda yang tertangkap sistem. Satu URL hanya masuk sekali, jadi daftar ini tidak memuat baris kembar.
        </p>

        <DataTable
            :kolom="kolom"
            :data="props.artikel.data"
            :meta="props.artikel"
            pencarian
            url-basis="/portal/berita"
            judul-kosong="Belum ada berita yang tertangkap"
            keterangan-kosong="Sistem membaca RSS media Anda tiap 15 menit. Kalau berita baru tidak muncul juga dalam sehari, kabari Diskominfo agar sumber feed-nya diperiksa."
        >
            <template #sel-judul="{ baris }">
                <a :href="baris.url" target="_blank" rel="noopener noreferrer" class="inline-flex items-start gap-1 font-medium hover:underline">
                    <span class="line-clamp-2">{{ baris.judul }}</span>
                    <ExternalLink class="mt-0.5 h-3 w-3 shrink-0 text-muted-foreground" aria-hidden="true" />
                </a>
            </template>

            <template #sel-diambil_at="{ baris }">
                <span class="text-xs text-muted-foreground">{{ waktu(baris.diambil_at) }}</span>
            </template>
        </DataTable>
    </LayoutPortal>
</template>
