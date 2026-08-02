<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { AksiBaris, FilterDefinisi, KolomDefinisi, OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';

interface BarisKonteks {
    id: number;
    nama: string;
    deskripsi: string | null;
    kata_kunci: string[] | null;
    utama: boolean;
    urutan: number;
    aktif: boolean;
    jumlah_relevan: number;
}

const props = defineProps<{
    konteks: { data: BarisKonteks[] } & PaginasiMeta;
    opsi: Record<string, OpsiFilter[]>;
}>();

const kolom: KolomDefinisi[] = [
    { kunci: 'urutan', judul: '#', bisaDiurutkan: true, kelas: 'angka', lebar: 'w-12' },
    { kunci: 'nama', judul: 'Konteks', bisaDiurutkan: true },
    { kunci: 'kata_kunci', judul: 'Kata kunci penyaring' },
    { kunci: 'jumlah_relevan', judul: 'Artikel relevan', kelas: 'angka text-right', lebar: 'w-32' },
    { kunci: 'aktif', judul: 'Status', lebar: 'w-28' },
];

const filter: FilterDefinisi[] = [{ kunci: 'aktif', label: 'Status', opsi: props.opsi.aktif }];

const aksiBaris: AksiBaris<BarisKonteks>[] = [
    { label: 'Ubah', href: (baris) => `/admin/konteks/${baris.id}/edit` },
    {
        label: 'Nonaktifkan',
        merusak: true,
        onKlik: (baris) => {
            if (confirm(`Nonaktifkan ${baris.nama}? Artikel baru berhenti dinilai terhadapnya, analisis yang sudah ada tetap tersimpan.`)) {
                router.delete(`/admin/konteks/${baris.id}`, { preserveScroll: true });
            }
        },
    },
];
</script>

<template>
    <Head title="Konteks pantauan" />

    <LayoutAdmin judul="Konteks pantauan" :breadcrumbs="[{ title: 'Konteks', href: '/admin/konteks' }]">
        <p class="text-xs text-muted-foreground">
            Sasaran penilaian sentimen. Nama konteks dikirim apa adanya ke model, jadi tulis seperti kalimat yang
            wajar dibaca. Menambah konteks menaikkan beban inferensi secara linear — kata kunci penyaring yang
            membuatnya tetap murah.
        </p>

        <DataTable
            :kolom="kolom"
            :data="konteks.data"
            :meta="konteks"
            :filter="filter"
            pencarian
            :aksi-baris="aksiBaris"
            url-basis="/admin/konteks"
            judul-kosong="Belum ada konteks pantauan"
            keterangan-kosong="Tanpa konteks, tidak ada yang dinilai. Tambahkan minimal satu dan tandai sebagai utama."
        >
            <template #aksi>
                <Button as-child size="sm" class="ml-auto h-8">
                    <Link href="/admin/konteks/create">
                        <Plus class="mr-1.5 h-4 w-4" />
                        Tambah konteks
                    </Link>
                </Button>
            </template>

            <template #sel-nama="{ baris }">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-medium">{{ baris.nama }}</span>
                        <Badge v-if="baris.utama" variant="secondary">Utama</Badge>
                    </div>
                    <p v-if="baris.deskripsi" class="truncate text-xs text-muted-foreground">{{ baris.deskripsi }}</p>
                </div>
            </template>

            <template #sel-kata_kunci="{ baris }">
                <span v-if="!baris.kata_kunci?.length" class="text-xs text-muted-foreground">
                    Tanpa penyaring — semua artikel dikirim ke model
                </span>
                <span v-else class="text-xs text-muted-foreground">
                    {{ baris.kata_kunci.slice(0, 4).join(', ') }}
                    <template v-if="baris.kata_kunci.length > 4">
                        +{{ baris.kata_kunci.length - 4 }} lagi
                    </template>
                </span>
            </template>

            <template #sel-aktif="{ baris }">
                <Badge :variant="baris.aktif ? 'outline' : 'secondary'">
                    {{ baris.aktif ? 'Aktif' : 'Nonaktif' }}
                </Badge>
            </template>
        </DataTable>
    </LayoutAdmin>
</template>
