<script setup lang="ts">
import DialogSumberFeed from '@/components/domain/DialogSumberFeed.vue';
import KopHalaman from '@/components/domain/KopHalaman.vue';
import PilKop from '@/components/domain/PilKop.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { SumberFeedBaris } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { formatDistanceToNow } from 'date-fns';
import { id } from 'date-fns/locale';
import {
    CircleAlert,
    CircleCheck,
    CircleX,
    Globe,
    Handshake,
    History,
    Layers,
    Loader,
    Newspaper,
    Pencil,
    Play,
    Plus,
    Power,
    Rss,
    Trash2,
    TriangleAlert,
    UserRound,
} from 'lucide-vue-next';
import { computed, ref, type Component } from 'vue';

interface Media {
    id: number;
    nama: string;
    slug: string;
    domain: string | null;
    url_website: string | null;
    tier: 'nasional' | 'regional' | 'lokal';
    jenis: string;
    kota: string | null;
    partner: boolean;
    nama_pic: string | null;
    kontak_pic: string | null;
    aktif: boolean;
    feed_dicari_at: string | null;
    artikel_count: number;
}

interface BarisRiwayat {
    id: number;
    sumber: string | null;
    status: 'sukses' | 'sebagian' | 'gagal';
    jumlah_baru: number;
    jumlah_ditemukan: number;
    pesan: string | null;
    dimulai_at: string | null;
}

const props = defineProps<{
    media: Media;
    sumberFeed: SumberFeedBaris[];
    riwayat: BarisRiwayat[];
    /** Ambang gagal berturut sebelum sistem mengarantina sumber sendiri. */
    maksGagal: number;
}>();

const dialogTerbuka = ref(false);
const sumberDisunting = ref<SumberFeedBaris | null>(null);

function tambah() {
    sumberDisunting.value = null;
    dialogTerbuka.value = true;
}

function ubah(sumber: SumberFeedBaris) {
    sumberDisunting.value = sumber;
    dialogTerbuka.value = true;
}

function hapus(sumber: SumberFeedBaris) {
    if (confirm(`Hapus sumber ${sumber.nama}? Artikel yang sudah terkumpul tetap tersimpan.`)) {
        router.delete(`/admin/media/${props.media.id}/sumber-feed/${sumber.id}`, { preserveScroll: true });
    }
}

/**
 * Tiga keadaan yang menjelaskan kenapa sebuah media belum menarik berita.
 *
 * Dibedakan karena tindakan yang dituntut ketiganya berbeda. Media nonaktif
 * menunggu keputusan admin, media yang feed-nya masih dicari tidak menunggu
 * apa pun, dan media yang pencariannya sudah selesai tanpa hasil menunggu
 * alamat diisi tangan. Menyatukan ketiganya jadi satu pesan "belum ada feed"
 * membuat admin mengisi alamat untuk media yang sebenarnya cuma perlu ditunggu.
 */
const keadaan = computed(() => {
    if (!props.media.aktif) {
        return {
            nada: 'mati' as const,
            ikon: CircleAlert,
            judul: 'Media ini nonaktif',
            pesan: 'Tidak ada berita yang ditarik selama saklarnya mati, termasuk lewat tombol tarik sekarang.',
        };
    }

    if (props.sumberFeed.length > 0) {
        return null;
    }

    if (props.media.feed_dicari_at === null) {
        return {
            nada: 'tunggu' as const,
            ikon: Loader,
            judul: 'Alamat RSS sedang dicari',
            pesan: 'Sistem sedang membuka situs media ini untuk menemukan alamat feed-nya. Muat ulang halaman sebentar lagi.',
        };
    }

    return {
        nada: 'kosong' as const,
        ikon: TriangleAlert,
        judul: 'Alamat RSS tidak ditemukan',
        pesan: 'Pencarian otomatis sudah dijalankan dan situs ini tidak menyediakan RSS di alamat yang lazim. Tambahkan sumbernya sendiri di bawah.',
    };
});

