<script setup lang="ts">
import DialogSumberFeed from '@/components/domain/DialogSumberFeed.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { SumberFeedBaris } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { formatDistanceToNow } from 'date-fns';
import { id } from 'date-fns/locale';
import { CircleAlert, Loader, Pencil, Play, Plus, Rss, Trash2, TriangleAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';

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
    mati: 'border-sentimen-netral/30 bg-sentimen-netral-lembut text-sentimen-netral',
    tunggu: 'border-aksen-biru/30 bg-aksen-biru/10 text-aksen-biru',
    kosong: 'border-sentimen-review/30 bg-sentimen-review-lembut text-sentimen-review',
} as const;

const RUPA_STATUS = {
    sukses: 'bg-sentimen-positif-lembut text-sentimen-positif',
    sebagian: 'bg-sentimen-review-lembut text-sentimen-review',
    gagal: 'bg-sentimen-negatif-lembut text-sentimen-negatif',
} as const;

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
</script>

<template>
    <Head :title="media.nama" />

    <LayoutAdmin
        :judul="media.nama"
        :breadcrumbs="[
            { title: 'Media', href: '/admin/media' },
            { title: media.nama, href: `/admin/media/${media.id}` },
        ]"
    >
        <div class="space-y-4">
            <!-- Identitas dan saklar induk, satu kartu di paling atas. -->
            <Card>
                <CardContent class="flex flex-wrap items-start justify-between gap-4 pt-6">
                    <div class="min-w-0 space-y-2">
                        <!--
                            Nama medianya tidak dicetak lagi di sini. LayoutAdmin
                            sudah menaruhnya sebagai judul halaman, dan
                            mengulangnya satu tingkat lebih kecil menghasilkan
                            dua tulisan sama persis berjarak text-xl ke text-lg,
                            beda 1,11 kali, terlalu dekat untuk terbaca sebagai
                            tingkatan dan terlalu mirip untuk terbaca sebagai
                            dua hal berbeda.
                        -->
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge :variant="media.aktif ? 'outline' : 'secondary'">
                                {{ media.aktif ? 'Aktif' : 'Nonaktif' }}
                            </Badge>
                            <Badge v-if="media.partner" variant="secondary">Partner</Badge>
                        </div>

                        <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                            <div class="flex gap-2">
                                <dt class="text-muted-foreground">Domain</dt>
                                <dd class="min-w-0 truncate">{{ media.domain ?? '-' }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-muted-foreground">Tier</dt>
                                <dd class="capitalize">{{ media.tier }}, {{ media.jenis }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-muted-foreground">Kontak</dt>
                                <dd class="min-w-0 truncate">{{ media.nama_pic ?? '-' }}{{ media.kontak_pic ? `, ${media.kontak_pic}` : '' }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-muted-foreground">Artikel terkumpul</dt>
                                <dd class="angka">{{ media.artikel_count }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="`/admin/media/${media.id}/edit`">
                                <Pencil class="mr-1.5 h-4 w-4" />
                                Ubah identitas
                            </Link>
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="!media.aktif || !sumberFeed.some((s) => s.aktif)"
                            @click="router.post(`/admin/media/${media.id}/crawl`, {}, { preserveScroll: true })"
                        >
                            <Play class="mr-1.5 h-4 w-4" />
                            Tarik sekarang
                        </Button>
                        <Button
                            :variant="media.aktif ? 'destructive' : 'default'"
                            size="sm"
                            @click="router.post(`/admin/media/${media.id}/aktif`, {}, { preserveScroll: true })"
                        >
                            {{ media.aktif ? 'Nonaktifkan' : 'Aktifkan' }}
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <div v-if="keadaan" class="flex items-start gap-3 rounded-xl border px-4 py-3.5" :class="RUPA_KEADAAN[keadaan.nada]">
                <component :is="keadaan.ikon" class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
                <div class="min-w-0">
                    <p class="text-sm font-semibold">{{ keadaan.judul }}</p>
                    <p class="text-sm text-foreground/75">{{ keadaan.pesan }}</p>
                </div>
            </div>

            <!--
                Pengambilan. Inilah yang dulu jadi halaman Sumber Feed sendiri.
            -->
            <Card>
                <CardHeader class="flex-row items-center justify-between gap-3 py-4">
                    <CardTitle class="flex items-center gap-2.5 text-base">
                        <span class="grid h-8 w-8 place-items-center rounded-xl bg-aksen-biru text-white shadow-sm dark:text-background">
                            <Rss class="h-[18px] w-[18px]" aria-hidden="true" />
                        </span>
                        Sumber pengambilan
                    </CardTitle>
                    <Button size="sm" @click="tambah">
                        <Plus class="mr-1.5 h-4 w-4" />
                        Tambah sumber
                    </Button>
                </CardHeader>

                <CardContent>
                    <ul v-if="sumberFeed.length" class="divide-y">
                        <li v-for="sumber in sumberFeed" :key="sumber.id" class="flex flex-wrap items-start justify-between gap-3 py-3">
                            <div class="min-w-0 flex-1 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium">{{ sumber.nama }}</span>
                                    <Badge variant="secondary" class="uppercase">{{ sumber.tipe }}</Badge>
                                    <Badge v-if="bermasalah(sumber)" class="bg-sentimen-negatif-lembut text-sentimen-negatif">
                                        Gagal {{ sumber.gagal_berturut }}× berturut-turut
                                    </Badge>
                                    <Badge v-if="!sumber.aktif" variant="secondary">Nonaktif</Badge>
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
                                <Button variant="ghost" size="icon" class="h-8 w-8" aria-label="Ubah sumber" @click="ubah(sumber)">
                                    <Pencil class="h-4 w-4" />
                                </Button>
                                <Button variant="ghost" size="icon" class="h-8 w-8 text-destructive" aria-label="Hapus sumber" @click="hapus(sumber)">
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                            </div>
                        </li>
                    </ul>

                    <p v-else class="py-4 text-sm text-muted-foreground">Belum ada sumber pengambilan untuk media ini.</p>
                </CardContent>
            </Card>

            <!--
                Sepuluh pengambilan terakhir. Penelusuran menyeluruh tetap di
                halaman Log crawl, yang di sini hanya menjawab satu pertanyaan:
                pengambilan terakhirnya berhasil atau tidak.
            -->
            <Card v-if="riwayat.length">
                <CardHeader class="py-4">
                    <CardTitle class="text-base">Riwayat pengambilan</CardTitle>
                </CardHeader>
                <CardContent>
                    <ul class="divide-y">
                        <li v-for="baris in riwayat" :key="baris.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5 text-sm">
                            <span class="rounded px-1.5 py-0.5 text-xs font-medium capitalize" :class="RUPA_STATUS[baris.status]">
                                {{ baris.status }}
                            </span>
                            <span class="min-w-0 flex-1 truncate text-muted-foreground">{{ baris.sumber ?? '-' }}</span>
                            <span class="angka text-xs text-muted-foreground">
                                {{ baris.jumlah_baru }} baru dari {{ baris.jumlah_ditemukan }} ditemukan
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
