<script setup lang="ts">
import KartuArtikel from '@/components/domain/KartuArtikel.vue';
import KopHalaman from '@/components/domain/KopHalaman.vue';
import PilKop from '@/components/domain/PilKop.vue';
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutPortal from '@/layouts/LayoutPortal.vue';
import { cn } from '@/lib/utils';
import { Head, Link } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { ArrowRight, CalendarRange, Clock, HelpCircle, Newspaper, Plus, PlusCircle } from 'lucide-vue-next';
import { computed, type Component } from 'vue';

type Tahap = 'tampil' | 'diproses' | 'di_luar_pantauan' | 'gagal';

const props = defineProps<{
    /** Nama media pengguna. Null kalau akunnya belum ditautkan ke media mana pun. */
    media: string | null;
    periode: { dari: string; sampai: string };
    kpi: { berita_30_hari: number; ditambahkan_sendiri: number; sedang_diproses: number };
    beritaTerbaru: {
        id: number;
        judul: string;
        url: string;
        media: string | null;
        diambil_at: string;
        ditambahkan_sendiri: boolean;
        tahap: Tahap;
    }[];
}>();

const { formatAngka } = useFormatAngka();

const judul = computed(() => props.media ?? 'Portal media');

const rentang = computed(
    () =>
        `${format(new Date(props.periode.dari), 'd MMM', { locale: id })} sampai ` +
        `${format(new Date(props.periode.sampai), 'd MMM yyyy', { locale: id })}`,
);

/*
 * Kosakata warna portal media, dan satu larangan yang membentuknya.
 *
 * Portal ini tidak boleh menampilkan nada berita sama sekali (dokumen 01 bagian
 * 8), dan konsekuensinya lebih jauh daripada sekadar menyembunyikan badge
 * sentimen: token `--color-sentimen-*` tidak boleh dipakai untuk apa pun di
 * sini. Hijau, kuning, dan merah sentimen sudah berarti nada di seluruh
 * aplikasi, dan meminjamnya untuk tahap pemrosesan membuat media membaca
 * lencana teknis sebagai penilaian atas isi beritanya.
 *
 * Yang tersisa cukup untuk membedakan seluruh keadaan yang perlu dibedakan:
 *
 * | Rona          | Arti di portal                          |
 * |---------------|-----------------------------------------|
 * | Navy merek    | Tuntas, terhitung, dan aksi utama       |
 * | Aksen ungu    | Mesin penilai sedang bekerja            |
 * | Abu netral    | Di luar lingkup, atau sekadar keterangan|
 * | Destructive   | Galat teknis yang perlu ditindaklanjuti |
 *
 * Rona yang sama dipakai BadgeTahapPortal, jadi titik di rel daftar dan lencana
 * di ujung baris yang sama tidak pernah menyebut dua hal berbeda.
 */

/**
 * Tiga angka beserta jendela waktunya masing-masing.
 *
 * Keterangan jendela menempel pada angkanya, bukan pada judul stripnya, karena
 * yang ketiga memang tidak mengikuti jendela yang sama. Satu judul "30 hari
 * terakhir" di atas ketiganya akan salah untuk salah satunya, dan angka yang
 * keterangannya salah lebih buruk daripada angka yang tidak ada.
 *
 * Hanya angka ketiga yang boleh berwarna. Ungu di sana berarti mesin penilai
 * sedang memegang kiriman media ini, arti yang sama dengan ungu di panel admin,
 * dan ia hilang sendiri begitu angkanya nol. Dua angka pertama sengaja putih:
 * keduanya keadaan normal, dan mewarnai ketiganya membuat tidak ada satu pun
 * yang menonjol.
 *
 * Ketiganya tidak digambar sebagai proporsi satu sama lain, dan itu disengaja.
 * "Berita terpantau" menghitung yang sudah relevan dan berlabel, sedangkan
 * "Anda tambahkan" menghitung seluruh kiriman apa pun hasil penilaiannya, jadi
 * yang kedua bukan bagian dari yang pertama. Bilah proporsi di antara keduanya
 * akan menggambar hubungan yang tidak ada.
 */
