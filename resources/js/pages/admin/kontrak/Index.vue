<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import ProgresKontrak from '@/components/domain/ProgresKontrak.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { AksiBaris, FilterDefinisi, KolomDefinisi, OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { Head, Link } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { Plus } from 'lucide-vue-next';

interface Baris {
    id: number;
    nomor: string | null;
    judul: string;
    jenis: string;
    status: string;
    nilai: string | null;
    target_pemuatan: number | null;
    tanggal_mulai: string;
    tanggal_akhir: string;
    media: { id: number; nama: string } | null;
    progres: {
        terverifikasi: number;
        menunggu: number;
        target: number | null;
        persen: number | null;
        sisa_hari: number;
        tertinggal: boolean;
    };
}

const props = defineProps<{
    kontrak: { data: Baris[] } & PaginasiMeta;
    opsi: Record<string, OpsiFilter[]>;
}>();

const { formatRupiah } = useFormatAngka();

const kolom: KolomDefinisi[] = [
    { kunci: 'judul', judul: 'Kontrak', bisaDiurutkan: true },
    { kunci: 'media', judul: 'Media', lebar: 'w-40' },
    { kunci: 'tanggal_akhir', judul: 'Periode', bisaDiurutkan: true, lebar: 'w-44' },
    { kunci: 'progres', judul: 'Realisasi', lebar: 'w-56' },
    { kunci: 'nilai', judul: 'Nilai', kelas: 'angka text-right', lebar: 'w-36' },
    { kunci: 'status', judul: 'Status', lebar: 'w-24' },
];

const filter: FilterDefinisi[] = [
    { kunci: 'status', label: 'Status', opsi: props.opsi.status },
    { kunci: 'jenis', label: 'Jenis', opsi: props.opsi.jenis },
    { kunci: 'media', label: 'Media', opsi: props.opsi.media },
];

const aksiBaris: AksiBaris<Baris>[] = [
    { label: 'Buka', href: (b) => `/admin/kontrak/${b.id}` },
    { label: 'Ubah', href: (b) => `/admin/kontrak/${b.id}/edit` },
];

const tanggal = (n: string) => format(new Date(n), 'd MMM yyyy', { locale: id });

const varianStatus: Record<string, string> = {
    aktif: 'outline',
    draft: 'secondary',
    selesai: 'secondary',
    batal: 'destructive',
};
</script>

<template>
    <Head title="Kontrak" />

    <LayoutAdmin judul="Kontrak" :breadcrumbs="[{ title: 'Kontrak', href: '/admin/kontrak' }]">
        <DataTable
            :kolom="kolom"
            :data="kontrak.data"
            :meta="kontrak"
            :filter="filter"
            pencarian
            :aksi-baris="aksiBaris"
            url-basis="/admin/kontrak"
            judul-kosong="Belum ada kontrak"
            keterangan-kosong="Tambahkan kontrak kerja sama, lalu artikel yang sudah ter-crawl akan tercatat sebagai pemuatan secara otomatis."
        >
            <template #aksi>
                <Button as-child size="sm" class="ml-auto h-8">
                    <Link href="/admin/kontrak/create">
                        <Plus class="mr-1.5 h-4 w-4" />
                        Tambah kontrak
                    </Link>
                </Button>
            </template>

            <template #sel-judul="{ baris }">
                <div class="min-w-0">
                    <Link :href="`/admin/kontrak/${baris.id}`" class="font-medium hover:underline">
                        {{ baris.judul }}
                    </Link>
                    <p class="text-xs text-muted-foreground">
                        {{ baris.nomor ?? 'Tanpa nomor' }} · {{ baris.jenis }}
                    </p>
                </div>
            </template>

            <template #sel-media="{ baris }">{{ baris.media?.nama ?? '-' }}</template>

            <template #sel-tanggal_akhir="{ baris }">
                <span class="text-xs text-muted-foreground">
                    {{ tanggal(baris.tanggal_mulai) }} - {{ tanggal(baris.tanggal_akhir) }}
                </span>
            </template>

            <template #sel-progres="{ baris }">
                <ProgresKontrak
                    :terverifikasi="baris.progres.terverifikasi"
                    :menunggu="baris.progres.menunggu"
                    :target="baris.progres.target"
                    :persen="baris.progres.persen"
                    :sisa-hari="baris.progres.sisa_hari"
                    :tertinggal="baris.progres.tertinggal"
                />
            </template>

            <template #sel-nilai="{ baris }">{{ formatRupiah(baris.nilai) }}</template>

            <template #sel-status="{ baris }">
                <Badge :variant="varianStatus[baris.status] ?? 'secondary'" class="capitalize">
                    {{ baris.status }}
                </Badge>
            </template>
        </DataTable>
    </LayoutAdmin>
</template>
