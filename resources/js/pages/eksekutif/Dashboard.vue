<script setup lang="ts">
import ChartTrenSentimen from '@/components/chart/ChartTrenSentimen.vue';
import Sparkline from '@/components/chart/Sparkline.vue';
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import KartuArtikel from '@/components/domain/KartuArtikel.vue';
import PemilihRentangTanggal from '@/components/domain/PemilihRentangTanggal.vue';
import SentimenBelumTersedia from '@/components/domain/SentimenBelumTersedia.vue';
import KotaKendari from '@/components/ilustrasi/KotaKendari.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useFormatAngka } from '@/composables/useFormatAngka';
import { useGerbangSentimen } from '@/composables/useGerbangSentimen';
import { usePeriodeEksekutif } from '@/composables/usePeriodeEksekutif';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import type { DeretTren, LabelSentimen } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { differenceInCalendarDays, format, formatDistanceToNow, isSameDay } from 'date-fns';
import { id } from 'date-fns/locale';
import {
    ArrowRight,
    Check,
    CircleCheck,
    Clock,
    Flame,
    Globe2,
    Info,
    LineChart,
    Newspaper,
    Radio,
    Scale,
    Sparkles,
    ThumbsUp,
    TriangleAlert,
} from 'lucide-vue-next';
import { computed } from 'vue';

type Berita = {
    id: number;
    judul: string;
    url: string;
    media: string | null;
    diambil_at: string;
    label: LabelSentimen | null;
    perlu_review: boolean;
    /** Alasan model atas label yang diberikannya. Kosong pada baris analisis lama. */
    ringkasan_ai: string | null;
};

type Topik = {
    judul: string;
    ringkasan: string;
    jumlah_artikel: number;
    jumlah_media: number;
    negatif: number;
    netral: number;
    positif: number;
    hari_beruntun: number;
    sentimen_dominan: LabelSentimen;
    prioritas: 'rendah' | 'sedang' | 'tinggi';
    artikel_ids: number[];
};

const props = defineProps<{
    periode: { dari: string; sampai: string };
    kpi: {
        berlabel: number;
        berlabel_selisih: number;
        artikel: number;
        cakupan_persen: number;
        negatif: number;
        negatif_selisih: number;
        negatif_persen: number;
        positif: number;
        positif_selisih: number;
        netral: number;
        perlu_review: number;
        media_aktif: number;
    };
    deret: DeretTren;
    /** Null saat rentangnya bukan salah satu pintasan, atau belum pernah dibuat. */
    narasi: {
        nada: 'positif' | 'netral' | 'negatif' | 'campuran' | null;
        judul: string | null;
        ringkasan: string | null;
        penjelasan_tren: string | null;
        poin: Array<{ teks: string; artikel_ids: number[] }>;
        perhatian: Array<{ topik: string; alasan: string }>;
        nada_ringkas: { positif?: string; netral?: string; negatif?: string };
        topik: Topik[];
        dari: string;
        sampai: string;
        dibuat_at: string;
    } | null;
    peringkatMedia: Array<{
        id: number;
        nama: string;
        jumlah_artikel: number;
        jumlah_negatif: number;
    }>;
    beritaPerhatian: Berita[];
    beritaPositif: Berita[];
    beritaTerbaru: Berita[];
    peringatan: { jumlah: number; terbaru: string; dipicu_at: string } | null;
}>();

const { formatAngka } = useFormatAngka();
const { pindah, kueri } = usePeriodeEksekutif(props.periode, '/eksekutif');

/**
 * Sentimen tidak tersedia selama belum ada kunci Gemini yang menyala.
 *
 * Sejak angka utama panel ini adalah berita yang sudah berlabel, seluruh isinya
 * bergantung pada model. Halaman menjadi hampir kosong dalam keadaan itu, dan
 * itu benar. Angka nol dibaca sebagai "tidak ada berita negatif", dan itu
 * pernyataan yang tidak dimiliki siapa pun.
 */
const { sentimenTersedia, alasanSentimen } = useGerbangSentimen();

const rentangTerbaca = computed(
    () =>
        `${format(new Date(props.periode.dari), 'd MMMM', { locale: id })} sampai ` +
        `${format(new Date(props.periode.sampai), 'd MMMM yyyy', { locale: id })}`,
);

const jumlahHari = computed(() => differenceInCalendarDays(new Date(props.periode.sampai), new Date(props.periode.dari)) + 1);

/** Dibulatkan ke bilangan bulat: "58 dari 100 berita" lebih mudah dibaca daripada "58,3%". */
function per100(bagian: number): number {
    return totalKomposisi.value === 0 ? 0 : Math.round((bagian / totalKomposisi.value) * 100);
}

/**
 * Tiga potong nada, satu barisan. Diberi nama sehari-hari, bukan istilah
 * analisis, karena halaman ini dibaca orang yang belum tentu pernah mendengar
 * kata sentimen.
 */
const komposisi = computed(() =>
    [
        {
            kunci: 'positif',
            nama: 'Positif',
            arti: 'memberitakan hal baik',
            jumlah: props.kpi.positif,
            warna: 'bg-sentimen-positif',
            tautan: 'positif',
        },
        {
            kunci: 'netral',
            nama: 'Netral',
            arti: 'menyampaikan informasi',
            jumlah: props.kpi.netral,
            warna: 'bg-sentimen-netral',
            tautan: 'netral',
        },
        {
            kunci: 'negatif',
            nama: 'Negatif',
            arti: 'menyoroti masalah atau kritik',
            jumlah: props.kpi.negatif,
            warna: 'bg-sentimen-negatif',
            tautan: 'negatif',
        },
    ].filter((p) => p.jumlah > 0),
);

/**
 * Penyebut persentase hanya tiga label itu, bukan seluruh berita berlabel.
 *
 * "Perlu dicek ulang" dikeluarkan dari barisan atas supaya kesimpulannya
 * ringkas. Angkanya tidak boleh ikut hilang: kalau ia tetap jadi penyebut
 * sementara potongnya tidak digambar, ketiga persentase tidak akan berjumlah
 * seratus dan selisihnya tidak ada penjelasannya di layar. Jumlahnya tetap
 * disebut satu baris di bawah batang.
 */
const totalKomposisi = computed(() => komposisi.value.reduce((jumlah, p) => jumlah + p.jumlah, 0));

