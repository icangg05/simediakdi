<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import KopHalaman from '@/components/domain/KopHalaman.vue';
import PilKop from '@/components/domain/PilKop.vue';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { FilterDefinisi, KolomDefinisi, OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { Head, router } from '@inertiajs/vue3';
import { format, formatDistanceToNow } from 'date-fns';
import { id } from 'date-fns/locale';
import { CircleCheck, CircleX, Clock, Loader2, RefreshCw, Rss, TriangleAlert } from 'lucide-vue-next';
import { onUnmounted, ref, type Component } from 'vue';

interface BarisLog {
    id: number;
    dimulai_at: string;
    selesai_at: string | null;
    jumlah_ditemukan: number;
    jumlah_baru: number;
    jumlah_salinan: number;
    status: 'berjalan' | 'sukses' | 'sebagian' | 'gagal';
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

                penarik = setInterval(() => router.reload({ only: ['log', 'crawlTerakhir', 'crawlBerikutnya'], showProgress: false }), 5000);

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
    { kunci: 'status', judul: 'Status', lebar: 'w-32' },
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

/**
 * Status crawl memakai palet sentimen, bukan varian lencana bawaan.
 *
 * Sebelumnya ketiganya dirender `variant` shadcn: outline untuk sukses,
 * secondary untuk sebagian, destructive untuk gagal. Dua yang pertama sama
 * sekali tidak berwarna, jadi satu-satunya yang terbaca sekali lihat adalah
 * yang gagal, dan "sebagian" tenggelam persis seperti "sukses" padahal ia
 * keadaan yang menuntut perhatian.
 *
 * Ikonnya wajib. Kolom ini yang paling sering disisir cepat, dan warna saja
 * tidak cukup untuk pembaca yang kesulitan membedakan merah dan hijau.
 */
const RUPA_STATUS: Record<BarisLog['status'] | 'terhenti', { kelas: string; label: string; ikon: Component }> = {
    berjalan: { kelas: 'bg-muted text-muted-foreground', label: 'Berjalan', ikon: Loader2 },
    sukses: { kelas: 'bg-sentimen-positif-lembut text-sentimen-positif', label: 'Sukses', ikon: CircleCheck },
    sebagian: { kelas: 'bg-sentimen-review-lembut text-sentimen-review', label: 'Sebagian', ikon: TriangleAlert },
    gagal: { kelas: 'bg-sentimen-negatif-lembut text-sentimen-negatif', label: 'Gagal', ikon: CircleX },
    terhenti: { kelas: 'bg-sentimen-negatif-lembut text-sentimen-negatif', label: 'Terhenti', ikon: CircleX },
};

/*
 * Baris yang tertinggal di `berjalan` terlalu lama.
 *
 * Status itu ditulis sebelum pengambilan dimulai dan diganti begitu selesai,
 * baik berhasil maupun gagal. Yang tidak pernah berganti berarti prosesnya
 * berhenti di tengah, misalnya container di-restart saat crawl sedang jalan.
 * Lima belas menit jauh di atas sumber paling lambat, yang selesai di bawah
 * satu menit, jadi ambang ini tidak akan menyalakan merah pada pekerjaan yang
 * masih sehat.
 */
const AMBANG_TERHENTI_MENIT = 15;

function rupaStatus(baris: BarisLog) {
    if (baris.status !== 'berjalan') {
        return RUPA_STATUS[baris.status];
    }

    const menit = (Date.now() - new Date(baris.dimulai_at).getTime()) / 60000;

    return menit > AMBANG_TERHENTI_MENIT ? RUPA_STATUS.terhenti : RUPA_STATUS.berjalan;
}
</script>

<template>
    <Head title="Log crawl" />

    <LayoutAdmin :breadcrumbs="[{ title: 'Log crawl', href: '/admin/log-crawl' }]">
        <KopHalaman
            judul="Log crawl"
            :keterangan="`Crawl otomatis berjalan tiap 3 jam untuk ${jumlahSumberAktif} sumber aktif. Log disimpan 7 hari, lalu dihapus otomatis.`"
        >
            <template #aksi>
                <!-- Menarik sekarang menjalankan pekerjaan di worker dan memakai
                     kuota jaringan seluruh sumber, jadi ia tidak dapat bidang
                     putih penuh yang di halaman lain berarti aksi utama. Putih
                     transparan: aksi yang tersedia, bukan yang diharapkan. -->
                <button
                    type="button"
                    class="tekan inline-flex items-center gap-2 rounded-lg bg-white/10 px-3.5 py-2 text-xs font-medium text-white ring-1 ring-white/25 transition-colors ring-inset hover:bg-white/20 focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-brand focus-visible:outline-hidden disabled:opacity-60"
                    :disabled="sedangCrawl"
                    @click="crawlSekarang"
                >
                    <RefreshCw class="size-3.5" :class="sedangCrawl && 'animate-spin motion-reduce:animate-none'" aria-hidden="true" />
                    {{ sedangCrawl ? 'Sedang menarik' : 'Crawl sekarang' }}
                </button>
            </template>

            <PilKop :ikon="Rss">
                <span class="angka">{{ jumlahSumberAktif }}</span> sumber aktif
            </PilKop>
            <PilKop :ikon="Clock">
                Terakhir: <span class="angka">{{ crawlTerakhir ? waktuJadwal(crawlTerakhir) : 'belum pernah' }}</span>
            </PilKop>
            <!-- Jadwal yang kosong bukan galat, tapi ia keadaan yang perlu
                 diperiksa: tanpa jadwal, tidak ada berita yang akan masuk. -->
            <PilKop :nada="crawlBerikutnya ? 'netral' : 'tunggu'" :ikon="crawlBerikutnya ? Clock : TriangleAlert">
                Berikutnya: <span class="angka">{{ crawlBerikutnya ? waktuJadwal(crawlBerikutnya) : 'tidak terjadwal' }}</span>
            </PilKop>

            <template #bawah>
                <p class="max-w-[80ch] text-xs text-white/60">
                    Kolom "sudah ada" berisi item feed yang URL-nya sudah tercatat di database, ditambah item yang dibuang saringan kata kunci.
                </p>
            </template>
        </KopHalaman>

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
                <span class="angka text-muted-foreground">{{ waktu(baris.dimulai_at) }}</span>
            </template>

            <template #sel-sumber_feed="{ baris }">
                <div class="min-w-0">
                    <p class="truncate font-medium">{{ baris.sumber_feed?.nama ?? 'Sumber terhapus' }}</p>
                    <p
                        v-if="baris.pesan"
                        class="truncate text-xs"
                        :class="rupaStatus(baris).label === 'Gagal' ? 'text-sentimen-negatif' : 'text-muted-foreground'"
                    >
                        {{ baris.pesan }}
                    </p>
                </div>
            </template>

            <!-- Artikel baru diberi bobot, nol diredupkan. Inilah satu-satunya
                 kolom yang menjawab apakah crawl ini membawa hasil, dan angka
                 nol yang setebal angka lima puluh membuat baris kosong terlihat
                 sama produktifnya. -->
            <template #sel-jumlah_baru="{ baris }">
                <span :class="baris.jumlah_baru > 0 ? 'font-semibold text-foreground' : 'text-muted-foreground/60'">
                    {{ baris.jumlah_baru }}
                </span>
            </template>

            <template #sel-status="{ baris }">
                <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium" :class="rupaStatus(baris).kelas">
                    <component
                        :is="rupaStatus(baris).ikon"
                        class="size-3 shrink-0"
                        :class="
                            baris.status === 'berjalan' && rupaStatus(baris).label === 'Berjalan' ? 'animate-spin motion-reduce:animate-none' : ''
                        "
                        aria-hidden="true"
                    />
                    {{ rupaStatus(baris).label }}
                </span>
            </template>
        </DataTable>
    </LayoutAdmin>
</template>
