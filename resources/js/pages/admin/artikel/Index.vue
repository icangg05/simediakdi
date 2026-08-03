<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { FilterDefinisi, KolomDefinisi, OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { Head, router } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { ExternalLink } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface BarisArtikel {
    id: number;
    judul: string;
    url: string;
    penulis: string | null;
    jumlah_kata: number | null;
    dipublikasikan_at: string | null;
    diambil_at: string;
    status_dedup: 'asli' | 'salinan';
    status_proses: string;
    artikel_induk_id: number | null;
    pesan_gagal: string | null;
    salinan_count: number;
    media: { id: number; nama: string } | null;
}

const props = defineProps<{
    artikel: { data: BarisArtikel[] } & PaginasiMeta;
    opsi: Record<string, OpsiFilter[]>;
    tanggal: { dari: string | null; sampai: string | null };
}>();

const kolom: KolomDefinisi[] = [
    { kunci: 'judul', judul: 'Judul', bisaDiurutkan: true },
    { kunci: 'media', judul: 'Media', lebar: 'w-40' },
    { kunci: 'diambil_at', judul: 'Diambil', bisaDiurutkan: true, lebar: 'w-32' },
    { kunci: 'jumlah_kata', judul: 'Kata', bisaDiurutkan: true, kelas: 'angka text-right', lebar: 'w-20' },
    { kunci: 'status_dedup', judul: 'Dedup', lebar: 'w-28' },
    { kunci: 'status_proses', judul: 'Proses', lebar: 'w-28' },
];

const filter: FilterDefinisi[] = [
    { kunci: 'media', label: 'Media', opsi: props.opsi.media },
    { kunci: 'dedup', label: 'Dedup', opsi: props.opsi.dedup },
    { kunci: 'proses', label: 'Proses', opsi: props.opsi.proses },
];

const dari = ref(props.tanggal.dari ?? '');
const sampai = ref(props.tanggal.sampai ?? '');

watch([dari, sampai], ([d, s]) => {
    const params = new URLSearchParams(window.location.search);
    if (d) params.set('dari', d);
    else params.delete('dari');

    if (s) params.set('sampai', s);
    else params.delete('sampai');
    params.delete('halaman');

    router.get('/admin/artikel', Object.fromEntries(params), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
});

const tanggalSingkat = (waktu: string | null) =>
    waktu ? format(new Date(waktu), 'd MMM yyyy, HH:mm', { locale: id }) : '-';

const labelProses: Record<string, string> = {
    mentah: 'Mentah',
    isi_diambil: 'Isi diambil',
    dianalisis: 'Dianalisis',
    selesai: 'Selesai',
    gagal: 'Gagal',
};
</script>

<template>
    <Head title="Artikel" />

    <LayoutAdmin judul="Artikel" :breadcrumbs="[{ title: 'Artikel', href: '/admin/artikel' }]">
        <DataTable
            :kolom="kolom"
            :data="artikel.data"
            :meta="artikel"
            :filter="filter"
            pencarian
            url-basis="/admin/artikel"
            judul-kosong="Belum ada artikel"
            keterangan-kosong="Daftarkan sumber feed lalu jalankan crawler. Artikel akan muncul di sini beberapa menit kemudian."
        >
            <template #aksi>
                <div class="ml-auto flex items-end gap-2">
                    <div class="grid gap-1">
                        <Label for="dari" class="text-xs text-muted-foreground">Diambil dari</Label>
                        <Input id="dari" v-model="dari" type="date" class="h-8 w-36" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="sampai" class="text-xs text-muted-foreground">sampai</Label>
                        <Input id="sampai" v-model="sampai" type="date" class="h-8 w-36" />
                    </div>
                </div>
            </template>

            <template #sel-judul="{ baris }">
                <div class="min-w-0">
                    <a
                        :href="baris.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-start gap-1 font-medium hover:underline"
                    >
                        <span class="line-clamp-2">{{ baris.judul }}</span>
                        <ExternalLink class="mt-0.5 h-3 w-3 shrink-0 text-muted-foreground" aria-hidden="true" />
                    </a>
                    <p v-if="baris.penulis" class="text-xs text-muted-foreground">{{ baris.penulis }}</p>
                    <p v-if="baris.pesan_gagal" class="text-xs text-sentimen-negatif">{{ baris.pesan_gagal }}</p>
                </div>
            </template>

            <template #sel-media="{ baris }">
                <span v-if="baris.media">{{ baris.media.nama }}</span>
                <span v-else class="text-muted-foreground">Belum ditautkan</span>
            </template>

            <template #sel-diambil_at="{ baris }">
                <span class="text-muted-foreground">{{ tanggalSingkat(baris.diambil_at) }}</span>
            </template>

            <template #sel-status_dedup="{ baris }">
                <Badge v-if="baris.status_dedup === 'salinan'" variant="secondary">
                    Salinan #{{ baris.artikel_induk_id }}
                </Badge>
                <span v-else-if="baris.salinan_count > 0" class="text-xs text-muted-foreground">
                    Asli, {{ baris.salinan_count }} salinan
                </span>
                <span v-else class="text-xs text-muted-foreground">Asli</span>
            </template>

            <template #sel-status_proses="{ baris }">
                <Badge :variant="baris.status_proses === 'gagal' ? 'destructive' : 'outline'">
                    {{ labelProses[baris.status_proses] ?? baris.status_proses }}
                </Badge>
            </template>
        </DataTable>
    </LayoutAdmin>
</template>