const RUPA_KEADAAN = {
    mati: 'bg-muted text-muted-foreground ring-border',
    tunggu: 'bg-aksen-biru/10 text-aksen-biru ring-aksen-biru/25',
    kosong: 'bg-sentimen-review-lembut text-sentimen-review ring-sentimen-review/25',
} as const;

const RUPA_STATUS: Record<BarisRiwayat['status'], { kelas: string; titik: string; ikon: Component }> = {
    sukses: { kelas: 'bg-sentimen-positif-lembut text-sentimen-positif', titik: 'bg-sentimen-positif', ikon: CircleCheck },
    sebagian: { kelas: 'bg-sentimen-review-lembut text-sentimen-review', titik: 'bg-sentimen-review', ikon: TriangleAlert },
    gagal: { kelas: 'bg-sentimen-negatif-lembut text-sentimen-negatif', titik: 'bg-sentimen-negatif', ikon: CircleX },
};

const warnaTier: Record<Media['tier'], string> = {
    nasional: 'bg-tier-nasional/10 text-tier-nasional ring-tier-nasional/25',
    regional: 'bg-tier-regional/10 text-tier-regional ring-tier-regional/25',
    lokal: 'bg-tier-lokal/10 text-tier-lokal ring-tier-lokal/25',
};

function sejak(iso: string | null): string {
    return iso ? formatDistanceToNow(new Date(iso), { addSuffix: true, locale: id }) : 'belum pernah';
}

/**
 * Sumber yang gagal berulang kali dan hampir pasti butuh diperiksa.
 *
 * Dulu penanda ini berarti "dimatikan sistem". Penonaktifan otomatis sudah
 * dicabut, jadi sumber seperti ini tetap mencoba di setiap jadwal, dan justru
 * karena itu ia perlu terlihat: tidak ada lagi saklar mati yang memaksa admin
 * menyadarinya.
 */
function bermasalah(sumber: SumberFeedBaris): boolean {
    return sumber.gagal_berturut >= props.maksGagal;
}

/** Titik pada rel sumber: hijau sehat, merah gagal berulang, abu dimatikan. */
function titikSumber(sumber: SumberFeedBaris): string {
    if (!sumber.aktif) return 'bg-muted-foreground/40';
    if (bermasalah(sumber)) return 'bg-sentimen-negatif';

    return 'bg-sentimen-positif';
}

const sumberAktif = computed(() => props.sumberFeed.filter((s) => s.aktif).length);

const bisaTarik = computed(() => props.media.aktif && sumberAktif.value > 0);

/** Keterangan identitas, dipakai sebagai bidang bergaris di kartu pertama. */
const identitas = computed(() => [
    { ikon: Globe, label: 'Domain', nilai: props.media.domain ?? '-' },
    { ikon: Newspaper, label: 'Jenis', nilai: props.media.jenis, kapital: true },
    {
        ikon: UserRound,
        label: 'Kontak',
        nilai: `${props.media.nama_pic ?? '-'}${props.media.kontak_pic ? `, ${props.media.kontak_pic}` : ''}`,
    },
    { ikon: History, label: 'Artikel terkumpul', nilai: String(props.media.artikel_count), angka: true },
]);
</script>

