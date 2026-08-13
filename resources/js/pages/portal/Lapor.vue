<script setup lang="ts">
import BadgeTahapPortal from '@/components/domain/BadgeTahapPortal.vue';
import KopHalaman from '@/components/domain/KopHalaman.vue';
import PilKop from '@/components/domain/PilKop.vue';
import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutPortal from '@/layouts/LayoutPortal.vue';
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    Info,
    Link2,
    Loader2,
    MinusCircle,
    Plus,
    PlusCircle,
    Search,
    Send,
    ShieldAlert,
    Trash2,
} from 'lucide-vue-next';
import { computed, ref, watch, type Component } from 'vue';

interface HasilBaris {
    url: string;
    url_kanonik: string;
    status: 'berhasil' | 'sudah_tercatat' | 'domain_salah' | 'gagal';
    judul: string | null;
    tanggal: string | null;
    pesan: string | null;
}

interface Kiriman {
    id: number;
    url: string;
    judul: string | null;
    tanggal: string | null;
    status: 'tampil' | 'di_luar_pantauan' | 'diproses' | 'gagal';
}

const props = defineProps<{
    sudahOtomatis: { id: number; judul: string | null; url: string; tanggal: string | null; ditambahkan_sendiri: boolean }[];
    kiriman: Kiriman[];
}>();

const { formatAngka } = useFormatAngka();

const page = usePage<SharedData & { hasilPeriksa?: { baris: HasilBaris[] } }>();

const formPeriksa = useForm({ tautan: '' });

const hasil = computed<HasilBaris[]>(() => page.props.hasilPeriksa?.baris ?? []);
const bisaDikirim = computed(() => hasil.value.filter((h) => h.status === 'berhasil' || h.status === 'gagal'));

/** Isian per baris. Judul dan tanggal terisi dari ekstraksi, kecuali saat gagal. */
const isian = ref<Record<string, { judul: string; tanggal: string }>>({});

watch(
    hasil,
    (baris) => {
        isian.value = Object.fromEntries(
            baris.map((b) => [
                b.url_kanonik,
                {
                    judul: b.judul ?? '',
                    tanggal: b.tanggal ?? format(new Date(), 'yyyy-MM-dd'),
                },
            ]),
        );
    },
    { immediate: true },
);

const formKirim = useForm<Record<string, unknown>>({});

function periksa() {
    formPeriksa.post('/portal/lapor/periksa', { preserveScroll: true });
}

function kirim() {
    formKirim
        .transform(() => ({
            baris: bisaDikirim.value.map((b) => ({
                url: b.url_kanonik,
                judul: isian.value[b.url_kanonik]?.judul ?? '',
                tanggal: isian.value[b.url_kanonik]?.tanggal ?? '',
            })),
        }))
        .post('/portal/lapor');
}

/*
 * Empat hasil pemeriksaan, empat rona, dan tidak satu pun dari palet sentimen.
 *
 * Larangannya sama dengan yang berlaku di seluruh portal: hijau, kuning, dan
 * merah sentimen berarti nada pemberitaan, dan meminjamnya di sini membuat
 * media membaca hasil pemeriksaan teknis sebagai penilaian atas isi beritanya.
 *
 * Sebelumnya keempatnya dibedakan lewat `variant` badge bawaan, dan akibatnya
 * tiga dari empat berdiri dalam bidang abu yang sama: "Terbaca", "Sudah ada",
 * dan "Perlu diisi manual" hanya bisa dibedakan dengan membaca teksnya satu per
 * satu, padahal justru di layar inilah media memindai puluhan baris sekaligus.
 *
 * | Status         | Rona        | Arti                                     |
 * |----------------|-------------|------------------------------------------|
 * | berhasil       | Aksen toska | Terbaca dan siap ditambahkan             |
 * | sudah_tercatat | Abu netral  | Dilewati, sistem sudah punya             |
 * | domain_salah   | Destructive | Ditolak, bukan domain media Anda         |
 * | gagal          | Amber       | Butuh isian tangan sebelum bisa dikirim  |
 *
 * Amber diambil dari palet Tailwind mentah, bukan dari token, dan itu satu
 * perkecualian yang perlu dijelaskan. Ia tidak menyatakan keadaan berita
 * melainkan keadaan formulir, yaitu ada isian yang menunggu diisi, dan arti itu
 * tidak punya token di sistem ini. Memakai destructive akan menyamakannya dengan
 * tautan yang ditolak, padahal barisnya justru masih bisa dikirim.
 *
 * Kontras terendahnya `text-amber-700` di atas `bg-amber-500/10`, yaitu 5,9:1 di
 * mode terang, dan `text-amber-300` di mode gelap pada 9,2:1.
 */
