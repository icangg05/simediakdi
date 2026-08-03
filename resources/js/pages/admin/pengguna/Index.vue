<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { AksiBaris, FilterDefinisi, KolomDefinisi, OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { Head, Link, router } from '@inertiajs/vue3';
import { formatDistanceToNow } from 'date-fns';
import { id } from 'date-fns/locale';
import { Plus } from 'lucide-vue-next';

interface Baris {
    id: number;
    name: string;
    email: string;
    jabatan: string | null;
    aktif: boolean;
    peran: string;
    peran_label: string;
    media: { id: number; nama: string } | null;
    login_terakhir_at: string | null;
}

const props = defineProps<{
    pengguna: { data: Baris[] } & PaginasiMeta;
    opsi: Record<string, OpsiFilter[]>;
}>();

const kolom: KolomDefinisi[] = [
    { kunci: 'name', judul: 'Nama', bisaDiurutkan: true },
    { kunci: 'peran', judul: 'Peran', bisaDiurutkan: true, lebar: 'w-48' },
    { kunci: 'media', judul: 'Media', lebar: 'w-40' },
    { kunci: 'login_terakhir_at', judul: 'Login terakhir', bisaDiurutkan: true, lebar: 'w-36' },
    { kunci: 'aktif', judul: 'Status', lebar: 'w-28' },
];

const filter: FilterDefinisi[] = [
    { kunci: 'peran', label: 'Peran', opsi: props.opsi.peran },
    { kunci: 'aktif', label: 'Status', opsi: props.opsi.aktif },
    { kunci: 'media', label: 'Media', opsi: props.opsi.media },
];

const aksiBaris: AksiBaris<Baris>[] = [
    { label: 'Ubah', href: (b) => `/admin/pengguna/${b.id}/edit` },
    {
        label: 'Nonaktifkan',
        merusak: true,
        onKlik: (b) => {
            if (confirm(`Nonaktifkan akun ${b.name}? Akun tidak dihapus, jejak auditnya tetap tersimpan.`)) {
                router.delete(`/admin/pengguna/${b.id}`, { preserveScroll: true });
            }
        },
    },
];

const sejak = (n: string | null) =>
    n ? formatDistanceToNow(new Date(n), { addSuffix: true, locale: id }) : 'Belum pernah';
</script>

<template>
    <Head title="Pengguna" />

    <LayoutAdmin judul="Pengguna" :breadcrumbs="[{ title: 'Pengguna', href: '/admin/pengguna' }]">
        <p class="text-xs text-muted-foreground">
            Registrasi mandiri tidak dibuka, halaman ini satu-satunya jalan akun dibuat. Setiap perubahan
            tercatat di audit log.
        </p>

        <DataTable
            :kolom="kolom"
            :data="pengguna.data"
            :meta="pengguna"
            :filter="filter"
            pencarian
            :aksi-baris="aksiBaris"
            url-basis="/admin/pengguna"
            judul-kosong="Belum ada pengguna"
        >
            <template #aksi>
                <Button as-child size="sm" class="ml-auto h-8">
                    <Link href="/admin/pengguna/create">
                        <Plus class="mr-1.5 h-4 w-4" />
                        Tambah pengguna
                    </Link>
                </Button>
            </template>

            <template #sel-name="{ baris }">
                <div class="min-w-0">
                    <p class="font-medium">{{ baris.name }}</p>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ baris.email }}<template v-if="baris.jabatan"> · {{ baris.jabatan }}</template>
                    </p>
                </div>
            </template>

            <template #sel-peran="{ baris }">{{ baris.peran_label }}</template>

            <template #sel-media="{ baris }">
                <span :class="baris.media ? '' : 'text-muted-foreground'">{{ baris.media?.nama ?? '-' }}</span>
            </template>

            <template #sel-login_terakhir_at="{ baris }">
                <span class="text-xs text-muted-foreground">{{ sejak(baris.login_terakhir_at) }}</span>
            </template>

            <template #sel-aktif="{ baris }">
                <Badge :variant="baris.aktif ? 'outline' : 'secondary'">
                    {{ baris.aktif ? 'Aktif' : 'Nonaktif' }}
                </Badge>
            </template>
        </DataTable>
    </LayoutAdmin>
</template>
