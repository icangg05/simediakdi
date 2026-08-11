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
    sumber_feed_aktif_count: number;
    /** Null berarti pencarian feed otomatis belum selesai dijalankan. */
    feed_dicari_at: string | null;
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
    { kunci: 'sumber_feed_count', judul: 'Pengambilan', lebar: 'w-44' },
    { kunci: 'aktif', judul: 'Status', lebar: 'w-28' },
];

const filter: FilterDefinisi[] = [
    { kunci: 'tier', label: 'Tier', opsi: props.opsi.tier },
    { kunci: 'jenis', label: 'Jenis', opsi: props.opsi.jenis },
    { kunci: 'partner', label: 'Kerja sama', opsi: props.opsi.partner },
    { kunci: 'aktif', label: 'Status', opsi: props.opsi.aktif },
];

const aksiBaris: AksiBaris<BarisMedia>[] = [
    { label: 'Kelola', href: (baris) => `/admin/media/${baris.id}` },
    {
        // Crawl berjalan di worker, jadi halaman ini tidak perlu menunggu.
        // Hasilnya dilihat di Log crawl, bukan di sini.
        label: 'Crawl sekarang',
        onKlik: (baris) => router.post(`/admin/media/${baris.id}/crawl`, {}, { preserveScroll: true }),
    },
    {
        // Saklar, bukan penghapusan. Dulu tombol berlabel "Nonaktifkan" di sini
        // memanggil soft delete, sehingga medianya lenyap dari daftar dan tidak
        // ada cara menghidupkannya lagi dari antarmuka.
        label: 'Aktifkan atau nonaktifkan',
        onKlik: (baris) => router.post(`/admin/media/${baris.id}/aktif`, {}, { preserveScroll: true }),
    },
    {
        label: 'Hapus',
        merusak: true,
        onKlik: (baris) => {
            if (confirm(`Hapus ${baris.nama}? Artikel yang sudah terkumpul tetap tersimpan.`)) {
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

/**
 * Keadaan pengambilan satu media, dalam satu frasa.
 *
 * Sejak seluruh media ditarik secara bawaan, pertanyaan yang dibawa admin ke
 * halaman ini bukan lagi "berapa sumbernya" melainkan "mana yang tidak jalan".
 * Angka telanjang tidak menjawab itu: nol bisa berarti masih dicari, bisa
 * berarti sudah dicari dan tidak ketemu, dan keduanya menuntut tindakan
 * berbeda.
 */
function pengambilan(baris: BarisMedia): { teks: string; kelas: string } {
    if (!baris.aktif) {
        return { teks: 'Dihentikan', kelas: 'text-muted-foreground' };
    }

    if (baris.sumber_feed_aktif_count > 0) {
        return { teks: `${baris.sumber_feed_aktif_count} sumber aktif`, kelas: 'text-sentimen-positif' };
    }

    if (baris.sumber_feed_count > 0) {
        return { teks: 'Semua sumber mati', kelas: 'text-sentimen-negatif' };
    }

    return baris.feed_dicari_at === null
        ? { teks: 'Mencari RSS', kelas: 'text-aksen-biru' }
        : { teks: 'RSS tidak ketemu', kelas: 'text-sentimen-review' };
}
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
            nomor
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
                    <Link :href="`/admin/media/${baris.id}`" class="font-medium hover:underline">{{ baris.nama }}</Link>
                    <Badge v-if="baris.partner" variant="secondary" class="text-xs">Partner</Badge>
                </div>
            </template>

            <template #sel-sumber_feed_count="{ baris }">
                <span class="text-sm" :class="pengambilan(baris).kelas">{{ pengambilan(baris).teks }}</span>
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
