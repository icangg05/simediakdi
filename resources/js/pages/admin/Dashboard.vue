<script setup lang="ts">
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import IndikatorKesehatan from '@/components/domain/IndikatorKesehatan.vue';
import KopHalaman from '@/components/domain/KopHalaman.vue';
import PilKop from '@/components/domain/PilKop.vue';
import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/vue3';
import { formatDistanceToNow } from 'date-fns';
import { id } from 'date-fns/locale';
import {
    ArrowRight,
    ArrowUpRight,
    CircleAlert,
    CircleCheck,
    CircleX,
    Download,
    FileText,
    Inbox,
    Minus,
    Radio,
    Sparkles,
    Target,
    Timer,
    TrendingDown,
    TrendingUp,
    TriangleAlert,
    Zap,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, type Component } from 'vue';

type Status = 'hijau' | 'kuning' | 'merah';
type Label = 'negatif' | 'netral' | 'positif';

interface StatusKesehatan {
    status: Status;
    keterangan: string;
}

const props = defineProps<{
    /** Subjek yang dipantau sistem, dicetak di kop supaya cakupan angka jelas. */
    pantauan: string;
    ekstraksi: {
        mentah: number;
        masuk_hari_ini: number;
        diekstrak_hari_ini: number;
        persen: number;
        laju_per_menit: number;
        estimasi_selesai_at: string | null;
        belum_klasifikasi: number;
        antre_ai: number;
        gagal: number;
    };
    kpi: {
        artikel_hari_ini: number;
        artikel_pekan_ini: number;
        selisih_pekan_lalu: number;
        gagal_proses: number;
        media_aktif: number;
    };
    kesehatan: { crawler: StatusKesehatan; sumber: StatusKesehatan; gemini: StatusKesehatan };
    mediaBermasalah: Array<{
        id: number;
        nama: string;
        gagal_berturut: number;
        jumlah_sumber: number;
        pesan_error_terakhir: string | null;
    }>;
    artikelTerbaru: Array<{
        id: number;
        judul: string;
        media: string | null;
        diambil_at: string;
        label: Label | null;
        perlu_review: boolean;
    }>;
}>();

const { formatAngka, formatPersen } = useFormatAngka();

const sejak = (waktu: string) => formatDistanceToNow(new Date(waktu), { addSuffix: true, locale: id });

/** Satu desimal, koma sebagai pemisah, mengikuti penulisan angka Indonesia. */
const formatLaju = (nilai: number) => nilai.toFixed(1).replace('.', ',');

/*
 * Tanggal dipaksa ke zona Kendari, bukan zona mesin yang membuka halaman.
 *
 * Seluruh angka "hari ini" di halaman ini dipotong pada pukul 00.00 WITA oleh
 * Waktu::awalHariIni() di server. Kalau kop halaman mencetak tanggal menurut
 * jam laptop yang kebetulan diatur WIB, ada delapan jam setiap hari ketika
 * tanggal di kop dan tanggal yang dihitung angka di bawahnya berbeda satu hari.
 */
const hariIni = new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'full',
    timeZone: 'Asia/Makassar',
}).format(new Date());

/*
 * Kosakata warna halaman ini, sama persis dengan panel admin lainnya.
 *
 * Halaman ini sebelumnya hanya memakai dua rona, hijau kuning merah untuk
 * kesehatan dan navy untuk bilah kemajuan, sehingga seluruh angka lain berdiri
 * tanpa arti warna sama sekali. Sekarang setiap rona membawa arti yang sudah
 * ditetapkan Antrean AI dan halaman Berita, dan tidak ada rona yang dipakai
 * sebagai hiasan:
 *
 * | Rona            | Arti                                     |
 * |-----------------|------------------------------------------|
 * | Navy merek      | Pekerjaan yang tuntas, dan aksi utama    |
 * | Aksen toska     | Masuk lingkup pantauan                   |
 * | Aksen ungu      | Gemini sedang bekerja                    |
 * | Abu redup       | Belum dinilai, dan keadaan netral        |
 * | Hijau sentimen  | Nada positif, dan sistem sehat           |
 * | Kuning sentimen | Menunggu, atau perlu diperiksa           |
 * | Merah sentimen  | Nada negatif, dan pekerjaan gagal        |
 *
 * Volume pemberitaan sengaja tidak ikut diwarnai. Alasannya ada di catatan
 * arahPekan di bawah.
 */

/**
 * Ringkasan tiga lampu kesehatan menjadi satu penunjuk besar di kop.
 *
 * Yang terburuk yang menang, dan keterangan yang dibawa adalah keterangan milik
 * lampu terburuk itu, bukan kalimat umum. Kop yang berbunyi normal sementara
 * satu bagian di bawahnya merah adalah kop yang berbohong, dan admin berhenti
 * mempercayai seluruh penunjuk begitu satu di antaranya tertangkap salah sekali.
 *
 * Ronanya diambil dari palet Tailwind yang dicerahkan, bukan token sentimen,
 * karena penunjuk ini berdiri di atas navy kop. Perkecualian yang sama sudah
 * dijelaskan panjang di PilKop dan di Antrean AI: token sentimen dirancang
 * untuk latar terang dan hanya mencapai rasio 1,9 di atas navy ini.
 */
