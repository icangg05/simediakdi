<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { FilterDefinisi, KolomDefinisi, OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { Head, router } from '@inertiajs/vue3';
import { format, formatDistanceToNow } from 'date-fns';
import { id } from 'date-fns/locale';
import { RefreshCw } from 'lucide-vue-next';
import { onUnmounted, ref } from 'vue';

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
    jumlahSumberAktif: number;
    crawlTerakhir: string | null;
    crawlBerikutnya: string | null;
}>();

const sedangCrawl = ref(false);

// Crawl berjalan di worker, bukan di permintaan ini, jadi baris log baru muncul
// beberapa detik setelah tombol dilepas. Tanpa penarikan ulang berkala, admin
// menekan tombol lalu menatap tabel yang tidak berubah dan menekannya lagi.
let penarik: ReturnType<typeof setInterval> | null = null;

function berhentiMenarik() {
    if (penarik !== null) {
        clearInterval(penarik);
        penarik = null;
    }

    sedangCrawl.value = false;
}

onUnmounted(berhentiMenarik);

function crawlSekarang() {
    sedangCrawl.value = true;

    router.post(
        '/admin/log-crawl/jalankan',
        {},
        {
            preserveScroll: true,
            preserveState: true,
            showProgress: false,
            onError: berhentiMenarik,
            onSuccess: () => {
                berhentiMenarik();
                sedangCrawl.value = true;

                penarik = setInterval(
                    () => router.reload({ only: ['log', 'crawlTerakhir', 'crawlBerikutnya'], showProgress: false }),
                    5000,
                );

                // Tiga menit, sedikit di atas durasi satu crawl penuh. Halaman
                // pemantauan yang menarik dirinya sendiri selamanya adalah
                // beban yang tidak pernah dimatikan siapa pun.
                setTimeout(berhentiMenarik, 180000);
            },
        },
    );
}

const kolom: KolomDefinisi[] = [
    { kunci: 'dimulai_at', judul: 'Waktu', bisaDiurutkan: true, lebar: 'w-40' },
    // `w-full max-w-0` bukan salah ketik. Tabel auto-layout menghitung lebar
    // kolom dari lebar alami isinya, dan pesan error sepanjang 2.000 huruf di
    // dalam truncate tetap dihitung sebagai satu baris utuh. Akibatnya kolom
    // ini melar dan truncate-nya tidak pernah kena. max-w-0 meruntuhkan lebar
    // alaminya, w-full membuatnya mengambil sisa ruang setelah kolom lain.
    { kunci: 'sumber_feed', judul: 'Sumber', lebar: 'w-full max-w-0' },
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

// "6 Agu 15:02 (3 jam lalu)". Jam mutlaknya untuk mencocokkan dengan baris log,
// jaraknya supaya admin tidak perlu menghitung sendiri apakah crawl sudah macet.
const waktuJadwal = (nilai: string) =>
    `${format(new Date(nilai), 'd MMM HH:mm', { locale: id })} (${formatDistanceToNow(new Date(nilai), { locale: id, addSuffix: true })})`;

const varianStatus: Record<BarisLog['status'], string> = {
    sukses: 'outline',
    sebagian: 'secondary',
    gagal: 'destructive',
};
</script>

<template>
    <Head title="Log crawl" />

    <LayoutAdmin judul="Log crawl" :breadcrumbs="[{ title: 'Log crawl', href: '/admin/log-crawl' }]">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-2xl space-y-2">
                <p class="text-xs text-muted-foreground">
                    Crawl otomatis berjalan tiap 3 jam untuk {{ jumlahSumberAktif }} sumber aktif. Log disimpan 90 hari,
                    lalu dihapus otomatis. Kolom "sudah ada" berisi item feed yang URL-nya sudah tercatat di database,
                    ditambah item yang dibuang saringan kata kunci.
                </p>

                <p class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    <span>
                        <span class="text-muted-foreground">Crawl terakhir:</span>
                        {{ crawlTerakhir ? waktuJadwal(crawlTerakhir) : 'belum pernah' }}
                    </span>
                    <span>
                        <span class="text-muted-foreground">Crawl berikutnya:</span>
                        {{ crawlBerikutnya ? waktuJadwal(crawlBerikutnya) : 'tidak terjadwal' }}
                    </span>
                </p>
            </div>

            <Button size="sm" variant="outline" :disabled="sedangCrawl" @click="crawlSekarang">
                <RefreshCw class="size-4" :class="sedangCrawl && 'animate-spin'" />
                {{ sedangCrawl ? 'Sedang menarik' : 'Crawl sekarang' }}
            </Button>
        </div>

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