const GAYA_HASIL: Record<HasilBaris['status'], { label: string; ikon: Component; lencana: string; titik: string; bingkai: string }> = {
    berhasil: {
        label: 'Terbaca',
        ikon: CheckCircle2,
        lencana: 'bg-aksen-toska/10 text-aksen-toska ring-aksen-toska/25',
        titik: 'bg-aksen-toska',
        bingkai: 'border-aksen-toska/25',
    },
    sudah_tercatat: {
        label: 'Sudah ada',
        ikon: MinusCircle,
        lencana: 'bg-muted text-muted-foreground ring-border',
        titik: 'bg-muted-foreground/40',
        bingkai: 'border-border',
    },
    domain_salah: {
        label: 'Ditolak',
        ikon: ShieldAlert,
        lencana: 'bg-destructive/10 text-destructive ring-destructive/25',
        titik: 'bg-destructive',
        bingkai: 'border-destructive/30',
    },
    gagal: {
        label: 'Perlu diisi manual',
        ikon: AlertTriangle,
        lencana: 'bg-amber-500/10 text-amber-700 ring-amber-500/30 dark:text-amber-300',
        titik: 'bg-amber-500',
        bingkai: 'border-amber-500/30',
    },
};

// Peta tahap kiriman tidak ada di sini. Ia tinggal di
// components/domain/BadgeTahapPortal.vue, karena beranda portal dan halaman
// Berita saya menampilkan tahap yang sama dan tiga salinan berarti tiga layar
// yang cepat atau lambat akan menyebut hal berbeda untuk berita yang sama.

const tanggal = (n: string | null) => (n ? format(new Date(n), 'd MMM yyyy', { locale: id }) : '-');

/** Kiriman yang masih digenggam mesin penilai, dicetak sebagai pil di kop. */
const kirimanDiproses = computed(() => props.kiriman.filter((k) => k.status === 'diproses').length);

/**
 * Mencabut satu kiriman, selalu lewat konfirmasi.
 *
 * Satu dialog untuk seluruh daftar, bukan satu dialog per baris. Kiriman bisa
 * mencapai seratus baris, dan seratus dialog yang menunggu di dalam DOM adalah
 * seratus simpul yang ikut dibacakan pembaca layar demi satu tombol yang
 * ditekan sekali sebulan.
 *
 * Konfirmasi tidak bisa dilewati, dan itu bukan basa-basi. Penghapusannya
 * permanen, tidak ada tempat sampah, dan tidak ada audit log yang mencatat
 * bahwa barisnya pernah ada. Satu tombol sampah yang langsung bekerja pada
 * daftar sepanjang ini terlalu dekat dengan tautan berita di sebelahnya.
 */
const sasaranHapus = ref<Kiriman | null>(null);

const formHapus = useForm({});

/**
 * Peringatan yang dicetak di dialog, berbeda untuk berita yang sudah terhitung.
 *
 * Berita berlencana "Tampil" sudah dinilai relevan dan menjadi bukti realisasi
 * kontrak kerja sama publikasi. Mencabutnya mengurangi angka realisasi media
 * ini, dan itu akibat yang tidak boleh baru diketahui sesudah tombolnya ditekan.
 */
const peringatanHapus = computed(() => {
    if (sasaranHapus.value?.status === 'tampil') {
        return {
            nada: 'text-destructive',
            teks:
                'Berita ini sudah dinilai relevan dan terhitung sebagai realisasi kontrak. ' +
                'Mencabutnya menurunkan angka realisasi media Anda, dan tidak ada cara membatalkannya.',
        };
    }

    return {
        nada: 'text-muted-foreground',
        teks: 'Berita ini belum terhitung di mana pun, jadi mencabutnya tidak mengubah angka realisasi media Anda.',
    };
});

function hapus() {
    const sasaran = sasaranHapus.value;

    if (sasaran === null) return;

    formHapus.delete(`/portal/lapor/${sasaran.id}`, {
        preserveScroll: true,
        onFinish: () => {
            sasaranHapus.value = null;
        },
    });
}