<template>
    <Head :title="media.nama" />

    <LayoutAdmin
        :breadcrumbs="[
            { title: 'Media', href: '/admin/media' },
            { title: media.nama, href: `/admin/media/${media.id}` },
        ]"
    >
        <KopHalaman :judul="media.nama" :keterangan="media.url_website ?? undefined">
            <template #aksi>
                <!--
                    Tiga tombol, tiga bobot yang berbeda.

                    Menarik sekarang adalah yang paling sering ditekan di layar
                    ini, jadi ia yang mendapat bidang putih penuh. Ubah identitas
                    hanya membuka form. Saklar induk diberi warna keadaan
                    tujuannya: merah kalau ia akan mematikan pengambilan, hijau
                    kalau ia akan menghidupkannya. Sebelumnya saklar ini memakai
                    `variant="destructive"` yang berarti merah pekat, dan
                    mematikan satu media bukan tindakan yang tidak bisa
                    dibatalkan.

                    Rona saklar itu diambil dari palet Tailwind, bukan token
                    sentimen, dengan alasan dan nilai yang sama seperti di
                    `PilKop`: token sentimen terlalu gelap untuk latar navy.
                -->
                <button
                    type="button"
                    class="tekan inline-flex items-center gap-2 rounded-lg bg-white px-3.5 py-2 text-xs font-semibold text-brand transition-colors hover:bg-white/90 focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-brand focus-visible:outline-hidden disabled:opacity-50"
                    :disabled="!bisaTarik"
                    :title="bisaTarik ? undefined : 'Butuh media aktif dengan sekurangnya satu sumber aktif'"
                    @click="router.post(`/admin/media/${media.id}/crawl`, {}, { preserveScroll: true })"
                >
                    <Play class="size-3.5" aria-hidden="true" />
                    Tarik sekarang
                </button>

                <Link
                    :href="`/admin/media/${media.id}/edit`"
                    class="tekan inline-flex items-center gap-2 rounded-lg bg-white/10 px-3.5 py-2 text-xs font-medium text-white ring-1 ring-white/25 transition-colors ring-inset hover:bg-white/20 focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-brand focus-visible:outline-hidden"
                >
                    <Pencil class="size-3.5" aria-hidden="true" />
                    Ubah identitas
                </Link>

                <button
                    type="button"
                    class="tekan inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-xs font-medium ring-1 transition-colors ring-inset focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-brand focus-visible:outline-hidden"
                    :class="
                        media.aktif
                            ? 'bg-red-300/15 text-red-200 ring-red-300/40 hover:bg-red-300/25'
                            : 'bg-emerald-300/15 text-emerald-200 ring-emerald-300/40 hover:bg-emerald-300/25'
                    "
                    @click="router.post(`/admin/media/${media.id}/aktif`, {}, { preserveScroll: true })"
                >
                    <Power class="size-3.5" aria-hidden="true" />
                    {{ media.aktif ? 'Nonaktifkan' : 'Aktifkan' }}
                </button>
            </template>

            <PilKop :nada="media.aktif ? 'baik' : 'buruk'" :ikon="media.aktif ? CircleCheck : CircleX">
                {{ media.aktif ? 'Aktif' : 'Nonaktif' }}
            </PilKop>
            <PilKop v-if="media.partner" :ikon="Handshake">Partner Pemkot</PilKop>
            <PilKop :nada="sumberAktif > 0 ? 'netral' : 'tunggu'" :ikon="sumberAktif > 0 ? Rss : TriangleAlert">
                <span class="angka">{{ sumberAktif }}</span> dari <span class="angka">{{ sumberFeed.length }}</span> sumber aktif
            </PilKop>
            <PilKop :ikon="Newspaper">
                <span class="angka">{{ media.artikel_count }}</span> artikel terkumpul
            </PilKop>
        </KopHalaman>

        <div class="space-y-4">
            <!-- Identitas sebagai bidang bergaris, bukan daftar definisi polos.
                 Empat keterangan yang berbagi satu bidang terbaca sebagai satu
                 kelompok, dan ikon di tiap sel membuat mata bisa melompat ke
                 yang dicarinya tanpa membaca labelnya. -->
            <Card class="muncul overflow-hidden" style="animation-delay: 80ms">
                <CardContent class="p-0">
                    <dl class="grid grid-cols-1 gap-px bg-border sm:grid-cols-2 lg:grid-cols-5">
                        <!-- Tier punya selnya sendiri karena ia satu-satunya
                             keterangan di sini yang mengubah perhitungan: ia
                             menentukan pembobotan media di peringkat. Warnanya
                             memakai token tier yang sama dengan daftar media. -->
                        <div class="min-w-0 space-y-1 bg-card p-4">
                            <dt class="flex items-center gap-1.5 text-xs text-muted-foreground">
                                <Layers class="size-3.5 shrink-0" aria-hidden="true" />
                                Tier
                            </dt>
                            <dd>
                                <span
                                    class="inline-flex rounded-md px-1.5 py-0.5 text-xs font-medium capitalize ring-1 ring-inset"
                                    :class="warnaTier[media.tier]"
                                >
                                    {{ media.tier }}
                                </span>
                            </dd>
                        </div>

                        <div v-for="i in identitas" :key="i.label" class="min-w-0 space-y-0.5 bg-card p-4">
                            <dt class="flex items-center gap-1.5 text-xs text-muted-foreground">
                                <component :is="i.ikon" class="size-3.5 shrink-0" aria-hidden="true" />
                                {{ i.label }}
                            </dt>
                            <dd
                                class="truncate text-sm font-medium"
                                :class="[i.kapital ? 'capitalize' : '', i.angka ? 'angka' : '']"
                                :title="i.nilai"
                            >
                                {{ i.nilai }}
                            </dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <div
                v-if="keadaan"
                class="muncul flex items-start gap-3 rounded-xl px-4 py-3.5 ring-1 ring-inset"
                :class="RUPA_KEADAAN[keadaan.nada]"
                style="animation-delay: 120ms"
            >
                <component
                    :is="keadaan.ikon"
                    class="mt-0.5 size-5 shrink-0"
                    :class="keadaan.nada === 'tunggu' ? 'animate-spin motion-reduce:animate-none' : ''"
                    aria-hidden="true"
                />
                <div class="min-w-0">
                    <p class="text-sm font-semibold">{{ keadaan.judul }}</p>
                    <p class="text-sm opacity-80">{{ keadaan.pesan }}</p>
                </div>
            </div>

            <!--
                Pengambilan. Inilah yang dulu jadi halaman Sumber Feed sendiri.
            -->
            <Card class="muncul overflow-hidden" style="animation-delay: 160ms">
                <CardHeader class="flex-row items-center justify-between gap-3 space-y-0 border-b py-3">
                    <CardTitle class="flex items-center gap-2 text-sm font-semibold">
                        <span class="grid size-7 place-items-center rounded-md bg-aksen-biru/10 text-aksen-biru">
                            <Rss class="size-4" aria-hidden="true" />
                        </span>
                        Sumber pengambilan
                    </CardTitle>
                    <Button size="sm" class="tekan h-8" @click="tambah">
                        <Plus class="size-4" aria-hidden="true" />
                        Tambah sumber
                    </Button>
                </CardHeader>

                <CardContent class="p-0">
                    <!-- Rel bertitik, bukan daftar rata. Titiknya berwarna
                         menurut keadaan sumbernya, jadi sumber bermasalah
                         terlihat sebelum lencananya dibaca. -->
                    <ul v-if="sumberFeed.length" class="divide-y">
                        <li v-for="sumber in sumberFeed" :key="sumber.id" class="flex gap-3 px-4 py-3 transition-colors hover:bg-muted/40">
                            <span
                                class="mt-1.5 size-2 shrink-0 rounded-full ring-4 ring-transparent"
                                :class="titikSumber(sumber)"
                                aria-hidden="true"
                            />

                            <div class="min-w-0 flex-1 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium">{{ sumber.nama }}</span>
                                    <span class="rounded-md bg-muted px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground uppercase">
                                        {{ sumber.tipe }}
                                    </span>
                                    <span
                                        v-if="bermasalah(sumber)"
                                        class="inline-flex items-center gap-1 rounded-md bg-sentimen-negatif-lembut px-1.5 py-0.5 text-[11px] font-medium text-sentimen-negatif"
                                    >
                                        <TriangleAlert class="size-3 shrink-0" aria-hidden="true" />
                                        Gagal <span class="angka">{{ sumber.gagal_berturut }}&times;</span> berturut-turut
                                    </span>
                                    <span
                                        v-if="!sumber.aktif"
                                        class="rounded-md bg-muted px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground"
                                    >
                                        Nonaktif
                                    </span>
                                </div>

                                <p class="truncate text-xs text-muted-foreground">{{ sumber.url }}</p>

                                <p class="angka text-xs text-muted-foreground">
                                    Tiap {{ sumber.interval_menit }} menit. Berhasil terakhir {{ sejak(sumber.berhasil_terakhir_at) }}.
                                    <template v-if="sumber.kata_kunci">Disaring kata "{{ sumber.kata_kunci }}".</template>
                                </p>

                                <p v-if="sumber.pesan_error_terakhir" class="text-xs text-sentimen-negatif">
                                    {{ sumber.pesan_error_terakhir }}
                                </p>
                            </div>

                            <div class="flex shrink-0 gap-1">
                                <Button variant="ghost" size="icon" class="size-8" aria-label="Ubah sumber" @click="ubah(sumber)">
                                    <Pencil class="size-4" aria-hidden="true" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-8 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                    aria-label="Hapus sumber"
                                    @click="hapus(sumber)"
                                >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                </Button>
                            </div>
                        </li>
                    </ul>

                    <div v-else class="flex flex-col items-center gap-2 px-6 py-10 text-center">
                        <div class="grid size-10 place-items-center rounded-full bg-muted text-muted-foreground">
                            <Rss class="size-5" aria-hidden="true" />
                        </div>
                        <p class="text-sm font-medium">Belum ada sumber pengambilan</p>
                        <p class="max-w-sm text-xs text-muted-foreground">
                            Selama tidak ada satu pun sumber, media ini tidak akan pernah menarik berita.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <!--
                Sepuluh pengambilan terakhir. Penelusuran menyeluruh tetap di
                halaman Log crawl, yang di sini hanya menjawab satu pertanyaan:
                pengambilan terakhirnya berhasil atau tidak.
            -->
            <Card v-if="riwayat.length" class="muncul overflow-hidden" style="animation-delay: 220ms">
                <CardHeader class="flex-row items-center gap-2 space-y-0 border-b py-3">
                    <span class="grid size-7 place-items-center rounded-md bg-muted text-muted-foreground">
                        <History class="size-4" aria-hidden="true" />
                    </span>
                    <CardTitle class="text-sm font-semibold">Riwayat pengambilan</CardTitle>
                </CardHeader>

                <CardContent class="p-0">
                    <ul class="divide-y">
                        <li
                            v-for="baris in riwayat"
                            :key="baris.id"
                            class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 text-sm transition-colors hover:bg-muted/40"
                        >
                            <span
                                class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium capitalize"
                                :class="RUPA_STATUS[baris.status].kelas"
                            >
                                <component :is="RUPA_STATUS[baris.status].ikon" class="size-3 shrink-0" aria-hidden="true" />
                                {{ baris.status }}
                            </span>
                            <span class="min-w-0 flex-1 truncate text-muted-foreground">{{ baris.sumber ?? '-' }}</span>
                            <span class="angka text-xs text-muted-foreground">
                                <span :class="baris.jumlah_baru > 0 ? 'font-semibold text-foreground' : ''">{{ baris.jumlah_baru }}</span>
                                baru dari {{ baris.jumlah_ditemukan }} ditemukan
                            </span>
                            <span class="angka shrink-0 text-xs text-muted-foreground">{{ sejak(baris.dimulai_at) }}</span>
                            <p v-if="baris.pesan" class="w-full text-xs text-sentimen-negatif">{{ baris.pesan }}</p>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <DialogSumberFeed v-model:terbuka="dialogTerbuka" :sumber="sumberDisunting" :media-id="media.id" />
    </LayoutAdmin>
</template>