const statistik = computed<Array<{ label: string; nilai: number; keterangan: string; ikon: Component; angka: string }>>(() => [
    {
        label: 'Berita terpantau',
        nilai: props.kpi.berita_30_hari,
        keterangan: '30 hari terakhir',
        ikon: Newspaper,
        angka: 'text-white',
    },
    {
        label: 'Anda tambahkan',
        nilai: props.kpi.ditambahkan_sendiri,
        keterangan: '30 hari terakhir',
        ikon: PlusCircle,
        angka: 'text-white',
    },
    {
        label: 'Sedang diproses',
        nilai: props.kpi.sedang_diproses,
        keterangan: 'Belum selesai dinilai',
        ikon: Clock,
        angka: props.kpi.sedang_diproses > 0 ? 'text-violet-200' : 'text-white',
    },
]);

/**
 * Garis aliran di kop hanya bergerak kalau memang ada yang sedang diproses.
 *
 * Ornamen bersama dengan panel admin, dan artinya dijaga sama persis: cahaya
 * yang menyusuri garis berarti mesin sedang memegang sesuatu milik media ini,
 * garis yang diam berarti tidak ada yang menggantung. Media membuka portal ini
 * beberapa kali sebulan, dan pertanyaan pertamanya hampir selalu "kiriman saya
 * kemarin sudah diproses belum".
 */
const mengalir = computed(() => props.kpi.sedang_diproses > 0);

/**
 * Titik rel di daftar berita, sewarna dengan lencana tahap di ujung baris.
 *
 * Bukan penanda tunggal. Lencananya tetap ada dan membawa ikon serta teks, jadi
 * titik ini hanya membuat sebaran tahap sepanjang daftar terbaca sebagai satu
 * kolom warna tanpa memindai lencana satu per satu.
 */
const titikTahap: Record<Tahap, string> = {
    tampil: 'bg-brand dark:bg-brand-terang',
    diproses: 'bg-aksen-ungu',
    di_luar_pantauan: 'bg-muted-foreground/40',
    gagal: 'bg-destructive',
};

/*
 * Tombol diwarnai menurut tujuannya.
 *
 * Peran ini hanya punya satu aksi di seluruh portal, yaitu menambah berita yang
 * terlewat, jadi tombol itulah satu-satunya yang boleh berisian penuh. Di dalam
 * kop navy ia dibalik menjadi putih dengan teks navy, karena navy di atas navy
 * tidak akan terbaca sebagai tombol sama sekali. Di luar kop ia memakai navy
 * yang sama dengan aksi utama di panel admin.
 */
const TOMBOL_KOP = cn(
    buttonVariants({ size: 'sm' }),
    'gap-1.5 bg-white text-brand shadow-xs shadow-black/10 hover:bg-white/90 focus-visible:ring-offset-brand',
);

const TOMBOL_UTAMA = cn(buttonVariants({ size: 'sm' }), 'gap-1.5 bg-brand text-white shadow-xs shadow-brand/25 hover:bg-brand-terang');

const TOMBOL_NETRAL = cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'gap-1.5');

/**
 * Kabut warna di sudut kartu, sama dengan yang dipakai Dashboard admin.
 *
 * Satu gradien radial tunggal, tidak berulang, jadi ia terbaca sebagai kartu
 * yang punya suhu warna dan bukan sebagai pola yang harus ikut dibaca.
 */
const kabut = (token: string, opasitas = 0.09) => ({
    background: `radial-gradient(26rem 14rem at 100% 0%, rgb(from var(${token}) r g b / ${opasitas}), transparent 72%)`,
});
</script>