/*
 * Tombol diwarnai menurut tujuannya.
 *
 * Kedua tombol di halaman ini navy, dan itu disengaja: keduanya adalah langkah
 * maju dalam satu alur yang sama, bukan dua aksi yang bersaing. Yang membedakan
 * keduanya urutannya, bukan warnanya, dan memberi salah satunya rona lain akan
 * menyarankan salah satu lebih berisiko daripada yang lain.
 */
const TOMBOL_UTAMA = cn(buttonVariants({ size: 'sm' }), 'gap-1.5 bg-brand text-white shadow-xs shadow-brand/25 hover:bg-brand-terang');

const TOMBOL_KIRIM = cn(buttonVariants(), 'gap-2 bg-brand text-white shadow-xs shadow-brand/25 hover:bg-brand-terang');

/*
 * Tombol pencabutan memakai destructive, satu-satunya rona merah yang boleh ada
 * di portal. Di sini artinya tepat: bukan nada berita, melainkan aksi yang
 * merusak dan tidak bisa dibatalkan. Tombol batal sengaja dibuat netral dan
 * ditaruh lebih dulu, jadi jalur yang paling mudah ditekan adalah jalur yang
 * tidak menghapus apa pun.
 */
const TOMBOL_HAPUS = cn(buttonVariants({ variant: 'destructive', size: 'sm' }), 'gap-1.5');

const TOMBOL_BATAL = cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'gap-1.5');

/** Kabut warna di sudut kartu, sama dengan yang dipakai beranda portal. */
const kabut = (token: string, opasitas = 0.09) => ({
    background: `radial-gradient(26rem 14rem at 100% 0%, rgb(from var(${token}) r g b / ${opasitas}), transparent 72%)`,
});
</script>