/**
 * Kesimpulan satu kata, dihitung dari angka dan bukan dari AI.
 *
 * Sengaja tidak memakai `narasi.nada`. Kalau chip di paling atas berasal dari
 * model sedangkan batang di bawahnya dari basis data, suatu hari keduanya akan
 * berbeda di layar yang sama, dan pembaca tidak punya cara tahu mana yang benar.
 */
const kondisi = computed(() => {
    if (props.kpi.berlabel === 0) {
        return { teks: 'Belum ada data', ikon: Scale, kelas: 'bg-muted text-muted-foreground', sapuan: 'bg-muted' };
    }

    if (props.kpi.negatif_persen >= 25) {
        return {
            teks: 'Perlu perhatian',
            ikon: TriangleAlert,
            kelas: 'bg-sentimen-negatif-lembut text-sentimen-negatif',
            sapuan: 'bg-sentimen-negatif-lembut',
        };
    }

    if (per100(props.kpi.positif) >= 50) {
        return {
            teks: 'Cenderung positif',
            ikon: CircleCheck,
            kelas: 'bg-sentimen-positif-lembut text-sentimen-positif',
            sapuan: 'bg-sentimen-positif-lembut',
        };
    }

    return { teks: 'Beragam', ikon: Scale, kelas: 'bg-sentimen-netral-lembut text-sentimen-netral', sapuan: 'bg-sentimen-netral-lembut' };
});

/**
 * Kalimat pembuka yang selalu ada, disusun dari angka.
 *
 * Ringkasan Gemini bisa tertunda atau gagal, dan halaman tidak boleh membuka
 * diri tanpa satu kalimat pun yang menjelaskan keadaan.
 */
const kalimatAngka = computed(() => {
    if (props.kpi.berlabel === 0) {
        return `Belum ada berita tentang Pemerintah Kota Kendari yang selesai diperiksa pada ${rentangTerbaca.value}.`;
    }

    return (
        `Dari ${formatAngka(props.kpi.berlabel)} berita tentang Pemerintah Kota Kendari pada rentang ini, ` +
        `${formatAngka(props.kpi.positif)} memberitakan hal baik, ${formatAngka(props.kpi.netral)} menyampaikan informasi, ` +
        `dan ${formatAngka(props.kpi.negatif)} menyoroti masalah.`
    );
});

const rataPerHari = computed(() => (jumlahHari.value === 0 ? 0 : Math.round((props.kpi.berlabel / jumlahHari.value) * 10) / 10));

/** Jumlah berita berlabel per titik, bahan garis mungil di kartu angka. */
const deretVolume = computed(() => props.deret.baris.map((b) => b.jumlah_positif + b.jumlah_netral + b.jumlah_negatif + b.jumlah_perlu_review));

const namaSatuan = computed(() => ({ harian: 'per hari', mingguan: 'per pekan', bulanan: 'per bulan' })[props.deret.satuan]);

const puncakMedia = computed(() => Math.max(1, ...props.peringkatMedia.map((m) => m.jumlah_artikel)));

/**
 * Ringkasan dibuat penjadwal, bukan saat halaman dibuka, jadi rentang yang
 * ditulisnya bisa tertinggal sehari dari rentang yang sedang dilihat. Itu
 * keadaan normal setelah Gemini gagal semalam, dan halaman harus mengatakannya
 * alih-alih menyajikan kalimat lama seolah baru.
 */
const narasiBasi = computed(() => !!props.narasi && !isSameDay(new Date(props.narasi.sampai), new Date(props.periode.sampai)));

const narasiUmur = computed(() => (props.narasi ? formatDistanceToNow(new Date(props.narasi.dibuat_at), { addSuffix: true, locale: id }) : ''));

/**
 * Berita yang jadi bahan ulasan, dikumpulkan dari seluruh topiknya.
 *
 * Satu artikel hanya boleh masuk satu topik, penjagaannya ada di sisi server,
 * tetapi `Set` tetap dipakai di sini supaya tautan tidak pernah membawa id
 * kembar kalau aturan itu suatu saat berubah.
 */
const artikelUlasan = computed(() => [...new Set((props.narasi?.topik ?? []).flatMap((t) => t.artikel_ids))]);

/** "2026-07-12" jadi "12 Juli 2026". Tanggal ISO tidak dibaca siapa pun di luar layar admin. */
function tanggalTerbaca(iso: string): string {
    return format(new Date(iso), 'd MMMM yyyy', { locale: id });
}

/**
 * Warna di halaman ini selalu berarti nada pemberitaan, tidak pernah hiasan.
 *
 * Itu sebabnya tiga kartu ini berlatar pastel sedangkan tiga kartu angka di
 * atasnya tidak. Angka total, jumlah media, dan rata-rata per hari bukan nada
 * apa pun, dan memberinya warna hijau atau kuning membuat pembaca menyimpulkan
 * sesuatu yang tidak pernah dinyatakan datanya.
 */
const NADA_DIJELASKAN = [
    {
        kunci: 'positif',
        judul: 'Sisi baik yang diberitakan',
        ikon: ThumbsUp,
        kartu: 'bg-sentimen-positif-lembut/60 border-sentimen-positif/25',
        tile: 'bg-sentimen-positif',
        aksen: 'text-sentimen-positif',
    },
    {
        kunci: 'netral',
        judul: 'Informasi yang disampaikan',
        ikon: Info,
        kartu: 'bg-sentimen-netral-lembut/70 border-sentimen-netral/25',
        tile: 'bg-sentimen-netral',
        aksen: 'text-sentimen-netral',
    },
    {
        kunci: 'negatif',
        judul: 'Masalah yang disorot',
        ikon: TriangleAlert,
        kartu: 'bg-sentimen-negatif-lembut/60 border-sentimen-negatif/25',
        tile: 'bg-sentimen-negatif',
        aksen: 'text-sentimen-negatif',
    },
] as const;

/**
 * Rupa kartu topik mengikuti nada dominannya, ketiga nada diberi warna.
 *
 * Empat lapis yang saling menguatkan, karena warna saja tidak cukup: sekitar
 * delapan persen pria kesulitan membedakan merah dan hijau, dan halaman ini
 * diproyeksikan di layar rapat yang warnanya tidak akurat. Pita di tepi kiri
 * menyatakan nada lewat posisi dan tebal, ikon lambang di sudut menyatakannya
 * lewat bentuk, dan lambang sentimen di kanan atas tetap membawa teksnya.
 *
 * Kepekatan latar sengaja tidak sama. Kartu negatif paling pekat, netral paling
 * samar. Halaman ini dibuka untuk menemukan yang menonjol, dan tiga warna
 * dengan bobot yang persis sama membuat tidak ada satu pun yang menonjol.
 */