<template>
    <Head title="Portal media" />

    <!--
        Kop halaman diberi remah jejak, sebelumnya kosong sama sekali.

        AppSidebarHeader tidak menggambar apa pun saat remahnya kosong, jadi
        beranda portal menyisakan satu batang putih setinggi 64 piksel yang
        hanya berisi tombol lipat sidebar. Halaman lain di aplikasi ini selalu
        mengisinya, dan batang kosong itu terbaca sebagai halaman yang belum
        selesai dibuat.

        Remah pertama menyebut panelnya, bukan langsung nama halaman. Satu
        aplikasi ini menampung tiga panel dengan tiga peran, dan menyebut yang
        mana yang sedang dibuka menjawab pertanyaan yang lebih sering muncul
        daripada "saya ada di halaman apa".
    -->
    <LayoutPortal
        lebar="sedang"
        :breadcrumbs="[
            { title: 'Portal media', href: '/portal' },
            { title: 'Beranda', href: '/portal' },
        ]"
    >
        <!--
            Kop navy bersama, komponen yang sama dengan seluruh panel admin.

            Sebelumnya halaman ini menggambar kopnya sendiri: bidang navy dengan
            satu sapuan cahaya, disalin sekitar dua puluh baris dari komponen yang
            sudah ada. Bentuknya nyaris sama, tetapi busur sepusatnya tidak ada dan
            garis mereknya tidak ada, jadi portal terbaca sebagai aplikasi lain
            yang kebetulan memakai warna yang sama. Menyatukannya sekaligus
            menghapus salinan itu.
        -->
        <KopHalaman :judul="judul" :keterangan="`Berita media Anda yang masuk pantauan Pemerintah Kota Kendari, ${rentang}.`">
            <template #aksi>
                <Link href="/portal/lapor" :class="TOMBOL_KOP">
                    <Plus class="size-4" aria-hidden="true" />
                    Tambah berita terlewat
                </Link>
            </template>

            <PilKop :ikon="CalendarRange">{{ rentang }}</PilKop>
            <PilKop v-if="kpi.sedang_diproses > 0" nada="kerja" :ikon="Clock"> {{ formatAngka(kpi.sedang_diproses) }} kiriman sedang dinilai </PilKop>

            <template #bawah>
                <!--
                    Garis aliran, bergerak hanya selama ada kiriman yang belum
                    selesai dinilai. Ia berhenti sendiri begitu semuanya tuntas,
                    jadi gerakannya adalah keterangan, bukan hiasan yang kebetulan
                    berjalan terus.
                -->
                <div class="relative h-px w-full overflow-hidden rounded-full bg-white/15" :class="mengalir ? 'aliran' : ''" aria-hidden="true"></div>

                <!--
                    Tiga angka dalam satu bidang bergaris rambut, bukan tiga kartu
                    berjajar. Ketiganya dibaca bersama sebagai satu keadaan, dan
                    memisahkannya ke dalam tiga kotak bertepi justru menyarankan
                    tiga hal yang berdiri sendiri.
                -->
                <dl class="mt-4 grid grid-cols-3 divide-x divide-white/20">
                    <div v-for="s in statistik" :key="s.label" class="px-3 first:pl-0 last:pr-0 sm:px-5">
                        <dt class="flex items-center gap-1.5 text-xs font-medium text-white/75">
                            <span class="grid size-5 shrink-0 place-items-center rounded-md bg-white/10 text-white/85" aria-hidden="true">
                                <component :is="s.ikon" class="size-3" />
                            </span>
                            <span class="truncate">{{ s.label }}</span>
                        </dt>
                        <dd>
                            <span class="angka mt-1.5 block text-2xl leading-none font-semibold sm:text-3xl" :class="s.angka">
                                {{ formatAngka(s.nilai) }}
                            </span>
                            <span class="mt-1.5 block text-[11px] leading-tight text-white/65">{{ s.keterangan }}</span>
                        </dd>
                    </div>
                </dl>
            </template>
        </KopHalaman>

        <!--
            Berita terbaru, sekarang di dalam kartu bertepi.

            Sebelumnya daftar ini berdiri telanjang di atas latar halaman dengan
            alasan bahwa membungkusnya menambah satu lapisan yang harus dibaca.
            Alasan itu berlaku waktu kop halaman berupa bidang navy tanpa tepi
            yang menyatu ke latar. Setelah kop menjadi kartu navy bersudut membulat
            yang jelas tepinya, daftar tanpa tepi di bawahnya terbaca sebagai isi
            yang tercecer keluar dari kartu di atasnya, bukan sebagai bagian
            berikutnya.
        -->
        <Card class="muncul relative overflow-hidden" style="animation-delay: 90ms">
            <div class="pointer-events-none absolute inset-0" :style="kabut('--color-brand')" aria-hidden="true"></div>

            <CardHeader class="relative flex-row flex-wrap items-center justify-between gap-3 space-y-0 border-b py-3">
                <div class="flex min-w-0 items-center gap-2">
                    <div class="grid size-7 shrink-0 place-items-center rounded-md bg-brand-lembut text-brand dark:text-white">
                        <Newspaper class="size-4" aria-hidden="true" />
                    </div>
                    <CardTitle class="truncate text-sm font-semibold">Berita terbaru</CardTitle>
                </div>

                <Link href="/portal/berita" :class="TOMBOL_UTAMA">
                    Lihat semua berita
                    <ArrowRight class="size-4" aria-hidden="true" />
                </Link>
            </CardHeader>

            <CardContent class="relative pt-4">
                <p class="mb-3 max-w-[70ch] text-xs leading-relaxed text-muted-foreground">
                    Berita temuan sistem muncul setelah selesai dinilai, sedangkan berita yang Anda tambahkan sendiri muncul sejak dikirim, apa pun
                    tahapnya. Hanya yang berlencana "Tampil" yang ikut terhitung di Berita saya.
                </p>

                <KeadaanKosong
                    v-if="!props.beritaTerbaru.length"
                    class="rounded-lg border border-dashed"
                    judul="Belum ada berita dari media Anda"
                    keterangan="Sistem menyisir RSS media Anda setiap tiga jam, lalu menilai tiap berita. Kalau berita Anda tetap tidak muncul, tambahkan sendiri lewat tombol di atas."
                >
                    <Link href="/portal/lapor" :class="cn(TOMBOL_UTAMA, 'mt-2')">
                        <Plus class="size-4" aria-hidden="true" />
                        Tambah berita terlewat
                    </Link>
                </KeadaanKosong>

                <!--
                    Tanpa animasi masuk per baris, dan itu keputusan, bukan
                    kelalaian. Kelas `muncul` mulai dari opacity nol, jadi baris
                    yang diberi jeda bertahap terbaca sebagai teks pudar selama
                    hampir satu detik. Pada halaman yang dibuka untuk membaca
                    judul, yang dibayar dengan kepudaran itu tidak sebanding. Satu
                    momen masuk sudah dikerjakan kartunya sendiri.
                -->
                <ul v-else class="-mx-2 divide-y">
                    <li v-for="b in props.beritaTerbaru" :key="b.id" class="transition-colors hover:bg-muted/60">
                        <div class="flex items-start gap-3 px-2">
                            <span
                                class="mt-4 size-1.5 shrink-0 rounded-full ring-4 ring-transparent ring-inset"
                                :class="titikTahap[b.tahap]"
                                aria-hidden="true"
                            ></span>

                            <!-- tampilkanSentimen sengaja tidak dipasang. Portal
                                 media tidak pernah menampilkan nada berita. Tahap
                                 dan ditambahkanSendiri bukan sentimen: yang pertama
                                 menyebut relevansi, yang kedua menandai asal baris,
                                 dan keduanya bukan penilaian atas nada isinya. -->
                            <KartuArtikel
                                class="min-w-0 flex-1"
                                :judul="b.judul"
                                :url="b.url"
                                :media="b.media"
                                :diambil-at="b.diambil_at"
                                :ditambahkan-sendiri="b.ditambahkan_sendiri"
                                :tahap="b.tahap"
                            />
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <!--
            Penutup halaman, dan sekaligus jawaban atas satu-satunya pertanyaan
            yang membuat peran ini membuka portal: kenapa berita saya tidak ada
            di sini. Sebelumnya jawabannya hanya muncul di keadaan kosong,
            padahal justru media yang sudah punya beberapa berita terpantau yang
            paling sering menyadari ada yang hilang.
        -->
        <Card class="muncul relative overflow-hidden" style="animation-delay: 150ms">
            <div class="pointer-events-none absolute inset-0" :style="kabut('--color-aksen-toska')" aria-hidden="true"></div>

            <CardContent class="relative flex gap-3 pt-6">
                <div class="grid size-8 shrink-0 place-items-center rounded-lg bg-aksen-toska/10 text-aksen-toska">
                    <HelpCircle class="size-4" aria-hidden="true" />
                </div>

                <div class="min-w-0 space-y-2">
                    <h2 class="text-sm font-semibold">Berita Anda tidak muncul di daftar?</h2>
                    <p class="max-w-[65ch] text-xs leading-relaxed text-muted-foreground">
                        Sistem menyisir RSS media Anda setiap tiga jam, lalu menilai tiap berita. Berita yang baru terbit umumnya masuk dalam dua jam.
                        Lewat dari itu dan tetap tidak muncul, tempel tautannya dan sistem akan memeriksa halamannya langsung. Berita yang Anda
                        tambahkan masuk antrean penilaian yang sama dengan berita temuan sistem, dan tahapnya terbaca di daftar di atas.
                    </p>

                    <Link href="/portal/lapor" :class="cn(TOMBOL_NETRAL, 'mt-1')">
                        <Plus class="size-4" aria-hidden="true" />
                        Tambah berita terlewat
                    </Link>
                </div>
            </CardContent>
        </Card>
    </LayoutPortal>
</template>