<template>
    <Head title="Tambah berita" />

    <LayoutPortal
        lebar="sedang"
        :breadcrumbs="[
            { title: 'Portal media', href: '/portal' },
            { title: 'Tambah berita', href: '/portal/lapor' },
        ]"
    >
        <KopHalaman
            judul="Tambah berita"
            keterangan="Berita yang Anda tambahkan masuk antrean penilaian yang sama dengan berita temuan sistem. Domainnya diperiksa lebih dulu, jadi hanya berita dari media Anda sendiri yang bisa dikirim."
        >
            <PilKop :ikon="CheckCircle2">{{ formatAngka(props.sudahOtomatis.length) }} sudah terpantau, 30 hari terakhir</PilKop>
            <PilKop v-if="kirimanDiproses > 0" nada="kerja" :ikon="Clock"> {{ formatAngka(kirimanDiproses) }} kiriman Anda sedang dinilai </PilKop>
        </KopHalaman>

        <!--
            Tiga langkah, bukan empat kartu sejajar.

            Sebelumnya halaman ini adalah empat kartu berukuran sama yang berdiri
            berurutan tanpa satu pun penanda bahwa urutan itu berarti. Padahal
            urutannya adalah alur kerjanya: daftar yang sudah terpantau harus
            dibaca lebih dulu supaya media tidak mengirim ulang berita yang sudah
            ada, dan hasil pemeriksaan hanya berarti setelah tautannya ditempel.
            Kartu keempat bukan langkah, ia catatan hasil, jadi ia keluar dari
            rangkaian ini dan berdiri sendiri di bawah.

            Langkah satu dan tiga selalu dirender, termasuk saat isinya kosong.
            Menyembunyikannya membuat penomorannya melompat, dan nomor yang
            melompat memaksa pembaca mencari langkah yang sebenarnya tidak pernah
            ada.
        -->
        <ol class="space-y-0">
            <li class="langkah muncul relative pb-5 pl-11" style="animation-delay: 60ms">
                <span class="angka absolute top-0 left-0 grid size-8 place-items-center rounded-lg bg-brand text-sm font-semibold text-white">
                    1
                </span>

                <div class="space-y-2 pt-1">
                    <h2 class="text-sm leading-tight font-semibold">Lihat dulu yang sudah terpantau</h2>
                    <p class="max-w-[70ch] text-xs leading-relaxed text-muted-foreground">
                        Berita yang sudah ada di daftar ini tidak perlu ditambahkan, karena sistem sudah menemukannya sendiri. Daftarnya bercampur:
                        sebagian ditemukan otomatis, sebagian masuk karena Anda kirim.
                    </p>

                    <Card v-if="props.sudahOtomatis.length" class="relative overflow-hidden">
                        <div class="pointer-events-none absolute inset-0" :style="kabut('--color-brand')" aria-hidden="true"></div>

                        <CardContent class="relative p-0">
                            <ul class="max-h-56 divide-y overflow-y-auto">
                                <li
                                    v-for="a in props.sudahOtomatis"
                                    :key="a.id"
                                    class="flex items-baseline gap-3 px-4 py-2 transition-colors hover:bg-muted/60"
                                >
                                    <span class="angka shrink-0 text-xs text-muted-foreground">{{ tanggal(a.tanggal) }}</span>
                                    <a :href="a.url" target="_blank" rel="noopener noreferrer" class="truncate text-sm hover:underline">
                                        {{ a.judul ?? a.url }}
                                    </a>
                                    <!-- Penanda asal baris, bukan penilaian atas
                                         isinya. Daftar ini bercampur, dan tanpa
                                         penanda media membacanya sebagai bukti
                                         crawler menemukan semuanya sendiri. -->
                                    <Badge v-if="a.ditambahkan_sendiri" variant="outline" class="ml-auto shrink-0 gap-1 border-dashed font-normal">
                                        <PlusCircle class="size-3 shrink-0" aria-hidden="true" />
                                        Anda tambahkan
                                    </Badge>
                                    <Badge v-else variant="secondary" class="ml-auto shrink-0 font-normal">Otomatis</Badge>
                                </li>
                            </ul>
                        </CardContent>
                    </Card>

                    <p v-else class="rounded-md border border-dashed px-4 py-3 text-xs leading-relaxed text-muted-foreground">
                        Belum ada berita media Anda yang terpantau dalam 30 hari terakhir. Lanjut ke langkah dua dan tempel tautannya langsung.
                    </p>
                </div>
            </li>

            <li class="langkah muncul relative pb-5 pl-11" style="animation-delay: 120ms">
                <span class="angka absolute top-0 left-0 grid size-8 place-items-center rounded-lg bg-brand text-sm font-semibold text-white">
                    2
                </span>

                <div class="space-y-2 pt-1">
                    <h2 class="text-sm leading-tight font-semibold">Tempel tautan yang belum ada di daftar itu</h2>
                    <p class="max-w-[70ch] text-xs leading-relaxed text-muted-foreground">
                        Judul dan tanggal dibaca sistem dari halamannya. Anda tidak perlu mengetik apa pun kecuali halamannya gagal dibaca.
                    </p>

                    <Card class="relative overflow-hidden">
                        <div class="pointer-events-none absolute inset-0" :style="kabut('--color-brand')" aria-hidden="true"></div>

                        <CardContent class="relative space-y-3 pt-6">
                            <div class="space-y-1.5">
                                <Label for="tautan" class="flex items-center gap-1.5">
                                    <Link2 class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                                    Satu tautan per baris
                                </Label>
                                <textarea
                                    id="tautan"
                                    v-model="formPeriksa.tautan"
                                    rows="5"
                                    placeholder="https://contoh.id/berita-pertama&#10;https://contoh.id/berita-kedua"
                                    class="w-full rounded-md border border-input bg-background p-3 font-mono text-xs transition-colors duration-150 placeholder:text-muted-foreground focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-card focus-visible:outline-hidden"
                                    :aria-invalid="formPeriksa.errors.tautan ? true : undefined"
                                    aria-describedby="tautan-bantuan"
                                />
                                <p v-if="formPeriksa.errors.tautan" class="flex items-start gap-1.5 text-xs text-destructive">
                                    <AlertTriangle class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                                    {{ formPeriksa.errors.tautan }}
                                </p>
                                <p id="tautan-bantuan" class="text-xs text-muted-foreground">
                                    Paling banyak 50 tautan sekali periksa. Tautan yang sama tidak dihitung dua kali.
                                </p>
                            </div>

                            <button
                                type="button"
                                :class="TOMBOL_UTAMA"
                                :disabled="formPeriksa.processing || !formPeriksa.tautan.trim()"
                                @click="periksa"
                            >
                                <Loader2 v-if="formPeriksa.processing" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                                <Search v-else class="size-4" aria-hidden="true" />
                                {{ formPeriksa.processing ? 'Memeriksa halamannya' : 'Periksa' }}
                            </button>
                        </CardContent>
                    </Card>
                </div>
            </li>

            <li class="langkah langkah-akhir muncul relative pl-11" style="animation-delay: 180ms">
                <span class="angka absolute top-0 left-0 grid size-8 place-items-center rounded-lg bg-brand text-sm font-semibold text-white">
                    3
                </span>

                <div class="space-y-2 pt-1">
                    <h2 class="text-sm leading-tight font-semibold">Periksa hasilnya, lalu tambahkan</h2>
                    <p class="max-w-[70ch] text-xs leading-relaxed text-muted-foreground">
                        Setiap tautan diperiksa satu per satu. Yang sudah ada di sistem dan yang ditolak tidak ikut ditambahkan, jadi Anda tidak perlu
                        membersihkan daftarnya sendiri.
                    </p>

                    <!--
                        Keadaan kosong yang mengajarkan antarmukanya, bukan yang
                        mengabarkan ketiadaan. Sebelumnya seluruh kartu ini tidak
                        dirender sampai tombol Periksa ditekan, jadi media yang baru
                        pertama membuka halaman tidak punya cara mengetahui bahwa
                        masih ada satu langkah lagi setelah menempel tautan.
                    -->
                    <p v-if="!hasil.length" class="rounded-md border border-dashed px-4 py-3 text-xs leading-relaxed text-muted-foreground">
                        Hasil pemeriksaan muncul di sini setelah tombol Periksa ditekan. Setiap baris menyebut apakah halamannya terbaca, sudah ada di
                        sistem, atau ditolak karena domainnya bukan milik media Anda.
                    </p>

                    <template v-else>
                        <Card class="relative overflow-hidden">
                            <div class="pointer-events-none absolute inset-0" :style="kabut('--color-aksen-toska')" aria-hidden="true"></div>

                            <CardContent class="relative space-y-3 pt-6">
                                <div
                                    v-for="b in hasil"
                                    :key="b.url_kanonik"
                                    class="space-y-2 rounded-md border bg-card p-3"
                                    :class="GAYA_HASIL[b.status].bingkai"
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <p class="flex min-w-0 items-start gap-2 text-xs break-all text-muted-foreground">
                                            <span
                                                class="mt-1.5 size-1.5 shrink-0 rounded-full"
                                                :class="GAYA_HASIL[b.status].titik"
                                                aria-hidden="true"
                                            ></span>
                                            {{ b.url }}
                                        </p>

                                        <span
                                            class="inline-flex shrink-0 items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                                            :class="GAYA_HASIL[b.status].lencana"
                                        >
                                            <component :is="GAYA_HASIL[b.status].ikon" class="size-3.5 shrink-0" aria-hidden="true" />
                                            {{ GAYA_HASIL[b.status].label }}
                                        </span>
                                    </div>

                                    <p
                                        v-if="b.pesan"
                                        class="flex items-start gap-1.5 text-xs"
                                        :class="b.status === 'domain_salah' ? 'text-destructive' : 'text-muted-foreground'"
                                    >
                                        <component
                                            :is="b.status === 'domain_salah' ? AlertTriangle : Info"
                                            class="mt-0.5 size-3 shrink-0"
                                            aria-hidden="true"
                                        />
                                        {{ b.pesan }}
                                    </p>

                                    <!-- Berhasil: judul dan tanggal ditampilkan untuk dicek
                                         sekilas, tidak untuk diketik ulang. -->
                                    <template v-if="b.status === 'berhasil'">
                                        <p class="text-sm font-medium">{{ b.judul }}</p>
                                        <p class="angka text-xs text-muted-foreground">Terbit {{ tanggal(b.tanggal) }}</p>
                                    </template>

                                    <!-- F-51. Hanya kasus ini yang meminta isian tambahan. -->
                                    <div v-else-if="b.status === 'gagal' && isian[b.url_kanonik]" class="grid gap-2 sm:grid-cols-2">
                                        <div class="space-y-1 sm:col-span-2">
                                            <Label :for="`judul-${b.url_kanonik}`" class="text-xs">Judul berita</Label>
                                            <Input :id="`judul-${b.url_kanonik}`" v-model="isian[b.url_kanonik].judul" class="h-8" />
                                        </div>
                                        <div class="space-y-1">
                                            <Label :for="`tanggal-${b.url_kanonik}`" class="text-xs">Tanggal terbit</Label>
                                            <Input :id="`tanggal-${b.url_kanonik}`" v-model="isian[b.url_kanonik].tanggal" type="date" class="h-8" />
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <!--
                            Tombol kirim berdiri di luar kartu hasil, bukan di
                            dalamnya. Ia mengomit seluruh daftar sekaligus, dan
                            tombol yang duduk di dalam kotak berisi baris-baris
                            terbaca seolah hanya mengurus baris terakhir.
                        -->
                        <div class="space-y-2 pt-1">
                            <p v-if="formKirim.errors.baris" class="flex items-start gap-1.5 text-xs text-destructive">
                                <AlertTriangle class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                                {{ formKirim.errors.baris }}
                            </p>

                            <button type="button" :class="TOMBOL_KIRIM" :disabled="formKirim.processing || !bisaDikirim.length" @click="kirim">
                                <Loader2 v-if="formKirim.processing" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                                <Send v-else class="size-4" aria-hidden="true" />
                                {{ formKirim.processing ? 'Menambahkan' : `Tambahkan semua (${bisaDikirim.length})` }}
                            </button>

                            <p class="text-xs text-muted-foreground">Tautan yang sudah ada atau ditolak tidak ikut ditambahkan.</p>
                        </div>
                    </template>
                </div>
            </li>
        </ol>

        <!--
            Perlu ada karena berita kiriman tidak langsung tampil di "Berita
            saya": ia harus diunduh dan dinilai lebih dulu. Tanpa daftar ini
            media menambah berita, tidak melihatnya muncul, lalu menambahnya
            lagi.

            Di luar rangkaian langkah, karena ia bukan langkah. Ia catatan hasil
            dari kiriman-kiriman sebelumnya, dan memberinya nomor empat akan
            menyarankan ada sesuatu yang harus dikerjakan di situ.
        -->
        <Card v-if="props.kiriman.length" class="muncul relative overflow-hidden" style="animation-delay: 240ms">
            <div class="pointer-events-none absolute inset-0" :style="kabut('--color-aksen-ungu')" aria-hidden="true"></div>

            <CardContent class="relative p-0">
                <div class="flex items-center gap-2 border-b px-4 py-3">
                    <div class="grid size-7 shrink-0 place-items-center rounded-md bg-aksen-ungu/10 text-aksen-ungu">
                        <Plus class="size-4" aria-hidden="true" />
                    </div>
                    <h2 class="text-sm font-semibold">Berita yang Anda tambahkan</h2>
                    <span class="angka ml-auto shrink-0 text-xs text-muted-foreground">{{ formatAngka(props.kiriman.length) }} kiriman</span>
                </div>

                <ul class="divide-y">
                    <li v-for="k in props.kiriman" :key="k.id" class="group/baris px-4 py-2.5 transition-colors hover:bg-muted/60">
                        <div class="flex items-start justify-between gap-3">
                            <a :href="k.url" target="_blank" rel="noopener noreferrer" class="min-w-0 text-sm hover:underline">
                                <span class="line-clamp-2">{{ k.judul ?? k.url }}</span>
                            </a>

                            <div class="flex shrink-0 items-center gap-1.5">
                                <BadgeTahapPortal :tahap="k.status" class="mt-0.5" />

                                <!--
                                    Merah baru muncul saat tombolnya disorot atau
                                    difokus, sebelum itu ia abu.

                                    Daftar ini bisa memuat seratus baris, dan
                                    seratus ikon merah berjajar membuat halaman
                                    terbaca seolah ada seratus masalah. Merah di
                                    portal berarti galat yang perlu ditindak, dan
                                    tombol yang belum disentuh belum menjadi galat
                                    apa pun. Warnanya menyala persis pada saat
                                    artinya berlaku, yaitu ketika jarinya sudah di
                                    atas tombol.

                                    `focus-visible` ikut memicu warnanya, kalau
                                    tidak pengguna keyboard tidak pernah melihat
                                    peringatan yang dilihat pengguna tetikus.
                                -->
                                <button
                                    type="button"
                                    class="tekan grid size-7 shrink-0 place-items-center rounded-md text-muted-foreground transition-colors duration-150 hover:bg-destructive/10 hover:text-destructive focus-visible:bg-destructive/10 focus-visible:text-destructive focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                    :title="`Cabut berita ini dari sistem`"
                                    @click="sasaranHapus = k"
                                >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                    <span class="sr-only">Cabut {{ k.judul ?? k.url }} dari sistem</span>
                                </button>
                            </div>
                        </div>
                        <p class="angka mt-1 text-xs text-muted-foreground">Terbit {{ tanggal(k.tanggal) }}</p>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <!--
            Konfirmasi pencabutan, satu untuk seluruh daftar.

            Judulnya menyebut tindakannya, bukan pertanyaan sopan. Isinya
            menyebut berita mana yang akan dicabut, apa akibatnya terhadap angka
            realisasi, dan bahwa crawler bisa menemukannya lagi. Yang terakhir
            itu bukan penghalusan: PembuangArtikel memang tidak menyimpan nisan
            URL, jadi berita yang masih terbit di situs medianya benar-benar bisa
            masuk lagi pada penyisiran berikutnya, dan media yang tidak tahu itu
            akan mengira pencabutannya gagal.
        -->
        <Dialog :open="sasaranHapus !== null" @update:open="(terbuka) => (terbuka ? null : (sasaranHapus = null))">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <span class="grid size-7 shrink-0 place-items-center rounded-md bg-destructive/10 text-destructive">
                            <AlertTriangle class="size-4" aria-hidden="true" />
                        </span>
                        Cabut berita ini dari sistem
                    </DialogTitle>
                    <DialogDescription class="space-y-3 pt-1 text-left">
                        <span class="block rounded-md border bg-muted/50 px-3 py-2 text-sm font-medium text-foreground">
                            {{ sasaranHapus?.judul ?? sasaranHapus?.url }}
                        </span>

                        <span class="block text-sm" :class="peringatanHapus.nada">{{ peringatanHapus.teks }}</span>

                        <span class="block text-sm">
                            Pencabutannya permanen dan tidak masuk tempat sampah. Kalau beritanya masih terbit di situs Anda, sistem bisa menemukannya
                            lagi sendiri pada penyisiran berikutnya.
                        </span>
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="gap-2 sm:gap-2">
                    <DialogClose as-child>
                        <button type="button" :class="TOMBOL_BATAL" :disabled="formHapus.processing">Batal</button>
                    </DialogClose>

                    <button type="button" :class="TOMBOL_HAPUS" :disabled="formHapus.processing" @click="hapus">
                        <Loader2 v-if="formHapus.processing" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                        <Trash2 v-else class="size-4" aria-hidden="true" />
                        {{ formHapus.processing ? 'Mencabut' : 'Cabut berita' }}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </LayoutPortal>