const peringkatStatus: Record<Status, number> = { hijau: 0, kuning: 1, merah: 2 };

const RUPA_STATUS: Record<Status, { label: string; titik: string; teks: string; bingkai: string; ikon: Component }> = {
    hijau: {
        label: 'Sistem berjalan normal',
        titik: 'bg-emerald-300',
        teks: 'text-emerald-200',
        bingkai: 'border-emerald-300/40 bg-emerald-300/10',
        ikon: CircleCheck,
    },
    kuning: {
        label: 'Ada yang perlu diperiksa',
        titik: 'bg-amber-300',
        teks: 'text-amber-200',
        bingkai: 'border-amber-300/40 bg-amber-300/10',
        ikon: CircleAlert,
    },
    merah: {
        label: 'Ada bagian yang bermasalah',
        titik: 'bg-red-300',
        teks: 'text-red-200',
        bingkai: 'border-red-300/45 bg-red-300/10',
        ikon: CircleX,
    },
};

const lampuTerburuk = computed(() =>
    [props.kesehatan.crawler, props.kesehatan.sumber, props.kesehatan.gemini].reduce((a, b) =>
        peringkatStatus[b.status] > peringkatStatus[a.status] ? b : a,
    ),
);

const statusSistem = computed(() => ({
    ...RUPA_STATUS[lampuTerburuk.value.status],
    keterangan: lampuTerburuk.value.keterangan,
    berdenyut: lampuTerburuk.value.status !== 'hijau',
}));

/*
 * Perubahan volume dibawakan ikon dan kata, sengaja tanpa warna nada.
 *
 * Hijau untuk naik dan merah untuk turun akan membaca kenaikan jumlah berita
 * sebagai kabar baik. Sistem ini mengukur volume dan nada pemberitaan, ia tidak
 * menilai kinerja, dan pewarnaan itu menyelundupkan penilaian yang bukan haknya.
 * Aturan ini tetap berlaku sesudah seluruh halaman diberi warna berfungsi:
 * warna di sini hanya berarti keadaan sistem atau lingkup pantauan, tidak pernah
 * berarti bagus atau jelek bagi Pemkot.
 */
const arahPekan = computed(() => {
    const selisih = props.kpi.selisih_pekan_lalu;

    if (selisih === 0) return { ikon: Minus, teks: 'sama dengan pekan sebelumnya' };

    return selisih > 0
        ? { ikon: TrendingUp, teks: `naik ${formatAngka(selisih)} dari pekan sebelumnya` }
        : { ikon: TrendingDown, teks: `turun ${formatAngka(Math.abs(selisih))} dari pekan sebelumnya` };
});

/**
 * Pil keterangan di kop: pekerjaan yang sedang tertunda, bukan daftar angka.
 *
 * Hanya keadaan yang menuntut sesuatu yang dicetak. Kalau tidak ada satu pun,
 * barisnya diganti satu pil hijau yang mengatakan itu, karena baris kosong di
 * kop terbaca sebagai data yang gagal dimuat, bukan sebagai ketiadaan pekerjaan.
 */
const pilPekerjaan = computed(() => {
    const pil: Array<{ kunci: string; nada: 'baik' | 'tunggu' | 'buruk' | 'kerja'; ikon: Component; teks: string }> = [];

    if (props.ekstraksi.mentah > 0) {
        pil.push({
            kunci: 'mentah',
            nada: 'tunggu',
            ikon: Download,
            teks: `${formatAngka(props.ekstraksi.mentah)} menunggu ekstraksi`,
        });
    }

    if (props.ekstraksi.antre_ai > 0) {
        pil.push({
            kunci: 'antre-ai',
            nada: 'kerja',
            ikon: Sparkles,
            teks: `${formatAngka(props.ekstraksi.antre_ai)} antre penilaian AI`,
        });
    }

    if (props.kpi.gagal_proses > 0) {
        pil.push({
            kunci: 'gagal',
            nada: 'buruk',
            ikon: TriangleAlert,
            teks: `${formatAngka(props.kpi.gagal_proses)} gagal diproses`,
        });
    }

    if (pil.length === 0) {
        pil.push({ kunci: 'sepi', nada: 'baik', ikon: CircleCheck, teks: 'Tidak ada pekerjaan tertunda' });
    }

    return pil;
});

/**
 * Perjalanan artikel hari ini, tiga tahap dari satu kelompok yang sama.
 *
 * Ketiganya menghitung artikel yang `diambil_at` nya jatuh setelah pukul 00.00
 * WITA, jadi angkanya memang boleh dibandingkan satu sama lain dan bilahnya
 * memang boleh diukur terhadap tahap pertama. Ini yang dulu tidak terjawab
 * halaman ini: angka berita hari ini berdiri sendiri tanpa penyebut, dan tidak
 * ada cara melihat apakah angka kecil berarti sepi berita atau berarti
 * ekstraksinya tertinggal.
 *
 * Bilah setiap tahap menyusut mengikuti angkanya, jadi penyusutan dari tahap ke
 * tahap adalah bentuk corongnya sendiri, bukan gambar corong yang ditempel.
 */
