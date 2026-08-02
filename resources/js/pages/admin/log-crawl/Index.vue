<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { FilterDefinisi, KolomDefinisi, OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { Head } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';

interface BarisLog {
    id: number;
    dimulai_at: string;
    selesai_at: string | null;
    jumlah_ditemukan: number;
    jumlah_baru: number;
    jumlah_salinan: number;
    status: 'sukses' | 'sebagian' | 'gagal';
    pesan: string | null;
    sumber_feed: { id: number; nama: string; media: { nama: string } | null } | null;
}

const props = defineProps<{
    log: { data: BarisLog[] } & PaginasiMeta;
    opsi: Record<string, OpsiFilter[]>;
}>();

const kolom: KolomDefinisi[] = [
    { kunci: 'dimulai_at', judul: 'Waktu', bisaDiurutkan: true, lebar: 'w-40' },
    { kunci: 'sumber_feed', judul: 'Sumber' },
    { kunci: 'jumlah_ditemukan', judul: 'Ditemukan', bisaDiurutkan: true, kelas: 'angka text-right', lebar: 'w-24' },
    { kunci: 'jumlah_baru', judul: 'Baru', bisaDiurutkan: true, kelas: 'angka text-right', lebar: 'w-20' },
    { kunci: 'jumlah_salinan', judul: 'Sudah ada', kelas: 'angka text-right', lebar: 'w-24' },
    { kunci: 'status', judul: 'Status', lebar: 'w-28' },
];

const filter: FilterDefinisi[] = [
    { kunci: 'status', label: 'Status', opsi: props.opsi.status },
    { kunci: 'sumber', label: 'Sumber', opsi: props.opsi.sumber },
];

const waktu = (nilai: string) => format(new Date(nilai), 'd MMM yyyy, HH:mm:ss', { locale: id });

const varianStatus: Record<BarisLog['status'], string> = {
    sukses: 'outline',
    sebagian: 'secondary',
    gagal: 'destructive',
};
</script>

<template>
    <Head title="Log crawl" />

    <LayoutAdmin judul="Log crawl" :breadcrumbs="[{ title: 'Log crawl', href: '/admin/log-crawl' }]">
        <p class="text-xs text-muted-foreground">
            Disimpan 90 hari, lalu dihapus otomatis. Kolom "sudah ada" berisi item feed yang URL-nya sudah pernah
            masuk; salinan yang ketahuan dari kemiripan isi baru terdeteksi setelah halamannya diunduh.
        </p>

        <DataTable
            :kolom="kolom"
            :data="log.data"
            :meta="log"
            :filter="filter"
            pencarian
            url-basis="/admin/log-crawl"
            judul-kosong="Belum ada log crawl"
            keterangan-kosong="Log terisi setiap kali crawler berjalan, baik berhasil maupun gagal."
        >
            <template #sel-dimulai_at="{ baris }">
                <span class="text-muted-foreground">{{ waktu(baris.dimulai_at) }}</span>
            </template>

            <template #sel-sumber_feed="{ baris }">
                <div class="min-w-0">
                    <p class="truncate font-medium">{{ baris.sumber_feed?.nama ?? 'Sumber terhapus' }}</p>
                    <p v-if="baris.pesan" class="truncate text-xs" :class="baris.status === 'gagal' ? 'text-sentimen-negatif' : 'text-muted-foreground'">
                        {{ baris.pesan }}
                    </p>
                </div>
            </template>

            <template #sel-status="{ baris }">
                <Badge :variant="varianStatus[baris.status]" class="capitalize">{{ baris.status }}</Badge>
            </template>
        </DataTable>
    </LayoutAdmin>
</template>