</template>

<style scoped>
/*
 * Rel yang menghubungkan ketiga nomor langkah.
 *
 * Digambar sebagai pseudo elemen, bukan div, supaya daftar bernomor yang
 * dibacakan pembaca layar tidak berisi simpul kosong. Garisnya berhenti di
 * langkah terakhir, kalau tidak ia menjuntai ke bawah tanpa tujuan dan menunjuk
 * ke kartu yang justru sengaja berada di luar rangkaian.
 *
 * Ia memudar ke bawah, jadi ujungnya tidak pernah bertabrakan dengan kotak nomor
 * berikutnya. Turunnya dianimasikan sekali saat halaman terbuka, dengan jeda
 * yang jatuh setelah ketiga langkahnya selesai masuk.
 */
.langkah::before {
    content: '';
    position: absolute;
    left: 0.9375rem;
    top: 2.5rem;
    bottom: 0.75rem;
    width: 1px;
    background: linear-gradient(180deg, hsl(var(--border)) 0%, hsl(var(--border) / 0.25) 100%);
    transform-origin: top;
    animation: langkah-turun 700ms cubic-bezier(0.32, 0.72, 0, 1) both;
    animation-delay: 300ms;
}

.langkah-akhir::before {
    display: none;
}

@keyframes langkah-turun {
    from {
        transform: scaleY(0);
    }

    to {
        transform: scaleY(1);
    }
}

@media (prefers-reduced-motion: reduce) {
    .langkah::before {
        animation: none;
    }
}
</style>