const aliranHariIni = computed(() => {
    const masuk = props.ekstraksi.masuk_hari_ini;
    const lebar = (n: number) => (masuk === 0 ? 0 : Math.min(100, (n / masuk) * 100));

    return [
        {
            kunci: 'masuk',
            label: 'Artikel masuk',
            jumlah: masuk,
            lebar: masuk > 0 ? 100 : 0,
            ikon: Inbox,
            tile: 'bg-muted text-muted-foreground',
            bilah: 'bg-muted-foreground/30',
            angka: '',
            keterangan: 'Seluruh tarikan crawler hari ini, sebelum dinilai apa pun',
        },
        {
            kunci: 'ekstrak',
            label: 'Isi berhasil diambil',
            jumlah: props.ekstraksi.diekstrak_hari_ini,
            lebar: lebar(props.ekstraksi.diekstrak_hari_ini),
            ikon: FileText,
            tile: 'bg-brand-lembut text-brand dark:text-white',
            bilah: 'bg-brand dark:bg-brand-terang',
            angka: '',
            keterangan: 'Halaman medianya terunduh utuh dan siap dinilai',
        },
        {
            kunci: 'relevan',
            label: 'Dinilai relevan',
            jumlah: props.kpi.artikel_hari_ini,
            lebar: lebar(props.kpi.artikel_hari_ini),
            ikon: Target,
            tile: 'bg-aksen-toska/10 text-aksen-toska',
            bilah: 'bg-aksen-toska',
            angka: 'text-aksen-toska',
            keterangan: `Masuk lingkup pantauan ${props.pantauan}`,
        },
    ];
});

/**
 * Garis aliran di kop hanya bergerak kalau memang ada yang mengalir.
 *
 * Ornamen ini dipinjam dari halaman Antrean AI dan artinya dijaga sama persis:
 * cahaya yang menyusuri garis berarti masih ada pekerjaan berjalan, garis yang
 * diam berarti tidak ada. Kalau ia tetap bergerak saat antreannya kosong, ia
 * berbohong lebih keras daripada teks apa pun yang bisa ditulis di sebelahnya.
 */
const mengalir = computed(() => props.ekstraksi.mentah > 0 || props.ekstraksi.antre_ai > 0);

/** Bilah kegagalan media diukur terhadap yang terparah, bukan terhadap angka tetap. */
const gagalTerparah = computed(() => Math.max(1, ...props.mediaBermasalah.map((m) => m.gagal_berturut)));

/**
 * Titik nada di rel kiri daftar berita.
 *
 * Ronanya sama persis dengan BadgeSentimen yang berdiri di baris yang sama,
 * jadi titik ini bukan penanda kedua yang harus dipelajari, ia hanya membuat
 * sebaran nada sepanjang daftar terbaca sebagai satu kolom warna. Karena
 * badgenya tetap ada dan membawa ikon serta teks, titik ini tidak pernah
 * menjadi satu-satunya penanda dan boleh berupa warna saja.
 */
const titikNada = (artikel: { label: Label | null; perlu_review: boolean }) => {
    if (artikel.perlu_review || artikel.label === null) {
        return 'bg-sentimen-review ring-sentimen-review/15';
    }

    return {
        positif: 'bg-sentimen-positif ring-sentimen-positif/15',
        netral: 'bg-sentimen-netral ring-sentimen-netral/15',
        negatif: 'bg-sentimen-negatif ring-sentimen-negatif/15',
    }[artikel.label];
};

/*
 * Tombol diwarnai menurut tujuannya, bukan menurut posisinya di kartu.
 *
 * Navy untuk jalur utama halaman ini, yaitu membuka daftar berita. Ungu untuk
 * yang menuju Gemini. Merah untuk yang menuju kegagalan yang perlu
 * ditindaklanjuti. Abu netral untuk yang sekadar berpindah halaman tanpa
 * membawa keadaan apa pun. Ronanya sama dengan rona angka yang diantarkannya,
 * jadi tombol dan angka yang berhubungan terbaca sepasang.
 *
 * Semuanya tautan, tidak satu pun mengubah data. Dashboard adalah tempat
 * melihat, dan aksi tulis punya halamannya sendiri yang membawa konteks lengkap
 * untuk memutuskan.
 */
const TOMBOL_UTAMA = cn(buttonVariants({ size: 'sm' }), 'gap-1.5 bg-brand text-white shadow-xs shadow-brand/25 hover:bg-brand-terang');

const TOMBOL_NETRAL = cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'gap-1.5');

const TOMBOL_GEMINI = cn(
    buttonVariants({ variant: 'outline', size: 'sm' }),
    'gap-1.5 border-aksen-ungu/35 bg-aksen-ungu/10 text-aksen-ungu hover:bg-aksen-ungu/20 hover:text-aksen-ungu',
);

const TOMBOL_GAGAL = cn(
    buttonVariants({ variant: 'outline', size: 'sm' }),
    'gap-1.5 border-sentimen-negatif/35 bg-sentimen-negatif-lembut text-sentimen-negatif hover:bg-sentimen-negatif/20 hover:text-sentimen-negatif',
);

