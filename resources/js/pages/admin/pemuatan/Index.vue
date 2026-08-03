<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { FilterDefinisi, KolomDefinisi, OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { Head, router } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { Camera, Check, ExternalLink, X } from 'lucide-vue-next';
import { ref } from 'vue';

interface Baris {
    id: number;
    url: string;
    judul: string | null;
    tanggal_muat: string | null;
    sumber_catatan: string;
    status_ekstraksi: string;
    status_verifikasi: string;
    alasan_penolakan: string | null;
    media: { id: number; nama: string } | null;
    kontrak: { id: number; judul: string; nomor: string | null } | null;
    pelapor: string | null;
    punya_arsip: boolean;
    punya_gambar: boolean;
    cuplikan_arsip: string | null;
}

const props = defineProps<{
    pemuatan: { data: Baris[] } & PaginasiMeta;
    jumlahMenunggu: number;
    opsi: Record<string, OpsiFilter[]>;
}>();

const kolom: KolomDefinisi[] = [
    { kunci: 'judul', judul: 'Laporan', bisaDiurutkan: false },
    { kunci: 'media', judul: 'Media', lebar: 'w-36' },
    { kunci: 'tanggal_muat', judul: 'Tanggal muat', bisaDiurutkan: true, lebar: 'w-32' },
    { kunci: 'bukti', judul: 'Bukti', lebar: 'w-24' },
    { kunci: 'aksi', judul: 'Verifikasi', lebar: 'w-52' },
];

const filter: FilterDefinisi[] = [{ kunci: 'status', label: 'Status', opsi: props.opsi.status }];

const tanggal = (n: string | null) => (n ? format(new Date(n), 'd MMM yyyy', { locale: id }) : '-');

// Alasan penolakan diketik langsung di baris. Dialog terpisah menambah satu
// klik per baris, dan antrean ini diperiksa berpuluh baris sekali duduk.
const menolak = ref<number | null>(null);
const alasan = ref('');

function verifikasi(baris: Baris) {
    router.put(`/admin/pemuatan/${baris.id}`, { status_verifikasi: 'terverifikasi' }, { preserveScroll: true });
}

function tolak(baris: Baris) {
    if (menolak.value !== baris.id) {
        menolak.value = baris.id;
        alasan.value = '';

        return;
    }

    router.put(
        `/admin/pemuatan/${baris.id}`,
        { status_verifikasi: 'ditolak', alasan_penolakan: alasan.value },
        { preserveScroll: true, onSuccess: () => (menolak.value = null) },
    );
}
</script>

<template>
    <Head title="Verifikasi pemuatan" />

    <LayoutAdmin judul="Verifikasi pemuatan" :breadcrumbs="[{ title: 'Verifikasi pemuatan', href: '/admin/pemuatan' }]">
        <p class="text-sm text-muted-foreground">
            Hanya laporan dari media yang masuk antrean ini. Pemuatan yang ditemukan crawler sudah terverifikasi sejak
            dibuat, karena tidak ada klaim pihak berkepentingan yang perlu diperiksa.
            <span v-if="props.jumlahMenunggu" class="font-medium text-foreground">
                {{ props.jumlahMenunggu }} laporan menunggu.
            </span>
        </p>

        <DataTable
            :kolom="kolom"
            :data="props.pemuatan.data"
            :meta="props.pemuatan"
            :filter="filter"
            pencarian
            url-basis="/admin/pemuatan"
            judul-kosong="Tidak ada laporan pemuatan"
            keterangan-kosong="Laporan muncul di sini setelah media mengirimkannya lewat portal."
        >
            <template #sel-judul="{ baris }">
                <div class="min-w-0 space-y-0.5">
                    <a
                        :href="baris.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-start gap-1 font-medium hover:underline"
                    >
                        <span class="line-clamp-2">{{ baris.judul ?? baris.url }}</span>
                        <ExternalLink class="mt-0.5 h-3 w-3 shrink-0 text-muted-foreground" aria-hidden="true" />
                    </a>
                    <p class="text-xs text-muted-foreground">
                        {{ baris.kontrak?.judul ?? 'Tanpa kontrak' }}
                        <template v-if="baris.pelapor"> · dilaporkan {{ baris.pelapor }}</template>
                    </p>
                    <p v-if="baris.cuplikan_arsip" class="line-clamp-2 text-xs text-muted-foreground">
                        {{ baris.cuplikan_arsip }}
                    </p>
                    <p v-if="baris.alasan_penolakan" class="text-xs text-destructive">
                        Ditolak: {{ baris.alasan_penolakan }}
                    </p>
                </div>
            </template>

            <template #sel-media="{ baris }">{{ baris.media?.nama ?? '-' }}</template>

            <template #sel-tanggal_muat="{ baris }">
                <span class="text-xs text-muted-foreground">{{ tanggal(baris.tanggal_muat) }}</span>
            </template>

            <template #sel-bukti="{ baris }">
                <div class="flex items-center gap-1.5">
                    <Button v-if="baris.punya_gambar" as-child size="sm" variant="ghost" class="h-7 px-2">
                        <a :href="`/admin/pemuatan/${baris.id}/bukti`" target="_blank" rel="noopener noreferrer">
                            <Camera class="h-3.5 w-3.5" aria-hidden="true" />
                            <span class="sr-only">Lihat tangkapan layar</span>
                        </a>
                    </Button>
                    <span v-else class="text-xs text-muted-foreground">-</span>
                    <!-- Ekstraksi gagal tidak menghalangi verifikasi (F-51),
                         jadi statusnya ditampilkan sebagai keterangan saja. -->
                    <Badge v-if="baris.status_ekstraksi === 'gagal'" variant="secondary" class="text-[10px]">
                        arsip gagal
                    </Badge>
                </div>
            </template>

            <template #sel-aksi="{ baris }">
                <div v-if="baris.status_verifikasi === 'menunggu'" class="space-y-1.5">
                    <div class="flex gap-1.5">
                        <Button size="sm" class="h-7" @click="verifikasi(baris)">
                            <Check class="mr-1 h-3.5 w-3.5" />
                            Terima
                        </Button>
                        <Button size="sm" variant="outline" class="h-7" @click="tolak(baris)">
                            <X class="mr-1 h-3.5 w-3.5" />
                            Tolak
                        </Button>
                    </div>
                    <Input
                        v-if="menolak === baris.id"
                        v-model="alasan"
                        placeholder="Alasan penolakan, dibaca media"
                        class="h-7 text-xs"
                        @keyup.enter="tolak(baris)"
                    />
                </div>
                <Badge v-else :variant="baris.status_verifikasi === 'terverifikasi' ? 'outline' : 'destructive'" class="capitalize">
                    {{ baris.status_verifikasi }}
                </Badge>
            </template>
        </DataTable>
    </LayoutAdmin>
</template>
