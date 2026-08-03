<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { AksiBaris, FilterDefinisi, KolomDefinisi, OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';

interface BarisMedia {
    id: number;
    nama: string;
    domain: string | null;
    tier: 'nasional' | 'regional' | 'lokal';
    jenis: string;
    partner: boolean;
    aktif: boolean;
    sumber_feed_count: number;
}

const props = defineProps<{
    media: { data: BarisMedia[] } & PaginasiMeta;
    opsi: Record<string, OpsiFilter[]>;
}>();

const kolom: KolomDefinisi[] = [
    { kunci: 'nama', judul: 'Nama', bisaDiurutkan: true },
    { kunci: 'domain', judul: 'Domain', bisaDiurutkan: true },
    { kunci: 'tier', judul: 'Tier', bisaDiurutkan: true, lebar: 'w-28' },
    { kunci: 'jenis', judul: 'Jenis', bisaDiurutkan: true, lebar: 'w-24' },
    { kunci: 'sumber_feed_count', judul: 'Sumber', kelas: 'angka text-right', lebar: 'w-20' },
    { kunci: 'aktif', judul: 'Status', lebar: 'w-28' },
];

const filter: FilterDefinisi[] = [
    { kunci: 'tier', label: 'Tier', opsi: props.opsi.tier },
    { kunci: 'jenis', label: 'Jenis', opsi: props.opsi.jenis },
    { kunci: 'partner', label: 'Kerja sama', opsi: props.opsi.partner },
    { kunci: 'aktif', label: 'Status', opsi: props.opsi.aktif },
];

const aksiBaris: AksiBaris<BarisMedia>[] = [
    { label: 'Ubah', href: (baris) => `/admin/media/${baris.id}/edit` },
    {
        label: 'Nonaktifkan',
        merusak: true,
        onKlik: (baris) => {
            if (confirm(`Nonaktifkan ${baris.nama}? Artikel yang sudah terkumpul tetap tersimpan.`)) {
                router.delete(`/admin/media/${baris.id}`, { preserveScroll: true });
            }
        },
    },
];

const warnaTier: Record<BarisMedia['tier'], string> = {
    nasional: 'bg-tier-nasional/10 text-tier-nasional',
    regional: 'bg-tier-regional/10 text-tier-regional',
    lokal: 'bg-tier-lokal/10 text-tier-lokal',
};
</script>

<template>
    <Head title="Media" />

    <LayoutAdmin judul="Media" :breadcrumbs="[{ title: 'Media', href: '/admin/media' }]">
        <DataTable
            :kolom="kolom"
            :data="media.data"
            :meta="media"
            :filter="filter"
            pencarian
            :aksi-baris="aksiBaris"
            url-basis="/admin/media"
            judul-kosong="Belum ada media"
            keterangan-kosong="Tambahkan media partner lebih dulu, lalu daftarkan sumber feed-nya."
        >
            <template #aksi>
                <Button as-child size="sm" class="ml-auto h-8">
                    <Link href="/admin/media/create">
                        <Plus class="mr-1.5 h-4 w-4" />
                        Tambah media
                    </Link>
                </Button>
            </template>

            <template #sel-nama="{ baris }">
                <div class="flex items-center gap-2">
                    <span class="font-medium">{{ baris.nama }}</span>
                    <Badge v-if="baris.partner" variant="secondary" class="text-xs">Partner</Badge>
                </div>
            </template>

            <template #sel-domain="{ baris }">
                <span class="text-muted-foreground">{{ baris.domain ?? '-' }}</span>
            </template>

            <template #sel-tier="{ baris }">
                <span class="rounded px-1.5 py-0.5 text-xs font-medium capitalize" :class="warnaTier[baris.tier]">
                    {{ baris.tier }}
                </span>
            </template>

            <template #sel-jenis="{ baris }">
                <span class="capitalize">{{ baris.jenis }}</span>
            </template>

            <template #sel-aktif="{ baris }">
                <Badge :variant="baris.aktif ? 'outline' : 'secondary'">
                    {{ baris.aktif ? 'Aktif' : 'Nonaktif' }}
                </Badge>
            </template>
        </DataTable>
    </LayoutAdmin>
</template>