/**
 * Kabut warna di sudut kartu, satu rona per kartu mengikuti isinya.
 *
 * Ditulis sebagai gradien inline, bukan kelas Tailwind, karena nilainya
 * dihitung dari token warna domain dengan sintaks warna relatif. Opasitasnya
 * sengaja rendah, sekitar delapan persen, jadi ia terbaca sebagai kartu yang
 * punya suhu warna, bukan sebagai bidang berwarna yang harus ikut dibaca.
 */
const kabut = (token: string, opasitas = 0.09) => ({
    background: `radial-gradient(26rem 14rem at 100% 0%, rgb(from var(${token}) r g b / ${opasitas}), transparent 72%)`,
});

/*
 * Menyegarkan hanya bagian ekstraksi, dan hanya selama masih ada artikel
 * mentah. Polling yang jalan terus pada dashboard yang menganggur adalah kueri
 * tiap lima detik selamanya, untuk angka yang tidak berubah.
 *
 * Lima detik, bukan sepuluh. Ekstraksi menghabiskan puluhan artikel per menit,
 * jadi angka yang tertinggal sepuluh detik sudah terasa salah saat dipandangi.
 * Antrean AI di kartu yang sama bergerak sekitar satu artikel per menit dan
 * tidak butuh serapat itu, ia ikut tersegarkan karena kebetulan satu prop.
 */
let pewaktu: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    pewaktu = setInterval(() => {
        if (props.ekstraksi.mentah === 0) return;
        router.reload({ only: ['ekstraksi'] });
    }, 5_000);
});

onUnmounted(() => clearInterval(pewaktu));
</script>

