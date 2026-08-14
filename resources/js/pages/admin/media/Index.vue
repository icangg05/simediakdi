<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import KopHalaman from '@/components/domain/KopHalaman.vue';
import PilKop from '@/components/domain/PilKop.vue';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { AksiBaris, FilterDefinisi, KolomDefinisi, OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { Head, Link, router } from '@inertiajs/vue3';
import { CircleCheck, Handshake, Loader, Newspaper, Plus, TriangleAlert } from 'lucide-vue-next';
import { computed, type Component } from 'vue';

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
    { kunci: 'sumber_feed_count', judul: 'Pengambilan', lebar: 'w-48' },
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
    nasional: 'bg-tier-nasional/10 text-tier-nasional ring-tier-nasional/25',
    regional: 'bg-tier-regional/10 text-tier-regional ring-tier-regional/25',
    lokal: 'bg-tier-lokal/10 text-tier-lokal ring-tier-lokal/25',
};

/**
 * Keadaan pengambilan satu media, dalam satu frasa.
 *
 * Sejak seluruh media ditarik secara bawaan, pertanyaan yang dibawa admin ke
 * halaman ini bukan lagi "berapa sumbernya" melainkan "mana yang tidak jalan".
 * Angka telanjang tidak menjawab itu: nol bisa berarti masih dicari, bisa
 * berarti sudah dicari dan tidak ketemu, dan keduanya menuntut tindakan
 * berbeda.
 *
 * Tiap keadaan sekarang membawa ikonnya sendiri. Empat frasa berwarna yang
 * hanya berbeda warna memaksa mata membaca kalimatnya satu per satu, dan yang
 * dicari admin saat menyisir daftar ini adalah baris yang bermasalah, bukan
 * bacaan.
 */
function pengambilan(baris: BarisMedia): { teks: string; kelas: string; ikon: Component } {
    if (!baris.aktif) {
        return { teks: 'Dihentikan', kelas: 'text-muted-foreground', ikon: TriangleAlert };
    }

    if (baris.sumber_feed_aktif_count > 0) {
        return { teks: `${baris.sumber_feed_aktif_count} sumber aktif`, kelas: 'text-sentimen-positif', ikon: CircleCheck };
    }

    if (baris.sumber_feed_count > 0) {
        return { teks: 'Semua sumber mati', kelas: 'text-sentimen-negatif', ikon: TriangleAlert };
    }

    return baris.feed_dicari_at === null
        ? { teks: 'Mencari RSS', kelas: 'text-aksen-biru', ikon: Loader }
        : { teks: 'RSS tidak ketemu', kelas: 'text-sentimen-review', ikon: TriangleAlert };
}

/**
 * Ringkasan di kop dihitung dari halaman yang sedang terbuka, kecuali totalnya.
 *
 * `media.total` datang dari paginator dan benar untuk seluruh tabel. Dua angka
 * lainnya hanya menghitung baris yang terlihat, jadi labelnya menyebut "di
 * halaman ini". Menyebutnya sebagai angka keseluruhan akan berbohong begitu
 * daftarnya lebih dari satu halaman, dan itu jenis angka yang sekali ketahuan
 * salah membuat seluruh kop berhenti dipercaya.
 */
const bermasalah = computed(() => props.media.data.filter((m) => m.aktif && m.sumber_feed_aktif_count === 0).length);

const partner = computed(() => props.media.data.filter((m) => m.partner).length);
</script>

<template>
    <Head title="Media" />

    <LayoutAdmin :breadcrumbs="[{ title: 'Media', href: '/admin/media' }]">
        <KopHalaman
            judul="Media"
            keterangan="Daftar media yang beritanya ditarik sistem, beserta sumber pengambilan masing-masing. Media nonaktif tidak ditarik sama sekali."
        >
            <template #aksi>
                <!-- Putih penuh: satu-satunya aksi utama halaman ini. -->
                <Link
                    href="/admin/media/create"
                    class="tekan inline-flex items-center gap-2 rounded-lg bg-white px-3.5 py-2 text-xs font-semibold text-brand transition-colors hover:bg-white/90 focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-brand focus-visible:outline-hidden"
                >
                    <Plus class="size-3.5" aria-hidden="true" />
                    Tambah media
                </Link>
            </template>

            <PilKop :ikon="Newspaper">
                <span class="angka">{{ media.total }}</span> media terdaftar
            </PilKop>
            <PilKop v-if="partner > 0" :ikon="Handshake">
                <span class="angka">{{ partner }}</span> media bekerja sama di halaman ini
            </PilKop>
            <PilKop v-if="bermasalah > 0" nada="tunggu" :ikon="TriangleAlert">
                <span class="angka">{{ bermasalah }}</span> tanpa sumber aktif di halaman ini
            </PilKop>
        </KopHalaman>

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
            keterangan-kosong="Tambahkan media yang bekerja sama lebih dulu, lalu daftarkan sumber feed-nya."
        >
            <template #sel-nama="{ baris }">
                <div class="flex items-center gap-2">
                    <Link
                        :href="`/admin/media/${baris.id}`"
                        class="rounded font-medium decoration-brand/40 underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
                    >
                        {{ baris.nama }}
                    </Link>
                    <!-- Toska berarti terhitung dan masuk lingkup, sama dengan
                         label Relevan di halaman Berita. Media yang bekerja
                         sama adalah media yang pemuatannya dihitung terhadap
                         kontrak. -->
                    <span
                        v-if="baris.partner"
                        class="inline-flex items-center gap-1 rounded-md bg-aksen-toska/10 px-1.5 py-0.5 text-[11px] font-medium whitespace-nowrap text-aksen-toska"
                    >
                        <Handshake class="size-3 shrink-0" aria-hidden="true" />
                        Bekerja sama
                    </span>
                </div>
            </template>

            <template #sel-sumber_feed_count="{ baris }">
                <span class="inline-flex items-center gap-1.5 text-sm" :class="pengambilan(baris).kelas">
                    <component :is="pengambilan(baris).ikon" class="size-3.5 shrink-0" aria-hidden="true" />
                    {{ pengambilan(baris).teks }}
                </span>
            </template>

            <template #sel-domain="{ baris }">
                <span class="text-muted-foreground">{{ baris.domain ?? '-' }}</span>
            </template>

            <template #sel-tier="{ baris }">
                <span class="rounded-md px-1.5 py-0.5 text-xs font-medium capitalize ring-1 ring-inset" :class="warnaTier[baris.tier]">
                    {{ baris.tier }}
                </span>
            </template>

            <template #sel-jenis="{ baris }">
                <span class="capitalize">{{ baris.jenis }}</span>
            </template>

            <!-- Titik berisian, bukan lencana bergaris. Kolom ini dibaca sambil
                 menyisir ke bawah, dan bentuk kecil yang sama di tiap baris
                 lebih cepat dibandingkan daripada kotak berteks. -->
            <template #sel-aktif="{ baris }">
                <span class="inline-flex items-center gap-1.5 text-xs" :class="baris.aktif ? '' : 'text-muted-foreground'">
                    <span class="size-1.5 shrink-0 rounded-full" :class="baris.aktif ? 'bg-sentimen-positif' : 'bg-muted-foreground/50'" />
                    {{ baris.aktif ? 'Aktif' : 'Nonaktif' }}
                </span>
            </template>
        </DataTable>
    </LayoutAdmin>
</template>
