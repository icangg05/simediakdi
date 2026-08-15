<script setup lang="ts">
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import BadgeTahapPortal from '@/components/domain/BadgeTahapPortal.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { formatDistanceToNow } from 'date-fns';
import { id } from 'date-fns/locale';
import { ChevronRight, ExternalLink, Handshake, Newspaper, PlusCircle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    judul: string;
    url: string | null;
    /** Halaman detail internal. Kalau ada, seluruh badan baris menuju ke sini. */
    detailUrl?: string;
    media: string | null;
    /** Media memiliki kerja sama publikasi dengan Pemerintah Kota. */
    mediaPartner?: boolean;
    diambilAt: string;
    label?: 'negatif' | 'netral' | 'positif' | null;
    perluReview?: boolean;
    /** Panel eksekutif menampilkan sentimen; portal media tidak boleh. */
    tampilkanSentimen?: boolean;
    /**
     * Berita ini ditambahkan sendiri oleh media, bukan ditemukan crawler.
     *
     * Penanda asal baris, bukan penilaian apa pun terhadap isinya, jadi ia
     * aman ditampilkan di portal media. Berguna karena media perlu tahu berita
     * mana yang tertangkap sistem tanpa diminta dan mana yang hanya masuk
     * karena mereka mengirimnya, dan itu yang menentukan apakah sumber feed
     * mereka perlu diperiksa.
     */
    ditambahkanSendiri?: boolean;
    /**
     * Tahap perjalanan artikel seperti yang dibaca peran media.
     *
     * Menyebut relevansi, bukan sentimen, jadi ia tidak melanggar aturan bahwa
     * portal media tidak menampilkan nada berita. Hanya dikirim oleh beranda
     * portal, satu-satunya daftar yang memuat artikel yang belum selesai
     * dinilai. Daftar lain isinya sudah pasti berada di tahap tampil, dan
     * lencana yang selalu berbunyi sama di setiap baris hanya menambah bising.
     */
    tahap?: 'tampil' | 'diproses' | 'di_luar_pantauan' | 'gagal' | null;
    /**
     * Alasan model memberi label itu, satu atau dua kalimat.
     *
     * Opsional, karena tidak setiap pemanggil mengirimnya dan baris analisis
     * lama bisa saja kosong. Judul berita sering tidak cukup untuk menilai
     * apakah labelnya masuk akal, dan kalimat ini yang membuat lompatan dari
     * judul ke label bisa diperiksa tanpa membuka artikelnya.
     */
    ringkasanAi?: string | null;
}>();

const page = usePage();

/**
 * Halaman asal dibawa ke detail supaya tombol kembali mempertahankan periode,
 * penyaring, pencarian, dan nomor halaman yang sedang dibaca.
 */
const tujuanDetail = computed(() => (props.detailUrl ? `${props.detailUrl}?kembali=${encodeURIComponent(page.url)}` : null));
</script>

<template>
    <article class="group relative grid grid-cols-1 items-start gap-x-3 gap-y-2 py-3 sm:grid-cols-[minmax(0,1fr)_auto]">
        <div class="min-w-0 space-y-1">
            <Link
                v-if="tujuanDetail"
                :href="tujuanDetail"
                class="inline-flex items-start gap-1 text-sm leading-snug font-semibold text-foreground transition-colors after:absolute after:inset-0 after:rounded-lg hover:text-brand focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
            >
                <span class="line-clamp-2">{{ judul }}</span>
                <ChevronRight
                    class="mt-0.5 size-3.5 shrink-0 text-muted-foreground transition-transform duration-200 group-hover:translate-x-0.5 motion-reduce:transition-none"
                    aria-hidden="true"
                />
            </Link>
            <a
                v-else-if="url"
                :href="url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-start gap-1 text-sm leading-snug font-medium hover:underline"
            >
                <span class="line-clamp-2">{{ judul }}</span>
                <ExternalLink class="mt-0.5 h-3 w-3 shrink-0 text-muted-foreground" aria-hidden="true" />
            </a>
            <p v-else class="inline-flex items-start text-sm leading-snug font-medium text-foreground">
                <span class="line-clamp-2">{{ judul }}</span>
            </p>
            <p v-if="ringkasanAi" class="line-clamp-2 text-xs leading-relaxed text-foreground/70">{{ ringkasanAi }}</p>

            <!--
                Nama media jadi lencana, bukan teks abu-abu yang menyatu dengan
                keterangan waktu di sebelahnya. Nama media adalah hal yang
                paling sering dicari mata setelah judul, dan sebagai teks polos
                ia justru bagian paling tidak terlihat di baris ini.

                Warnanya netral, bukan warna nada. Di halaman ini warna berarti
                nada pemberitaan, dan lencana media berwarna akan terbaca
                seolah medianya sendiri yang bernada.
            -->
            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                <span class="inline-flex max-w-full items-center gap-1.5 rounded-md bg-muted px-2 py-0.5 font-medium text-foreground/75">
                    <Newspaper class="h-3 w-3 shrink-0" aria-hidden="true" />
                    <span class="truncate">{{ media ?? 'Media belum ditautkan' }}</span>
                </span>
                <span
                    v-if="mediaPartner"
                    class="inline-flex shrink-0 items-center gap-1 rounded-md bg-aksen-toska/10 px-2 py-0.5 font-medium text-aksen-toska"
                >
                    <Handshake class="h-3 w-3 shrink-0" aria-hidden="true" />
                    Bekerja sama
                </span>
                <span
                    v-if="ditambahkanSendiri"
                    class="inline-flex shrink-0 items-center gap-1 rounded-md border border-dashed px-2 py-0.5 font-medium text-foreground/75"
                >
                    <PlusCircle class="h-3 w-3 shrink-0" aria-hidden="true" />
                    Anda tambahkan
                </span>
                {{ formatDistanceToNow(new Date(diambilAt), { addSuffix: true, locale: id }) }}
            </p>
        </div>

        <div
            v-if="detailUrl || tampilkanSentimen || tahap"
            class="relative z-10 flex w-full shrink-0 flex-row items-center justify-between gap-2 sm:w-auto sm:flex-col sm:items-end"
        >
            <BadgeSentimen v-if="tampilkanSentimen" :label="label ?? null" :perlu-review="perluReview" class="mt-0.5 shrink-0" />
            <BadgeTahapPortal v-else-if="tahap" :tahap="tahap" class="mt-0.5 shrink-0" />

            <a
                v-if="detailUrl && url"
                :href="url"
                target="_blank"
                rel="noopener noreferrer"
                class="tekan ml-auto inline-flex min-h-10 items-center gap-1.5 rounded-md bg-background px-2.5 py-1.5 text-[11px] font-semibold whitespace-nowrap text-foreground shadow-xs ring-1 ring-border transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden sm:min-h-8"
            >
                <ExternalLink class="size-3.5" aria-hidden="true" />
                Lihat artikel asli
                <span class="sr-only">(dibuka di tab baru)</span>
            </a>
        </div>
    </article>
</template>
