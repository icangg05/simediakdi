<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { AksiBaris, FilterDefinisi, KolomDefinisi, OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { Head, Link, router } from '@inertiajs/vue3';
import { formatDistanceToNow } from 'date-fns';
import { id } from 'date-fns/locale';
import { AlertTriangle, Plus } from 'lucide-vue-next';

interface BarisSumber {
    id: number;
    nama: string;
    tipe: 'rss' | 'scrape' | 'google_news';
    url: string;
    aktif: boolean;
    gagal_berturut: number;
    dijalankan_terakhir_at: string | null;
    pesan_error_terakhir: string | null;
    media: { id: number; nama: string } | null;
}

const props = defineProps<{
    sumberFeed: { data: BarisSumber[] } & PaginasiMeta;
    opsi: Record<string, OpsiFilter[]>;
}>();

const kolom: KolomDefinisi[] = [
    { kunci: 'nama', judul: 'Nama', bisaDiurutkan: true },
    { kunci: 'media', judul: 'Media', lebar: 'w-44' },
    { kunci: 'tipe', judul: 'Tipe', bisaDiurutkan: true, lebar: 'w-32' },
    { kunci: 'dijalankan_terakhir_at', judul: 'Dijalankan', bisaDiurutkan: true, lebar: 'w-36' },
    { kunci: 'gagal_berturut', judul: 'Gagal', bisaDiurutkan: true, kelas: 'angka text-right', lebar: 'w-20' },
    { kunci: 'aktif', judul: 'Status', lebar: 'w-28' },
];

const filter: FilterDefinisi[] = [
    { kunci: 'tipe', label: 'Tipe', opsi: props.opsi.tipe },
    { kunci: 'aktif', label: 'Status', opsi: props.opsi.aktif },
    { kunci: 'media', label: 'Media', opsi: props.opsi.media },
];

const aksiBaris: AksiBaris<BarisSumber>[] = [
    { label: 'Ubah', href: (baris) => `/admin/sumber-feed/${baris.id}/edit` },
    {
        label: 'Hapus',
        merusak: true,
        onKlik: (baris) => {
            if (confirm(`Hapus sumber ${baris.nama}?`)) {
                router.delete(`/admin/sumber-feed/${baris.id}`, { preserveScroll: true });
            }
        },
    },
];

const labelTipe: Record<BarisSumber['tipe'], string> = {
    rss: 'RSS',
    scrape: 'Scraping',
    google_news: 'Google News',
};

function sejak(waktu: string | null): string {
    if (!waktu) return 'Belum pernah';
    return formatDistanceToNow(new Date(waktu), { addSuffix: true, locale: id });
}
</script>

<template>
    <Head title="Sumber feed" />

    <LayoutAdmin judul="Sumber feed" :breadcrumbs="[{ title: 'Sumber feed', href: '/admin/sumber-feed' }]">
        <DataTable
            :kolom="kolom"
            :data="sumberFeed.data"
            :meta="sumberFeed"
            :filter="filter"
            pencarian
            :aksi-baris="aksiBaris"
            url-basis="/admin/sumber-feed"
            judul-kosong="Belum ada sumber feed"
            keterangan-kosong="Daftarkan RSS media, atau satu sumber Google News dengan kata kunci Kendari."
        >
            <template #aksi>
                <Button as-child size="sm" class="ml-auto h-8">
                    <Link href="/admin/sumber-feed/create">
                        <Plus class="mr-1.5 h-4 w-4" />
                        Tambah sumber
                    </Link>
                </Button>
            </template>

            <template #sel-nama="{ baris }">
                <div>
                    <span class="font-medium">{{ baris.nama }}</span>
                    <p class="truncate text-xs text-muted-foreground">{{ baris.url }}</p>
                </div>
            </template>

            <template #sel-media="{ baris }">
                <span v-if="baris.media">{{ baris.media.nama }}</span>
                <span v-else class="text-muted-foreground">Lintas media</span>
            </template>

            <template #sel-tipe="{ baris }">{{ labelTipe[baris.tipe] }}</template>

            <template #sel-dijalankan_terakhir_at="{ baris }">
                <span class="text-muted-foreground">{{ sejak(baris.dijalankan_terakhir_at) }}</span>
            </template>

            <template #sel-gagal_berturut="{ baris }">
                <span
                    v-if="baris.gagal_berturut > 0"
                    class="inline-flex items-center gap-1"
                    :class="baris.gagal_berturut >= 3 ? 'text-sentimen-negatif' : 'text-sentimen-review'"
                    :title="baris.pesan_error_terakhir ?? undefined"
                >
                    <AlertTriangle class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ baris.gagal_berturut }}
                </span>
                <span v-else class="text-muted-foreground">0</span>
            </template>

            <template #sel-aktif="{ baris }">
                <Badge :variant="baris.aktif ? 'outline' : 'secondary'">
                    {{ baris.aktif ? 'Aktif' : 'Nonaktif' }}
                </Badge>
            </template>
        </DataTable>
    </LayoutAdmin>
</template>