const RUPA_TOPIK = {
    negatif: {
        kartu: 'bg-sentimen-negatif-lembut/55 border-sentimen-negatif/25 hover:border-sentimen-negatif/45',
        pita: 'bg-sentimen-negatif',
        aksen: 'text-sentimen-negatif',
        ikon: TriangleAlert,
    },
    netral: {
        kartu: 'bg-sentimen-netral-lembut/45 border-sentimen-netral/25 hover:border-sentimen-netral/45',
        pita: 'bg-sentimen-netral',
        aksen: 'text-sentimen-netral',
        ikon: Info,
    },
    positif: {
        kartu: 'bg-sentimen-positif-lembut/45 border-sentimen-positif/25 hover:border-sentimen-positif/45',
        pita: 'bg-sentimen-positif',
        aksen: 'text-sentimen-positif',
        ikon: ThumbsUp,
    },
} as const;

function rupaTopik(nada: LabelSentimen) {
    return RUPA_TOPIK[nada] ?? RUPA_TOPIK.netral;
}
</script>

<template>
    <Head title="Kondisi pemberitaan Kota Kendari" />

    <LayoutEksekutif>
        <header class="muncul flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-primary dark:text-aksen-biru">Kondisi pemberitaan Kota Kendari</h1>
                <p class="text-sm text-muted-foreground">Apa yang ditulis media tentang Pemerintah Kota, {{ rentangTerbaca }}</p>
            </div>

            <PemilihRentangTanggal :dari="periode.dari" :sampai="periode.sampai" inline @ubah="(dari, sampai) => pindah({ dari, sampai })" />
        </header>

        <SentimenBelumTersedia v-if="!sentimenTersedia" :alasan="alasanSentimen" />

        <template v-else>
            <!--
                Satu blok yang menjawab "bagaimana keadaannya" sebelum pembaca
                menemukan satu pun grafik. Kesimpulan, kalimat penjelas, lalu
                batang komposisi yang bisa diklik. Angka mentah menyusul di
                bawahnya, bukan sebaliknya.
            -->
            <Card class="muncul relative overflow-hidden" style="animation-delay: 60ms">
                <!--
                    Sapuan warna mengikuti nada kesimpulan, siluet kota di
                    atasnya. Sapuannya yang membawa arti, siluetnya yang membuat
                    blok ini terbaca sebagai halaman Kota Kendari dan bukan
                    laporan mana pun.

                    Disembunyikan di bawah lg. Di layar sempit ruangnya habis
                    untuk kalimat, dan siluet yang terhimpit hanya menjadi noda.
                -->
                <div
                    :class="kondisi.sapuan"
                    class="pointer-events-none absolute -right-24 -top-32 h-72 w-72 rounded-full opacity-60 blur-3xl"
                    aria-hidden="true"
                ></div>
                <KotaKendari class="pointer-events-none absolute right-2 top-0 hidden w-[32%] max-w-[380px] lg:block" />

                <CardContent class="relative space-y-5 p-5 sm:p-6">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <span :class="kondisi.kelas" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-medium">
                            <component :is="kondisi.ikon" class="h-4 w-4" aria-hidden="true" />
                            {{ kondisi.teks }}
                        </span>
                        <span class="text-xs text-muted-foreground">Dihitung langsung dari jumlah berita pada rentang ini</span>
                    </div>

                    <div class="space-y-2">
                        <h2
                            v-if="narasi?.judul"
                            class="max-w-[46rem] text-xl font-semibold leading-snug text-primary dark:text-aksen-biru sm:text-2xl"
                        >
                            {{ narasi.judul }}
                        </h2>
                        <p class="max-w-[46rem] text-sm leading-relaxed text-muted-foreground">{{ kalimatAngka }}</p>
                    </div>

                    <!--
                        Batang komposisi menggantikan donat. Panjang yang
                        dibandingkan berdampingan jauh lebih mudah dibaca
                        daripada sudut lingkaran, dan tiap potongnya membuka
                        daftar beritanya sendiri.
                    -->
                    <div v-if="totalKomposisi > 0" class="space-y-3">
                        <div class="flex h-3 gap-0.5 overflow-hidden rounded-full">
                            <div
                                v-for="(potong, urutan) in komposisi"
                                :key="potong.kunci"
                                :class="potong.warna"
                                class="tumbuh h-full"
                                :style="{ width: `${(potong.jumlah / totalKomposisi) * 100}%`, animationDelay: `${240 + urutan * 110}ms` }"
                            ></div>
                        </div>

                        <ul class="grid gap-1 rounded-xl border bg-muted/40 p-2 sm:grid-cols-3">
                            <li
                                v-for="(potong, urutan) in komposisi"
                                :key="potong.kunci"
                                class="muncul"
                                :style="{ animationDelay: `${300 + urutan * 90}ms` }"
                            >
                                <Link
                                    :href="`/eksekutif/berita?${kueri({ sentimen: potong.tautan })}`"
                                    class="tekan flex h-full gap-3 rounded-lg px-3 py-2.5 hover:bg-background"
                                >
                                    <span :class="potong.warna" class="mt-2 h-3 w-3 shrink-0 rounded-full" aria-hidden="true"></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="angka block text-3xl font-semibold leading-none">
                                            {{ formatAngka(potong.jumlah) }}
                                            <span class="text-sm font-normal text-muted-foreground">berita</span>
                                        </span>
                                        <span class="mt-1.5 block text-sm font-semibold leading-tight">
                                            {{ potong.nama }}
                                            <span class="angka font-normal text-muted-foreground">{{ per100(potong.jumlah) }} dari 100</span>
                                        </span>
                                        <span class="block text-xs leading-snug text-muted-foreground">{{ potong.arti }}</span>
                                    </span>
                                </Link>
                            </li>
                        </ul>

                        <!--
                            Sisanya tetap disebut. Berita yang labelnya belum
                            cukup yakin dikeluarkan dari barisan di atas supaya
                            kesimpulannya ringkas, tapi menghilangkannya diam
                            diam berarti tiga persentase di atas menyembunyikan
                            selisih yang tidak pernah dijelaskan.
                        -->
                        <p v-if="kpi.perlu_review > 0" class="angka text-xs text-muted-foreground">
                            {{ formatAngka(kpi.perlu_review) }} berita lain masih dicek ulang karena label modelnya belum cukup yakin, dan belum
                            dihitung di tiga angka di atas.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <!--
                Penjelasan AI dikeluarkan jadi kartu sendiri, bukan lagi bagian
                bawah kartu ilustrasi.

                Satu kartu yang memuat kesimpulan, angka, batang, dan tiga blok
                tulisan model menjadi terlalu panjang untuk dibaca sebagai satu
                unit, dan garis pemisah di dalamnya sudah menandakan bahwa
                isinya memang dua hal berbeda. Yang di atas dihitung basis data,
                yang di sini ditulis model, dan pemisahan kartu menyatakan itu
                lebih jelas daripada garis.
            -->
            <Card class="muncul overflow-hidden" style="animation-delay: 100ms">
                <CardHeader class="flex-row items-center justify-between gap-3 bg-aksen-ungu/[0.07] py-3.5">
                    <CardTitle class="flex items-center gap-2.5 text-base">
                        <span class="grid h-8 w-8 place-items-center rounded-xl bg-aksen-ungu text-white shadow-sm">
                            <Sparkles class="h-[18px] w-[18px]" aria-hidden="true" />
                        </span>
                        Ulasan pemberitaan periode ini
                    </CardTitle>
                    <span v-if="narasi?.ringkasan" class="shrink-0 text-xs text-muted-foreground">Diperbarui {{ narasiUmur }}</span>
                </CardHeader>

                <CardContent class="space-y-4 pt-5">
                    <template v-if="narasi?.ringkasan">
                        <p class="max-w-[46rem] whitespace-pre-line text-sm leading-relaxed">{{ narasi.ringkasan }}</p>

                        <!--
                            Centangnya bernada merek, bukan hijau. Poin di sini
                            hanya hal yang tercatat pada rentang ini, dan
                            sebagiannya bisa kabar buruk. Centang hijau akan
                            membacanya sebagai kabar baik semua.
                        -->
                        <!--
                            Tiap poin membuka berita yang mendasarinya. Id-nya
                            dikembalikan model bersama kalimatnya lalu divalidasi
                            server, jadi tautan ini tidak pernah menebak.

                            Poin tanpa id tetap dirender sebagai teks biasa. Itu
                            keadaan narasi lama yang dibuat sebelum skemanya
                            berubah, dan poin tanpa tautan lebih baik daripada
                            tautan yang membuka daftar kosong.
                        -->
                        <ul v-if="narasi.poin.length" class="grid gap-1 sm:grid-cols-2">
                            <li v-for="poin in narasi.poin" :key="poin.teks">
                                <component
                                    :is="poin.artikel_ids.length ? Link : 'div'"
                                    :href="
                                        poin.artikel_ids.length ? `/eksekutif/berita?${kueri({ artikel: poin.artikel_ids.join(',') })}` : undefined
                                    "
                                    class="tekan group flex h-full gap-2.5 rounded-lg px-2 py-1.5 text-sm text-muted-foreground"
                                    :class="poin.artikel_ids.length ? 'hover:bg-muted' : ''"
                                >
                                    <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-brand/10">
                                        <Check class="h-3 w-3 text-brand dark:text-aksen-biru" aria-hidden="true" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        {{ poin.teks }}
                                        <span
                                            v-if="poin.artikel_ids.length"
                                            class="angka ml-1 whitespace-nowrap text-xs font-medium text-aksen-biru group-hover:underline"
                                        >
                                            {{ formatAngka(poin.artikel_ids.length) }} berita
                                        </span>
                                    </span>
                                </component>
                            </li>
                        </ul>

                        <!--
                            Berita negatif terbaru, bukan kalimat tindak lanjut
                            tulisan model.

                            Kalimat model tidak membawa id artikel, sehingga
                            pembaca yang ingin memeriksa sendiri harus mencari
                            beritanya di arsip. Daftar ini membuka portal
                            aslinya langsung. Warnanya peringatan, bukan merah:
                            ini pengingat untuk dibaca, bukan pernyataan bahwa
                            keadaannya sudah buruk.
                        -->
                        <div v-if="beritaPerhatian.length" class="rounded-xl border border-sentimen-review/30 bg-sentimen-review-lembut px-4 py-3.5">
                            <p class="flex items-center gap-1.5 text-sm font-semibold text-sentimen-review">
                                <TriangleAlert class="h-4 w-4 shrink-0" aria-hidden="true" />
                                Berita bernada negatif terbaru
                            </p>
                            <!--
                                Satu berita saja, yang paling baru. Daftar
                                panjang di sini mengulang kartu "Berita yang
                                menyoroti masalah" di bawah halaman, dan
                                pengulangan itu membuat keduanya berhenti
                                menarik perhatian.
                            -->
                            <KartuArtikel
                                v-bind="{
                                    judul: beritaPerhatian[0].judul,
                                    url: beritaPerhatian[0].url,
                                    media: beritaPerhatian[0].media,
                                    diambilAt: beritaPerhatian[0].diambil_at,
                                    label: beritaPerhatian[0].label,
                                    perluReview: beritaPerhatian[0].perlu_review,

                                    ringkasanAi: beritaPerhatian[0].ringkasan_ai,
                                }"
                            />
                        </div>

                        <!--
                            Tautan ke berita yang jadi bahan ulasan, bukan ke
                            seluruh arsip rentang ini. Kalimat yang dibaca
                            pimpinan harus bisa ditelusuri sampai ke berita
                            aslinya, dan arsip menyimpan tautan portal tiap
                            beritanya.

                            Satu tautan untuk seluruh kartu, bukan per kalimat.
                            Model tidak mengembalikan id artikel untuk tiap poin
                            maupun tiap butir tindak lanjut, jadi tautan per
                            kalimat hanya bisa dibuat dengan menebak, dan tautan
                            yang menebak lebih buruk daripada tidak ada tautan.
                        -->
                        <div class="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                            <p class="max-w-[46rem] text-xs text-muted-foreground">
                                Ulasan di atas disusun otomatis oleh sistem, berdasarkan angka yang sudah dihitung.
                                <template v-if="narasiBasi">
                                    Ulasan itu menghitung rentang {{ tanggalTerbaca(narasi.dari) }} sampai {{ tanggalTerbaca(narasi.sampai) }},
                                    sedikit berbeda dari rentang yang sedang dibuka. Seluruh angka di halaman ini tetap yang terbaru.
                                </template>
                            </p>

                            <Link
                                v-if="artikelUlasan.length"
                                :href="`/eksekutif/berita?${kueri({ artikel: artikelUlasan.join(',') })}`"
                                class="tekan group inline-flex shrink-0 items-center gap-1.5 rounded-full bg-aksen-ungu/10 px-3 py-1.5 text-xs font-semibold text-aksen-ungu hover:bg-aksen-ungu/20"
                            >
                                <Newspaper class="h-3.5 w-3.5" aria-hidden="true" />
                                <span class="angka">Baca {{ formatAngka(artikelUlasan.length) }} berita aslinya</span>
                                <ArrowRight
                                    class="ease-[cubic-bezier(0.32,0.72,0,1)] h-3 w-3 transition-transform duration-300 group-hover:translate-x-1"
                                    aria-hidden="true"
                                />
                            </Link>
                        </div>
                    </template>

                    <p v-else class="text-xs text-muted-foreground">
                        Ulasan sedang disusun dari berita terbaru, dan tersedia untuk rentang Hari ini, 7 hari, 30 hari, dan 90 hari. Seluruh angka di
                        halaman ini sudah bisa dibaca sekarang.
                    </p>
                </CardContent>
            </Card>

            <!--
                Tiga fakta yang tidak diulang di tempat lain: seberapa banyak,
                seberapa luas, seberapa sering. Komposisi nada tidak diulang di
                sini karena sudah dijawab batang di atas.
            -->
            <div class="muncul grid gap-4 sm:grid-cols-3" style="animation-delay: 140ms">
                <Link :href="`/eksekutif/berita?${kueri()}`" class="tekan group rounded-xl">
                    <Card class="angkat relative h-full overflow-hidden group-hover:border-aksen-biru/40">
                        <!--
                            Ikon besar tembus pandang di sudut, bukan bagan
                            palsu. Bentuknya mengulang ikon di tile supaya kartu
                            ini bisa dikenali sekilas tanpa membaca labelnya.
                        -->
                        <Newspaper
                            class="ease-[cubic-bezier(0.32,0.72,0,1)] pointer-events-none absolute -right-3 -top-3 h-24 w-24 text-aksen-biru/[0.08] transition-transform duration-500 group-hover:scale-110"
                            aria-hidden="true"
                        />

                        <CardContent class="relative flex h-full flex-col p-5">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <span class="grid h-10 w-10 place-items-center rounded-xl bg-aksen-biru/10">
                                        <Newspaper class="h-5 w-5 text-aksen-biru" aria-hidden="true" />
                                    </span>
                                    <p class="mt-3 text-[13px] font-medium text-muted-foreground">Berita tentang Pemkot</p>
                                    <p class="angka mt-0.5 text-4xl font-semibold leading-none">{{ formatAngka(kpi.berlabel) }}</p>
                                </div>

                                <!--
                                    Bentuk deretnya, dari data yang sama dengan
                                    grafik besar di bawah. Angka tunggal tidak
                                    bisa membedakan arus merata dari satu hari
                                    yang meledak, garis ini bisa.
                                -->
                                <Sparkline :nilai="deretVolume" class="mt-1 shrink-0" />
                            </div>

                            <p v-if="kpi.berlabel_selisih !== 0" class="mt-3 text-xs text-muted-foreground">
                                {{ kpi.berlabel_selisih > 0 ? 'Lebih banyak' : 'Lebih sedikit' }}
                                {{ formatAngka(Math.abs(kpi.berlabel_selisih)) }} dibanding rentang sebelumnya
                            </p>
                            <p v-else class="mt-3 text-xs text-muted-foreground">Sama banyak dengan rentang sebelumnya</p>

                            <span class="mt-auto inline-flex items-center gap-1 pt-3 text-xs font-medium text-aksen-biru group-hover:underline">
                                Lihat daftarnya <ArrowRight class="h-3 w-3" aria-hidden="true" />
                            </span>
                        </CardContent>
                    </Card>
                </Link>

                <Link :href="`/eksekutif/media?${kueri()}`" class="tekan group rounded-xl">
                    <Card class="angkat relative h-full overflow-hidden group-hover:border-aksen-toska/40">
                        <Globe2
                            class="ease-[cubic-bezier(0.32,0.72,0,1)] pointer-events-none absolute -right-3 -top-3 h-24 w-24 text-aksen-toska/[0.09] transition-transform duration-500 group-hover:scale-110"
                            aria-hidden="true"
                        />

                        <CardContent class="relative flex h-full flex-col p-5">
                            <span class="grid h-10 w-10 place-items-center rounded-xl bg-aksen-toska/10">
                                <Radio class="h-5 w-5 text-aksen-toska" aria-hidden="true" />
                            </span>
                            <p class="mt-3 text-[13px] font-medium text-muted-foreground">Media yang memberitakan</p>
                            <p class="angka mt-0.5 text-4xl font-semibold leading-none">{{ formatAngka(kpi.media_aktif) }}</p>
                            <p class="mt-3 text-xs text-muted-foreground">Portal berita yang menulis tentang Pemkot pada rentang ini</p>
                            <span class="mt-auto inline-flex items-center gap-1 pt-3 text-xs font-medium text-aksen-toska group-hover:underline">
                                Lihat peringkatnya <ArrowRight class="h-3 w-3" aria-hidden="true" />
                            </span>
                        </CardContent>
                    </Card>
                </Link>

                <Card class="relative h-full overflow-hidden">
                    <LineChart class="pointer-events-none absolute -right-3 -top-3 h-24 w-24 text-aksen-ungu/[0.09]" aria-hidden="true" />

                    <CardContent class="relative flex h-full flex-col p-5">
                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-aksen-ungu/10">
                            <Scale class="h-5 w-5 text-aksen-ungu" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-[13px] font-medium text-muted-foreground">Rata-rata per hari</p>
                        <p class="angka mt-0.5 text-4xl font-semibold leading-none">{{ formatAngka(rataPerHari) }}</p>
                        <p class="mt-3 text-xs text-muted-foreground">
                            Berita per hari selama {{ formatAngka(jumlahHari) }} hari, dari total {{ formatAngka(kpi.artikel) }} berita yang dipantau
                        </p>
                    </CardContent>
                </Card>
            </div>

            <!-- Hanya dirender kalau ada isinya. Kartu kosong bertuliskan "tidak ada peringatan" menghabiskan ruang layar. -->
            <Card v-if="peringatan" class="muncul border-sentimen-negatif/30 bg-sentimen-negatif-lembut" style="animation-delay: 200ms">
                <CardContent class="flex items-start gap-3 p-4">
                    <span class="relative mt-0.5 grid h-5 w-5 shrink-0 place-items-center">
                        <span class="denyut absolute inset-0 rounded-full bg-sentimen-negatif/25" aria-hidden="true"></span>
                        <TriangleAlert class="relative h-5 w-5 text-sentimen-negatif" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-sentimen-negatif">
                            {{ formatAngka(peringatan.jumlah) }} peringatan belum dibaca dalam 24 jam terakhir
                        </p>
                        <p class="truncate text-xs text-muted-foreground">{{ peringatan.terbaru }}</p>
                        <Link :href="`/eksekutif/sentimen?${kueri()}`" class="text-xs underline">Buka rincian nada pemberitaan</Link>
                    </div>
                </CardContent>
            </Card>

            <!--
                Penjelasan Gemini ditempel tepat di sebelah angka yang
                dijelaskannya. Batang di atas menjawab "berapa", tiga kolom ini
                menjawab "kenapa", dan itu pertanyaan berikutnya yang pasti
                muncul di kepala pembaca.
            -->
            <section v-if="narasi?.nada_ringkas.positif" class="muncul space-y-3" style="animation-delay: 260ms">
                <h2 class="flex items-center gap-2 text-base font-semibold text-primary dark:text-aksen-biru">
                    <span class="grid h-7 w-7 place-items-center rounded-lg bg-primary/10 dark:bg-aksen-biru/15">
                        <Info class="h-4 w-4" aria-hidden="true" />
                    </span>
                    Apa isi beritanya
                </h2>

                <!--
                    Warnanya tetap warna sentimen, bukan biru dan jingga seperti
                    gambar rujukan. Ketiga kartu ini menjelaskan tiga potong
                    batang yang persis di atasnya, dan hubungan itu hanya
                    terbaca kalau warnanya sama. Yang diambil dari rujukan
                    bentuknya: tile ikon berwarna penuh, tepi tipis, latar kartu
                    yang jauh lebih terang daripada tile-nya.
                -->
                <div class="grid gap-4 md:grid-cols-3">
                    <div
                        v-for="(bagian, urutan) in NADA_DIJELASKAN"
                        :key="bagian.kunci"
                        :class="bagian.kartu"
                        class="angkat muncul space-y-3 rounded-2xl border p-5"
                        :style="{ animationDelay: `${320 + urutan * 90}ms` }"
                    >
                        <p :class="bagian.aksen" class="flex items-center gap-3 text-sm font-semibold leading-snug">
                            <span :class="bagian.tile" class="grid h-9 w-9 shrink-0 place-items-center rounded-xl text-white shadow-sm">
                                <component :is="bagian.ikon" class="h-[18px] w-[18px]" aria-hidden="true" />
                            </span>
                            {{ bagian.judul }}
                        </p>
                        <p class="text-sm leading-relaxed text-foreground/75">{{ narasi.nada_ringkas[bagian.kunci] }}</p>
                    </div>
                </div>
            </section>

            <!-- Grafik selalu ditemani satu kalimat. Grafik garis bertumpuk adalah bagian halaman yang paling sering salah dibaca. -->
            <Card class="muncul" style="animation-delay: 320ms">
                <CardHeader class="pb-2">
                    <CardTitle class="sr-only">Perubahan dari waktu ke waktu</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3 p-4 pt-0">
                    <!--
                        Judulnya diserahkan ke BaseChart, bukan dicetak dua kali.
                        BaseChart menaruh judul sebaris dengan tombol tabel dan
                        unduh, jadi menambah CardTitle di atasnya menghasilkan
                        dua judul untuk satu grafik yang sama.
                    -->
                    <ChartTrenSentimen judul="Perubahan dari waktu ke waktu" :data="deret.baris as never" :satuan="deret.satuan" :tinggi="280" />
                    <p class="text-xs text-muted-foreground">
                        Tiap titik menghitung berita {{ namaSatuan }}. Warnanya ditumpuk, jadi tinggi seluruh tumpukan berarti jumlah berita hari itu.
                    </p>
                    <p v-if="narasi?.penjelasan_tren" class="max-w-[46rem] text-sm leading-relaxed">{{ narasi.penjelasan_tren }}</p>
                </CardContent>
            </Card>

            <!--
                Topik, bukan kata kunci. Judulnya kalimat yang menjelaskan
                isunya, dan seluruh angka di kartu dihitung Postgres setelah
                pengelompokan dari Gemini divalidasi.
            -->
            <div v-if="narasi?.topik.length" class="muncul space-y-3" style="animation-delay: 380ms">
                <div class="flex items-start gap-2">
                    <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-aksen-ungu/10">
                        <Flame class="h-4 w-4 text-aksen-ungu" aria-hidden="true" />
                    </span>
                    <div>
                        <h2 class="text-base font-semibold leading-7 text-primary dark:text-aksen-biru">Yang paling banyak diberitakan</h2>
                        <p class="text-sm text-muted-foreground">Ketuk salah satu untuk membaca berita yang membahasnya.</p>
                    </div>
                </div>

                <!--
                    Ketiga nada berwarna sekarang, tidak lagi hanya yang negatif.
                    Latar menyatakan nada, pita di tepi kiri menegaskannya, dan
                    ikon lambang raksasa di sudut mengulang bentuk yang sama.

                    Angka di kaki kartu dipecah jadi keping berjajar, bukan satu
                    kalimat panjang. Tiga fakta yang berbeda jenis, jumlah
                    berita, luas media, dan lamanya bertahan, terbaca sekilas
                    kalau masing-masing punya kotaknya sendiri.
                -->
                <div class="grid gap-3.5 md:grid-cols-2">
                    <Card
                        v-for="(topik, urutan) in narasi.topik"
                        :key="topik.judul"
                        :class="rupaTopik(topik.sentimen_dominan).kartu"
                        class="angkat muncul tekan relative overflow-hidden"
                        :style="{ animationDelay: `${420 + urutan * 70}ms` }"
                    >
                        <!-- Pita nada. Tetap terbaca di layar rapat yang warnanya melenceng. -->
                        <span :class="rupaTopik(topik.sentimen_dominan).pita" class="absolute inset-y-0 left-0 w-1" aria-hidden="true"></span>

                        <!-- Lambang nada raksasa, mengulang idiom kartu angka di atas halaman. -->
                        <component
                            :is="rupaTopik(topik.sentimen_dominan).ikon"
                            :class="rupaTopik(topik.sentimen_dominan).aksen"
                            class="pointer-events-none absolute -bottom-5 -right-4 h-28 w-28 opacity-[0.07]"
                            aria-hidden="true"
                        />

                        <CardContent class="relative p-0">
                            <Link
                                :href="`/eksekutif/berita?${kueri({ artikel: topik.artikel_ids.join(',') })}`"
                                class="group flex h-full flex-col gap-2.5 py-4 pl-5 pr-4"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <p class="text-sm font-semibold leading-snug">{{ topik.judul }}</p>
                                    <BadgeSentimen :label="topik.sentimen_dominan" ringkas class="mt-0.5 shrink-0" />
                                </div>

                                <p class="line-clamp-2 text-xs leading-relaxed text-muted-foreground">{{ topik.ringkasan }}</p>

                                <div class="mt-auto flex flex-wrap items-center gap-1.5 pt-1.5">
                                    <span class="angka inline-flex items-center gap-1.5 rounded-lg bg-background/70 px-2 py-1 text-xs font-medium">
                                        <Newspaper :class="rupaTopik(topik.sentimen_dominan).aksen" class="h-3.5 w-3.5" aria-hidden="true" />
                                        {{ formatAngka(topik.jumlah_artikel) }} berita
                                    </span>
                                    <span class="angka inline-flex items-center gap-1.5 rounded-lg bg-background/70 px-2 py-1 text-xs font-medium">
                                        <Globe2 :class="rupaTopik(topik.sentimen_dominan).aksen" class="h-3.5 w-3.5" aria-hidden="true" />
                                        {{ formatAngka(topik.jumlah_media) }} media
                                    </span>
                                    <span
                                        v-if="topik.hari_beruntun >= 2"
                                        class="angka inline-flex items-center gap-1.5 rounded-lg bg-background/70 px-2 py-1 text-xs font-medium"
                                    >
                                        <Clock :class="rupaTopik(topik.sentimen_dominan).aksen" class="h-3.5 w-3.5" aria-hidden="true" />
                                        {{ formatAngka(topik.hari_beruntun) }} hari beruntun
                                    </span>

                                    <ArrowRight
                                        :class="rupaTopik(topik.sentimen_dominan).aksen"
                                        class="ease-[cubic-bezier(0.32,0.72,0,1)] ml-auto h-4 w-4 shrink-0 transition-transform duration-300 group-hover:translate-x-1"
                                        aria-hidden="true"
                                    />
                                </div>
                            </Link>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <Card class="muncul overflow-hidden" style="animation-delay: 500ms">
                <CardHeader class="flex-row items-center justify-between bg-aksen-toska/[0.08] py-3.5">
                    <CardTitle class="flex items-center gap-2.5 text-base">
                        <span class="grid h-8 w-8 place-items-center rounded-xl bg-aksen-toska text-white shadow-sm">
                            <Globe2 class="h-[18px] w-[18px]" aria-hidden="true" />
                        </span>
                        Media yang paling banyak memberitakan
                    </CardTitle>
                    <Link
                        :href="`/eksekutif/media?${kueri()}`"
                        class="group inline-flex items-center gap-1 text-xs font-medium text-aksen-toska hover:underline"
                    >
                        Peringkat lengkap
                        <ArrowRight
                            class="ease-[cubic-bezier(0.32,0.72,0,1)] h-3 w-3 transition-transform duration-300 group-hover:translate-x-1"
                            aria-hidden="true"
                        />
                    </Link>
                </CardHeader>
                <CardContent class="pt-5">
                    <ul v-if="peringkatMedia.length" class="grid gap-2 sm:grid-cols-2">
                        <li v-for="(m, urutan) in peringkatMedia" :key="m.id">
                            <Link
                                :href="`/eksekutif/berita?${kueri({ media: m.id })}`"
                                class="tekan flex items-center gap-3 rounded-xl px-2.5 py-2 hover:bg-muted"
                            >
                                <span
                                    class="angka grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-aksen-toska/10 text-xs font-semibold text-aksen-toska"
                                >
                                    {{ urutan + 1 }}
                                </span>

                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center gap-3">
                                        <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ m.nama }}</span>
                                        <span class="angka shrink-0 text-xs text-muted-foreground">{{ formatAngka(m.jumlah_artikel) }} berita</span>
                                    </span>
                                    <!--
                                        Satu batang, panjangnya total berita
                                        media itu dibanding media teratas.
                                        Pembagian nada dilepas atas permintaan:
                                        daftar ini menjawab "siapa yang paling
                                        banyak menulis", dan nada pemberitaannya
                                        sudah punya tempatnya sendiri di atas.
                                    -->
                                    <span class="mt-1.5 block h-2 overflow-hidden rounded-full bg-muted">
                                        <span
                                            class="tumbuh block h-full rounded-full bg-aksen-toska/70"
                                            :style="{
                                                width: `${(m.jumlah_artikel / puncakMedia) * 100}%`,
                                                animationDelay: `${560 + urutan * 60}ms`,
                                            }"
                                        ></span>
                                    </span>
                                </span>
                            </Link>
                        </li>
                    </ul>
                    <p v-else class="py-4 text-sm text-muted-foreground">Belum ada media yang memberitakan Pemkot pada rentang ini.</p>
                </CardContent>
            </Card>

            <!--
                Tiga kelompok, bukan satu daftar kronologis. Pada rentang yang
                penuh kegiatan seremonial, satu-satunya berita negatif rentang
                itu terdorong keluar dari enam baris teratas justru pada hari
                pimpinan paling perlu melihatnya.
            -->
            <div class="muncul grid gap-4 lg:grid-cols-2" style="animation-delay: 560ms">
                <!--
                    Seluruh kartu diberi warna nada isinya, bukan hanya
                    kepalanya. Dua daftar berdampingan dengan badan putih yang
                    sama terbaca seperti satu daftar yang terpotong dua, dan
                    pembaca kehilangan bahwa keduanya menjawab pertanyaan yang
                    berlawanan.
                -->
                <Card v-if="beritaPerhatian.length" class="overflow-hidden border-sentimen-negatif/25 bg-sentimen-negatif-lembut/50">
                    <CardHeader class="flex-row items-center justify-between pb-3">
                        <CardTitle class="flex items-center gap-2.5 text-base text-sentimen-negatif">
                            <span class="grid h-8 w-8 place-items-center rounded-xl bg-sentimen-negatif text-white shadow-sm">
                                <TriangleAlert class="h-[18px] w-[18px]" aria-hidden="true" />
                            </span>
                            Berita yang bernada negatif
                        </CardTitle>
                        <Link
                            :href="`/eksekutif/berita?${kueri({ sentimen: 'negatif' })}`"
                            class="group inline-flex items-center gap-1 text-xs font-medium text-sentimen-negatif hover:underline"
                        >
                            Semua
                            <ArrowRight
                                class="ease-[cubic-bezier(0.32,0.72,0,1)] h-3 w-3 transition-transform duration-300 group-hover:translate-x-1"
                                aria-hidden="true"
                            />
                        </Link>
                    </CardHeader>
                    <CardContent>
                        <div class="divide-y divide-sentimen-negatif/15">
                            <KartuArtikel
                                v-for="berita in beritaPerhatian"
                                :key="berita.id"
                                v-bind="{
                                    judul: berita.judul,
                                    url: berita.url,
                                    media: berita.media,
                                    diambilAt: berita.diambil_at,
                                    label: berita.label,
                                    perluReview: berita.perlu_review,

                                    ringkasanAi: berita.ringkasan_ai,
                                }"
                                tampilkan-sentimen
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="beritaPositif.length" class="overflow-hidden border-sentimen-positif/25 bg-sentimen-positif-lembut/40">
                    <CardHeader class="flex-row items-center justify-between pb-3">
                        <CardTitle class="flex items-center gap-2.5 text-base text-sentimen-positif">
                            <span class="grid h-8 w-8 place-items-center rounded-xl bg-sentimen-positif text-white shadow-sm">
                                <ThumbsUp class="h-[18px] w-[18px]" aria-hidden="true" />
                            </span>
                            Berita bernada positif
                        </CardTitle>
                        <Link
                            :href="`/eksekutif/berita?${kueri({ sentimen: 'positif' })}`"
                            class="group inline-flex items-center gap-1 text-xs font-medium text-sentimen-positif hover:underline"
                        >
                            Semua
                            <ArrowRight
                                class="ease-[cubic-bezier(0.32,0.72,0,1)] h-3 w-3 transition-transform duration-300 group-hover:translate-x-1"
                                aria-hidden="true"
                            />
                        </Link>
                    </CardHeader>
                    <CardContent>
                        <div class="divide-y divide-sentimen-positif/15">
                            <KartuArtikel
                                v-for="berita in beritaPositif"
                                :key="berita.id"
                                v-bind="{
                                    judul: berita.judul,
                                    url: berita.url,
                                    media: berita.media,
                                    diambilAt: berita.diambil_at,
                                    label: berita.label,
                                    perluReview: berita.perlu_review,

                                    ringkasanAi: berita.ringkasan_ai,
                                }"
                                tampilkan-sentimen
                            />
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Card class="muncul overflow-hidden" style="animation-delay: 620ms">
                <CardHeader class="flex-row items-center justify-between bg-aksen-biru/[0.07] py-3.5">
                    <CardTitle class="flex items-center gap-2.5 text-base">
                        <span class="grid h-8 w-8 place-items-center rounded-xl bg-aksen-biru text-white shadow-sm">
                            <Clock class="h-[18px] w-[18px]" aria-hidden="true" />
                        </span>
                        Berita terbaru
                    </CardTitle>
                    <Link
                        :href="`/eksekutif/berita?${kueri()}`"
                        class="group inline-flex items-center gap-1 text-xs font-medium text-aksen-biru hover:underline"
                    >
                        Arsip berita
                        <ArrowRight
                            class="ease-[cubic-bezier(0.32,0.72,0,1)] h-3 w-3 transition-transform duration-300 group-hover:translate-x-1"
                            aria-hidden="true"
                        />
                    </Link>
                </CardHeader>
                <CardContent class="pt-5">
                    <!--
                        Lini masa, bukan daftar bergaris. Kartu ini satu-satunya
                        di halaman yang isinya berurut menurut waktu, dan garis
                        menurun dengan titik menyatakan urutan itu sebelum satu
                        keterangan waktu pun dibaca.

                        Titiknya berwarna nada beritanya, sehingga sebaran nada
                        sepanjang hari terbaca dari bentuk garisnya saja. Warna
                        bukan satu-satunya penanda: lencana sentimen di kanan
                        tiap baris tetap membawa ikon dan teksnya.
                    -->
                    <ol v-if="beritaTerbaru.length" class="relative">
                        <span class="absolute bottom-4 left-[5px] top-4 w-px bg-border" aria-hidden="true"></span>

                        <li v-for="berita in beritaTerbaru" :key="berita.id" class="tekan relative rounded-lg py-1 pl-7 pr-2 hover:bg-muted/60">
                            <span
                                :class="rupaTopik(berita.label ?? 'netral').pita"
                                class="absolute left-0 top-[18px] h-2.5 w-2.5 rounded-full ring-4 ring-card"
                                aria-hidden="true"
                            ></span>

                            <KartuArtikel
                                v-bind="{
                                    judul: berita.judul,
                                    url: berita.url,
                                    media: berita.media,
                                    diambilAt: berita.diambil_at,
                                    label: berita.label,
                                    perluReview: berita.perlu_review,
                                    ringkasanAi: berita.ringkasan_ai,
                                }"
                                tampilkan-sentimen
                            />
                        </li>
                    </ol>
                    <p v-else class="py-4 text-sm text-muted-foreground">Belum ada berita tentang Pemkot yang selesai diperiksa pada rentang ini.</p>
                </CardContent>
            </Card>

            <!--
                Catatan cakupan dan keterbatasan data pindah ke kaki halaman.
                Isinya sama untuk seluruh panel eksekutif, dan mengulangnya di
                ujung dashboard berarti dua paragraf bernada sama berdempetan.
            -->
        </template>
    </LayoutEksekutif>
</template>