<template>
    <Head title="Dashboard admin" />

    <!--
        Breadcrumb tetap dikirim walaupun Dashboard adalah halaman akar admin.

        Bilah kop aplikasi mengisi dirinya dari daftar ini. Tanpa daftar itu
        bilahnya hanya berisi tombol sidebar dan sisanya kosong sepanjang layar,
        dan Dashboard menjadi satu-satunya halaman admin yang kopnya kosong.
    -->
    <LayoutAdmin :breadcrumbs="[{ title: 'Dashboard', href: '/admin' }]">
        <!--
            Kop navy bersama, komponen yang sama dengan empat belas halaman admin
            lainnya. Sebelumnya Dashboard menulis kopnya sendiri, dan akibatnya
            halaman pertama yang dilihat admin setiap pagi adalah satu-satunya
            halaman yang bentuk kopnya berbeda dari seluruh sistem.

            Isinya yang khas Dashboard tetap dipertahankan: kalimat yang
            menyebutkan cakupan angka, dan satu penunjuk kesehatan. Keduanya
            perlu dibaca sebelum angka mana pun di bawahnya dipercaya.
        -->
        <KopHalaman
            judul="Dashboard"
            :keterangan="`Pemantauan pemberitaan ${pantauan}. Hitungan berita dan daftar di halaman ini hanya mencakup artikel yang sudah dinilai relevan, artikel di luar pantauan tidak ikut dihitung.`"
        >
            <!--
                Penunjuk kesehatan, dibuat lebih besar daripada pil biasa karena
                inilah satu-satunya hal di halaman ini yang dibaca dari jauh.
                Bentuknya sengaja sama dengan penunjuk keadaan mesin di Antrean
                AI, sehingga admin yang berpindah antar dua halaman itu tidak
                perlu belajar dua kosakata untuk pertanyaan yang sama.

                `aria-live` polite supaya pembaca layar mengabarkan perubahan
                keadaan tanpa memotong bacaan yang sedang berjalan. Denyutnya
                tidak terbaca pembaca layar, jadi labelnya yang membawa maknanya.
            -->
            <template #aksi>
                <div
                    class="flex max-w-sm items-center gap-2.5 rounded-lg border px-3 py-2 backdrop-blur-xs"
                    :class="statusSistem.bingkai"
                    role="status"
                    aria-live="polite"
                >
                    <span class="relative flex size-2.5 shrink-0" aria-hidden="true">
                        <!-- Denyutnya dimatikan untuk pengguna yang meminta gerak
                             dikurangi. Maknanya tidak ikut hilang, keadaan yang
                             sama sudah ditulis lengkap sebagai teks di sebelahnya. -->
                        <span
                            v-if="statusSistem.berdenyut"
                            class="absolute inline-flex size-full animate-ping rounded-full opacity-75 motion-reduce:animate-none"
                            :class="statusSistem.titik"
                        />
                        <span class="relative inline-flex size-2.5 rounded-full" :class="statusSistem.titik" />
                    </span>

                    <div class="min-w-0 text-sm leading-tight">
                        <p class="font-medium" :class="statusSistem.teks">{{ statusSistem.label }}</p>
                        <p class="truncate text-xs text-white/70" :title="statusSistem.keterangan">{{ statusSistem.keterangan }}</p>
                    </div>
                </div>
            </template>

            <PilKop>{{ hariIni }}, waktu Kendari</PilKop>
            <PilKop v-for="pil in pilPekerjaan" :key="pil.kunci" :nada="pil.nada" :ikon="pil.ikon">{{ pil.teks }}</PilKop>

            <template #bawah>
                <!--
                    Garis aliran. Cahayanya menyusuri garis selama masih ada
                    artikel yang menunggu diproses, dan berhenti begitu semuanya
                    tuntas, jadi keadaan mesin terbaca dari ujung ruangan tanpa
                    membaca satu kata pun.
                -->
                <div class="relative h-px w-full overflow-hidden rounded-full bg-white/15" :class="mengalir ? 'aliran' : ''" aria-hidden="true"></div>

                <div class="mt-4 flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
                    <p class="text-xs text-white/60">
                        <span class="angka text-3xl font-semibold tracking-tight text-white">{{ formatAngka(kpi.artikel_pekan_ini) }}</span>
                        <span class="ml-2">berita relevan dalam 7 hari terakhir</span>
                    </p>

                    <p class="inline-flex items-center gap-1.5 text-xs text-white/70">
                        <component :is="arahPekan.ikon" class="size-3.5 shrink-0" aria-hidden="true" />
                        {{ arahPekan.teks }}
                    </p>
                </div>
            </template>
        </KopHalaman>

        <div class="grid gap-4 lg:grid-cols-3">
            <!--
                Perjalanan hari ini, bukan tiga angka berjajar.

                Susunan lama meletakkan berita hari ini, berita sepekan, dan
                jumlah media dalam satu kartu bergaris bagi. Ketiganya tidak
                punya hubungan apa pun satu sama lain, jadi garis pemisahnya
                memisahkan hal yang memang sudah terpisah dan tidak menjelaskan
                apa pun. Yang menggantikannya adalah tiga tahap dari satu
                kelompok artikel yang sama, dan di situ perbandingan antar
                angkanya benar-benar berarti.
            -->
            <Card class="muncul relative overflow-hidden lg:col-span-2" style="animation-delay: 60ms">
                <div class="pointer-events-none absolute inset-0" :style="kabut('--color-aksen-toska')" aria-hidden="true"></div>

                <CardHeader class="relative flex-row items-center justify-between gap-3 space-y-0 border-b py-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <div class="grid size-7 shrink-0 place-items-center rounded-md bg-aksen-toska/10 text-aksen-toska">
                            <Target class="size-4" aria-hidden="true" />
                        </div>
                        <CardTitle class="truncate text-sm font-semibold">Perjalanan berita hari ini</CardTitle>
                    </div>

                    <p class="angka shrink-0 text-xs text-muted-foreground">Sejak pukul 00.00 WITA</p>
                </CardHeader>

                <CardContent class="relative pt-4">
                    <p v-if="ekstraksi.masuk_hari_ini === 0" class="text-xs leading-relaxed text-muted-foreground">
                        Belum ada satu pun artikel masuk hari ini. Kalau crawler seharusnya sudah berjalan, periksa lampu Crawler di sebelah dan
                        halaman Log crawl.
                    </p>

                    <ol v-else class="space-y-4">
                        <li v-for="(tahap, i) in aliranHariIni" :key="tahap.kunci" class="relative pl-11">
                            <span class="absolute top-0 left-0 grid size-8 place-items-center rounded-lg" :class="tahap.tile">
                                <component :is="tahap.ikon" class="size-4" aria-hidden="true" />
                            </span>

                            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                                <p class="text-sm leading-tight font-medium">{{ tahap.label }}</p>
                                <p class="angka text-xl font-semibold tracking-tight" :class="tahap.angka">
                                    {{ formatAngka(tahap.jumlah) }}
                                    <span v-if="i > 0" class="angka ml-1 text-xs font-normal text-muted-foreground">
                                        {{ formatPersen(tahap.lebar) }}
                                    </span>
                                </p>
                            </div>

                            <!-- Bilahnya diukur terhadap tahap pertama, jadi
                                 penyusutannya sendiri yang membentuk corong. -->
                            <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                                <div
                                    class="tumbuh h-full rounded-full transition-[width] duration-500 ease-out"
                                    :class="tahap.bilah"
                                    :style="{ width: `${tahap.lebar}%`, animationDelay: `${180 + i * 110}ms` }"
                                />
                            </div>

                            <p class="mt-1 text-xs text-muted-foreground">{{ tahap.keterangan }}</p>
                        </li>
                    </ol>
                </CardContent>
            </Card>

            <!--
                Kesehatan sistem. Rona kartunya hijau kalau semuanya sehat dan
                ikut berpindah begitu ada yang memburuk, jadi kartu ini sendiri
                membawa jawabannya sebelum satu baris pun dibaca.
            -->
            <Card class="muncul relative overflow-hidden" style="animation-delay: 120ms">
                <div
                    class="pointer-events-none absolute inset-0"
                    :style="
                        kabut(
                            {
                                hijau: '--color-sentimen-positif',
                                kuning: '--color-sentimen-review',
                                merah: '--color-sentimen-negatif',
                            }[lampuTerburuk.status],
                        )
                    "
                    aria-hidden="true"
                ></div>

                <CardHeader class="relative flex-row items-center gap-2 space-y-0 border-b py-3">
                    <div class="grid size-7 shrink-0 place-items-center rounded-md bg-muted text-muted-foreground">
                        <Radio class="size-4" aria-hidden="true" />
                    </div>
                    <CardTitle class="text-sm font-semibold">Kesehatan sistem</CardTitle>
                </CardHeader>

                <CardContent class="relative space-y-0 pt-4">
                    <IndikatorKesehatan label="Crawler" :ikon="Radio" v-bind="kesehatan.crawler" />
                    <IndikatorKesehatan label="Penarikan media" :ikon="Download" v-bind="kesehatan.sumber" />
                    <IndikatorKesehatan label="Gemini" :ikon="Sparkles" v-bind="kesehatan.gemini" terakhir />

                    <!--
                        Artikel gagal proses dicetak di sini, bukan hanya di
                        kartu ekstraksi. Kartu itu menghilang begitu antreannya
                        habis, dan kegagalan yang tersisa ikut hilang bersamanya
                        justru pada saat tidak ada pekerjaan lain yang menutupi.

                        Tombolnya menuju Log crawl, bukan halaman Berita. Kalimat
                        lama di sini menyuruh menjalankan ulang dari halaman
                        Berita, dan itu tidak bisa dilakukan: status `gagal`
                        tidak termasuk dalam satu pun dari tiga tahap yang
                        dikenal ArtikelController, jadi artikel gagal tidak
                        pernah muncul di daftar mana pun di halaman itu. Log
                        crawl adalah satu-satunya layar yang benar-benar bisa
                        menjalankan ulang penarikan.

                        Ronanya merah karena tujuannya merah, sama dengan rona
                        angka yang diantarkannya tepat di atasnya.
                    -->
                    <div v-if="kpi.gagal_proses > 0" class="mt-4 space-y-2.5 border-t pt-3.5">
                        <p class="text-xs leading-relaxed text-muted-foreground">
                            <span class="angka font-semibold text-sentimen-negatif">{{ formatAngka(kpi.gagal_proses) }}</span>
                            artikel gagal diproses dan tidak pernah sampai ke penilaian.
                        </p>

                        <Link href="/admin/log-crawl" :class="TOMBOL_GAGAL">
                            <TriangleAlert class="size-4" aria-hidden="true" />
                            Buka Log crawl
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </Link>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!--
            Hanya dirender saat ada pekerjaan tertunda, baik ekstraksi maupun
            penilaian. Kartu bertuliskan "tidak ada antrean" menghabiskan ruang
            untuk mengabarkan ketiadaan.
        -->
        <Card v-if="ekstraksi.mentah > 0 || ekstraksi.antre_ai > 0" class="muncul relative overflow-hidden" style="animation-delay: 180ms">
            <div class="pointer-events-none absolute inset-0" :style="kabut('--color-brand')" aria-hidden="true"></div>

            <CardHeader class="relative flex-row flex-wrap items-center justify-between gap-3 space-y-0 border-b py-3">
                <div class="flex min-w-0 items-center gap-2">
                    <div class="grid size-7 shrink-0 place-items-center rounded-md bg-brand-lembut text-brand dark:text-white">
                        <Download class="size-4" aria-hidden="true" />
                    </div>
                    <CardTitle class="truncate text-sm font-semibold">Ekstraksi artikel mentah</CardTitle>
                </div>

                <Link href="/admin/antrean-ai" :class="TOMBOL_GEMINI">
                    <Sparkles class="size-4" aria-hidden="true" />
                    Buka Antrean AI
                    <ArrowRight class="size-4" aria-hidden="true" />
                </Link>
            </CardHeader>

            <CardContent class="relative space-y-3 pt-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p>
                        <span class="angka text-2xl font-semibold tracking-tight">{{ formatAngka(ekstraksi.mentah) }}</span>
                        <span class="text-sm text-muted-foreground"> artikel mentah menunggu diekstraksi</span>
                    </p>
                    <p class="angka text-xs text-muted-foreground">
                        {{ formatAngka(ekstraksi.diekstrak_hari_ini) }} dari {{ formatAngka(ekstraksi.masuk_hari_ini) }} selesai hari ini ({{
                            formatPersen(ekstraksi.persen)
                        }})
                    </p>
                </div>

                <!-- Bilah kemajuan membawa sapuan cahaya yang sama dengan garis
                     di kop, dan hanya selama masih ada artikel mentah. Yang
                     bergerak berarti masih dikerjakan. -->
                <div class="relative h-2 overflow-hidden rounded-full bg-muted">
                    <div
                        class="tumbuh h-full rounded-full bg-brand transition-[width] duration-500 ease-out dark:bg-brand-terang"
                        :class="ekstraksi.mentah > 0 ? 'aliran-terang' : ''"
                        :style="{ width: `${ekstraksi.persen}%` }"
                    />
                </div>

                <dl class="grid gap-2 sm:grid-cols-3">
                    <div class="angkat rounded-md border bg-card p-2.5">
                        <dt class="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Zap class="size-3.5 shrink-0 text-brand dark:text-brand-terang" aria-hidden="true" />
                            Laju ekstraksi
                        </dt>
                        <dd class="angka mt-0.5 text-base font-semibold">
                            {{ formatLaju(ekstraksi.laju_per_menit) }}
                            <span class="text-xs font-normal text-muted-foreground">artikel/menit</span>
                        </dd>
                    </div>

                    <div class="angkat rounded-md border bg-card p-2.5">
                        <dt class="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Timer class="size-3.5 shrink-0 text-brand dark:text-brand-terang" aria-hidden="true" />
                            Perkiraan selesai
                        </dt>
                        <dd class="mt-0.5 text-base font-semibold">
                            <template v-if="ekstraksi.estimasi_selesai_at">{{ sejak(ekstraksi.estimasi_selesai_at) }}</template>
                            <template v-else-if="ekstraksi.mentah === 0">Sudah selesai</template>
                            <template v-else>Belum bisa dihitung</template>
                        </dd>
                    </div>

                    <div class="angkat rounded-md border bg-card p-2.5">
                        <dt class="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Sparkles class="size-3.5 shrink-0 text-aksen-ungu" aria-hidden="true" />
                            Belum diklasifikasi
                        </dt>
                        <dd class="angka mt-0.5 text-base font-semibold">
                            {{ formatAngka(ekstraksi.belum_klasifikasi) }}
                            <span class="text-xs font-normal text-aksen-ungu"> ({{ formatAngka(ekstraksi.antre_ai) }} antre AI) </span>
                        </dd>
                    </div>
                </dl>

                <p class="text-xs leading-relaxed text-muted-foreground">
                    Angka menyegarkan sendiri tiap lima detik selama masih ada artikel mentah. Perkiraan dihitung dari laju sepuluh menit terakhir,
                    jadi ia bergerak mengikuti kecepatan server media yang sedang ditarik. Artikel yang selesai diekstraksi langsung mengantre
                    penilaian relevansi, dan baru muncul di daftar berita setelah dinyatakan relevan.
                </p>
            </CardContent>
        </Card>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="muncul relative overflow-hidden lg:col-span-2" style="animation-delay: 240ms">
                <div class="pointer-events-none absolute inset-0" :style="kabut('--color-brand')" aria-hidden="true"></div>

                <CardHeader class="relative flex-row flex-wrap items-center justify-between gap-3 space-y-0 border-b py-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <div class="grid size-7 shrink-0 place-items-center rounded-md bg-brand-lembut text-brand dark:text-white">
                            <FileText class="size-4" aria-hidden="true" />
                        </div>
                        <CardTitle class="truncate text-sm font-semibold">Berita relevan terbaru</CardTitle>
                    </div>

                    <Link href="/admin/artikel?tahap=selesai&relevansi=relevan" :class="TOMBOL_UTAMA">
                        Lihat semua berita
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </CardHeader>

                <CardContent class="relative pt-2">
                    <!--
                        Tiap baris menuju halaman detail hasil ekstraksi, bukan
                        ke situs medianya. Yang dicari admin saat menekan sebuah
                        judul di sini adalah teks yang berhasil diambil sistem
                        beserta keputusan relevansi dan sentimennya, dan tautan
                        ke sumber aslinya tetap tersedia di halaman itu.

                        Titik di rel kiri memakai warna nada beritanya, jadi
                        sebaran nada sepanjang daftar terbaca sebagai satu kolom
                        warna tanpa harus membaca satu badge pun.
                    -->
                    <ul v-if="artikelTerbaru.length" class="divide-y">
                        <li v-for="artikel in artikelTerbaru" :key="artikel.id">
                            <Link
                                :href="`/admin/artikel/${artikel.id}`"
                                class="tekan group flex items-center gap-3 rounded-md px-2 py-2.5 transition-colors duration-150 hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                            >
                                <span class="size-1.5 shrink-0 rounded-full ring-4" :class="titikNada(artikel)" aria-hidden="true"></span>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[13px] font-medium">{{ artikel.judul }}</p>
                                    <p class="truncate text-xs text-muted-foreground">
                                        {{ artikel.media ?? 'Media belum ditautkan' }}, {{ sejak(artikel.diambil_at) }}
                                    </p>
                                </div>

                                <BadgeSentimen class="hidden shrink-0 sm:inline-flex" :label="artikel.label" :perlu-review="artikel.perlu_review" />

                                <ArrowUpRight
                                    class="size-4 shrink-0 text-muted-foreground transition-transform duration-150 group-hover:translate-x-px group-hover:-translate-y-px group-hover:text-brand dark:group-hover:text-brand-terang"
                                    aria-hidden="true"
                                />
                            </Link>
                        </li>
                    </ul>

                    <p v-else class="px-2 py-3 text-xs leading-relaxed text-muted-foreground">
                        Belum ada berita relevan. Daftar ini terisi setelah crawler menemukan berita dan penilaian menyatakannya relevan terhadap
                        {{ pantauan }}. Kalau crawler sudah berjalan tapi daftar tetap kosong, periksa Antrean Klasifikasi.
                    </p>
                </CardContent>
            </Card>

            <!--
                Kartu media membawa dua hal sekaligus: berapa media yang aktif,
                dan mana yang sedang gagal ditarik. Sebelumnya jumlah media aktif
                berdiri di kartu KPI di puncak halaman, jauh dari daftar
                kegagalannya, sehingga angka enam puluh media aktif tidak pernah
                berdampingan dengan kabar bahwa lima di antaranya sedang mati.
            -->
            <Card class="muncul relative overflow-hidden" style="animation-delay: 300ms">
                <div
                    class="pointer-events-none absolute inset-0"
                    :style="kabut(mediaBermasalah.length ? '--color-sentimen-negatif' : '--color-sentimen-positif')"
                    aria-hidden="true"
                ></div>

                <CardHeader class="relative flex-row items-center justify-between gap-3 space-y-0 border-b py-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <div
                            class="grid size-7 shrink-0 place-items-center rounded-md"
                            :class="
                                mediaBermasalah.length
                                    ? 'bg-sentimen-negatif-lembut text-sentimen-negatif'
                                    : 'bg-sentimen-positif-lembut text-sentimen-positif'
                            "
                        >
                            <Radio class="size-4" aria-hidden="true" />
                        </div>
                        <CardTitle class="truncate text-sm font-semibold">Kondisi media</CardTitle>
                    </div>

                    <p class="shrink-0 text-xs text-muted-foreground">
                        <span class="angka font-semibold text-foreground">{{ formatAngka(kpi.media_aktif) }}</span> aktif
                    </p>
                </CardHeader>

                <CardContent class="relative space-y-3 pt-4">
                    <ul v-if="mediaBermasalah.length" class="space-y-3">
                        <li v-for="media in mediaBermasalah" :key="media.id" class="space-y-1.5">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate text-[13px] font-medium">{{ media.nama }}</p>
                                    <p class="truncate text-xs text-muted-foreground" :title="media.pesan_error_terakhir ?? undefined">
                                        {{ media.pesan_error_terakhir ?? 'Tanpa pesan error' }}
                                    </p>
                                    <p v-if="media.jumlah_sumber > 1" class="angka text-xs text-muted-foreground">
                                        {{ formatAngka(media.jumlah_sumber) }} sumber bermasalah
                                    </p>
                                </div>

                                <Badge :variant="media.gagal_berturut >= 5 ? 'destructive' : 'outline'" class="angka shrink-0">
                                    {{ media.gagal_berturut }}x gagal
                                </Badge>
                            </div>

                            <!-- Bilah kegagalan diukur terhadap media terparah.
                                 Panjangnya membuat urutan keparahan terbaca
                                 sekali lihat, tanpa membandingkan angka satu per
                                 satu di kolom kanan. -->
                            <div class="h-1 w-full overflow-hidden rounded-full bg-muted">
                                <div
                                    class="tumbuh h-full rounded-full"
                                    :class="media.gagal_berturut >= 5 ? 'bg-sentimen-negatif' : 'bg-sentimen-review'"
                                    :style="{ width: `${(media.gagal_berturut / gagalTerparah) * 100}%` }"
                                />
                            </div>
                        </li>
                    </ul>

                    <p v-else class="inline-flex items-center gap-1.5 text-xs text-sentimen-positif">
                        <CircleCheck class="size-3.5 shrink-0" aria-hidden="true" />
                        Semua media tertarik tanpa kegagalan.
                    </p>

                    <Link href="/admin/media" :class="mediaBermasalah.length ? TOMBOL_GAGAL : TOMBOL_NETRAL">
                        Kelola media
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </CardContent>
            </Card>
        </div>
    </LayoutAdmin>
