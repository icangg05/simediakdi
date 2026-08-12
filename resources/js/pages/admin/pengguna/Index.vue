<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import KopHalaman from '@/components/domain/KopHalaman.vue';
import PilKop from '@/components/domain/PilKop.vue';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { AksiBaris, FilterDefinisi, KolomDefinisi, OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { Head, Link, router } from '@inertiajs/vue3';
import { formatDistanceToNow } from 'date-fns';
import { id } from 'date-fns/locale';
import { Plus, ScrollText, Users } from 'lucide-vue-next';
import { computed } from 'vue';

interface Baris {
    id: number;
    name: string;
    username: string;
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

const sejak = (n: string | null) => (n ? formatDistanceToNow(new Date(n), { addSuffix: true, locale: id }) : 'Belum pernah');

/**
 * Peran diwarnai menurut apa yang boleh dilakukannya, bukan menurut selera.
 *
 * Navy untuk peran yang boleh menulis, karena navy di panel ini berarti aksi
 * dan wewenang. Biru untuk pembaca yang tidak pernah menulis apa pun. Toska
 * untuk media, warna yang di seluruh panel sudah berarti pihak yang beritanya
 * dihitung. Ronanya tidak meminjam palet sentimen: peran bukan kabar baik
 * maupun buruk.
 */
const warnaPeran: Record<string, string> = {
    superadmin: 'bg-brand-lembut text-brand ring-brand/20 dark:text-white dark:ring-white/20',
    walikota: 'bg-aksen-biru/10 text-aksen-biru ring-aksen-biru/25',
    media: 'bg-aksen-toska/10 text-aksen-toska ring-aksen-toska/25',
};

const nonaktif = computed(() => props.pengguna.data.filter((p) => !p.aktif).length);
</script>

<template>
    <Head title="Pengguna" />

    <LayoutAdmin :breadcrumbs="[{ title: 'Pengguna', href: '/admin/pengguna' }]">
        <KopHalaman
            judul="Pengguna"
            keterangan="Registrasi mandiri tidak dibuka, halaman ini satu-satunya jalan akun dibuat. Setiap perubahan tercatat di audit log."
        >
            <template #aksi>
                <Link
                    href="/admin/pengguna/create"
                    class="tekan inline-flex items-center gap-2 rounded-lg bg-white px-3.5 py-2 text-xs font-semibold text-brand transition-colors hover:bg-white/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-brand"
                >
                    <Plus class="size-3.5" aria-hidden="true" />
                    Tambah pengguna
                </Link>
            </template>

            <PilKop :ikon="Users">
                <span class="angka">{{ pengguna.total }}</span> akun terdaftar
            </PilKop>
            <!-- Akun nonaktif bukan kabar buruk, ia keadaan yang disengaja.
                 Karena itu netral, bukan merah. -->
            <PilKop v-if="nonaktif > 0">
                <span class="angka">{{ nonaktif }}</span> nonaktif di halaman ini
            </PilKop>
            <PilKop :ikon="ScrollText">Akun dinonaktifkan, tidak pernah dihapus</PilKop>
        </KopHalaman>

        <DataTable
            :kolom="kolom"
            :data="pengguna.data"
            :meta="pengguna"
            :filter="filter"
            pencarian
            :aksi-baris="aksiBaris"
            nomor
            url-basis="/admin/pengguna"
            judul-kosong="Belum ada pengguna"
        >
            <template #sel-name="{ baris }">
                <div class="flex min-w-0 items-center gap-2.5">
                    <!-- Inisial, bukan foto. Sistem tidak menyimpan avatar dan
                         tidak akan menyimpannya, jadi bidang berhuruf adalah
                         penanda paling jujur yang bisa dipasang di sini. Ia
                         memberi tiap baris satu titik jangkar sehingga mata
                         tidak kehilangan barisnya saat menggeser ke kanan. -->
                    <span
                        class="grid size-8 shrink-0 place-items-center rounded-full text-xs font-semibold ring-1 ring-inset"
                        :class="baris.aktif ? warnaPeran[baris.peran] : 'bg-muted text-muted-foreground ring-border'"
                        aria-hidden="true"
                    >
                        {{ baris.name.charAt(0).toUpperCase() }}
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ baris.name }}</p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ baris.username }} &middot; {{ baris.email }}<template v-if="baris.jabatan"> &middot; {{ baris.jabatan }}</template>
                        </p>
                    </div>
                </div>
            </template>

            <template #sel-peran="{ baris }">
                <span
                    class="inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                    :class="warnaPeran[baris.peran] ?? 'bg-muted text-muted-foreground ring-border'"
                >
                    {{ baris.peran_label }}
                </span>
            </template>

            <template #sel-media="{ baris }">
                <span :class="baris.media ? '' : 'text-muted-foreground'">{{ baris.media?.nama ?? '-' }}</span>
            </template>

            <template #sel-login_terakhir_at="{ baris }">
                <span class="text-xs" :class="baris.login_terakhir_at ? 'text-muted-foreground' : 'text-muted-foreground/70'">
                    {{ sejak(baris.login_terakhir_at) }}
                </span>
            </template>

            <template #sel-aktif="{ baris }">
                <span class="inline-flex items-center gap-1.5 text-xs" :class="baris.aktif ? '' : 'text-muted-foreground'">
                    <span class="size-1.5 shrink-0 rounded-full" :class="baris.aktif ? 'bg-sentimen-positif' : 'bg-muted-foreground/50'" />
                    {{ baris.aktif ? 'Aktif' : 'Nonaktif' }}
                </span>
            </template>
        </DataTable>
    </LayoutAdmin>
</template>