</template>

<style scoped>
/*
 * `.aliran` yang dipakai garis di kop tinggal di resources/css/app.css, dipakai
 * bersama Antrean AI dan kedua halaman portal media.
 *
 * Yang tersisa di sini hanya variannya untuk bilah kemajuan navy. Dipisahkan
 * karena bilah itu berdiri di bidang terang, bukan di atas navy kop, dan butuh
 * isian putih yang lebih tipis supaya sapuannya tidak terbaca sebagai kilatan.
 */
.aliran-terang {
    position: relative;
    overflow: hidden;
}

.aliran-terang::after {
    content: '';
    position: absolute;
    inset-block: 0;
    left: 0;
    width: 40%;
    background: linear-gradient(90deg, transparent, rgb(255 255 255 / 0.35), transparent);
    animation: aliran-jalan 5s cubic-bezier(0.45, 0, 0.55, 1) infinite;
}

/* Keyframes-nya sengaja tidak ditulis ulang di sini. `aliran-jalan` sudah
   global di app.css, dan penulisan ulang di dalam blok scoped akan membuat Vue
   mengganti namanya dengan versi berhash, sehingga ada dua putaran identik yang
   harus dijaga tetap sama. */

/*
 * Kisi titik sempat dipasang di sudut kartu perjalanan berita, lalu dicabut.
 *
 * Alasannya sudah tertulis di resources/css/app.css pada catatan latar panel
 * eksekutif: pola berulang menjadi unsur kedua yang bersaing dengan tepi kartu,
 * dan pada proyektor ruang rapat pola halus justru pecah menjadi bintik.
 * Keputusan itu berlaku untuk seluruh sistem, bukan hanya untuk latar eksekutif,
 * jadi kartu ini tidak berhak membuat perkecualian untuk dirinya sendiri.
 *
 * Kedalaman kartunya dibawa kabut warna yang tersisa, yaitu satu gradien radial
 * tunggal yang tidak berulang dan tidak punya tepi untuk dibaca mata.
 */

@media (prefers-reduced-motion: reduce) {
    .aliran-terang::after {
        animation: none;
    }
}
</style>
