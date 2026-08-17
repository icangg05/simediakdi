<script setup lang="ts">
import ChartNadaMedia from '@/components/chart/ChartNadaMedia.vue';
import ChartTrenSentimen from '@/components/chart/ChartTrenSentimen.vue';
import Sparkline from '@/components/chart/Sparkline.vue';
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import KartuArtikel from '@/components/domain/KartuArtikel.vue';
import KartuEksekutif from '@/components/domain/KartuEksekutif.vue';
import KopEksekutif from '@/components/domain/KopEksekutif.vue';
import PemilihBulan from '@/components/domain/PemilihBulan.vue';
import PemilihRentangTanggal from '@/components/domain/PemilihRentangTanggal.vue';
import PermukaanKendaliKop from '@/components/domain/PermukaanKendaliKop.vue';
import PilKop from '@/components/domain/PilKop.vue';
import SentimenBelumTersedia from '@/components/domain/SentimenBelumTersedia.vue';
import TautanTujuan from '@/components/domain/TautanTujuan.vue';
import { Card, CardContent } from '@/components/ui/card';
import { useFormatAngka } from '@/composables/useFormatAngka';
import { useGerbangSentimen } from '@/composables/useGerbangSentimen';
import { usePeriodeEksekutif } from '@/composables/usePeriodeEksekutif';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import type { DeretMedia, DeretTren, LabelSentimen } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { differenceInCalendarDays, endOfMonth, format, formatDistanceToNow, isSameDay, isSameMonth, startOfMonth } from 'date-fns';
import { id } from 'date-fns/locale';
import {
    ArrowRight,
    CalendarRange,
    Check,
    CircleCheck,
    Clock,
    Flame,
    Gauge,
    Globe2,
    Handshake,
    Info,
    Minus,
    Newspaper,
    Radio,
    Scale,
    Sparkles,
    ThumbsUp,
    TrendingDown,
    TrendingUp,
    TriangleAlert,
} from 'lucide-vue-next';
import { computed } from 'vue';

/*
 * Arti warna di halaman ini, satu tabel yang mengikat seluruh berkas.
 *
 * | Warna            | Arti                                                  |
 * |------------------|-------------------------------------------------------|
 * | Navy merek       | Identitas halaman. Kop, dan tidak pernah data.        |
 * | Hijau sentimen   | Nada positif                                          |
 * | Abu sentimen     | Nada netral                                           |
 * | Merah sentimen   | Nada negatif                                          |
 * | Jingga review    | Perlu dicek ulang                                     |
 * | Aksen biru       | Berita dan arsipnya, tanpa saringan nada              |
 * | Aksen toska      | Media dan jangkauannya                                |
 * | Aksen ungu       | Apa pun yang disusun model                            |
 *
 * Aturannya satu kalimat: warna nada hanya boleh dipakai untuk nada
 * pemberitaan. Jumlah berita, jumlah media, dan rata-rata per hari bukan nada
 * apa pun, dan memberinya hijau atau merah membuat pembaca menyimpulkan sesuatu
 * yang tidak pernah dinyatakan datanya. Tabel yang sama dipakai
 * `KartuEksekutif` dan `TautanTujuan`, jadi ketiganya harus diubah bersamaan.
 */

type Berita = {
    id: number;
    judul: string;
    url: string | null;
    media: string | null;
    media_partner: boolean;
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
    opsiBulan: string[];
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
        media_total_aktif: number;
        media_bekerja_sama: number;
        media_tidak_bekerja_sama: number;
    };
    deret: DeretTren;
    deretMedia: DeretMedia;
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
    /** Berita negatif terakhir di seluruh arsip, tanpa batas rentang. */
    negatifTerakhir: Berita | null;
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

const tanggalTunggal = computed(() => isSameDay(new Date(props.periode.dari), new Date(props.periode.sampai)));

/** Satu hari dibaca sebagai nama hari dan tanggal, bukan rentang yang berulang. */
const rentangTerbaca = computed(() => {
    if (tanggalTunggal.value) {
        return format(new Date(props.periode.dari), 'EEEE, d MMMM yyyy', { locale: id });
    }

    return (
        `${format(new Date(props.periode.dari), 'd MMMM', { locale: id })} sampai ` +
        `${format(new Date(props.periode.sampai), 'd MMMM yyyy', { locale: id })}`
    );
});

const keteranganKop = computed(() =>
    tanggalTunggal.value
        ? `Apa yang ditulis media tentang Pemerintah Kota pada ${rentangTerbaca.value}`
        : `Apa yang ditulis media tentang Pemerintah Kota, ${rentangTerbaca.value}`,
);

const jumlahHari = computed(() => differenceInCalendarDays(new Date(props.periode.sampai), new Date(props.periode.dari)) + 1);

/**
 * Bulan kalender yang sudah lewat, dipilih lewat pemilih bulan.
 *
 * Keempat pintasan hanya menjawab keadaan yang sedang berjalan. Membiarkannya
 * tampil saat bulan lampau dibuka berarti menawarkan empat tombol yang semuanya
 * melempar pengguna kembali ke hari ini tanpa ia meminta.
 */
const bulanLampau = computed(() => {
    const awal = new Date(`${props.periode.dari}T00:00:00`);

    return (
        format(startOfMonth(awal), 'yyyy-MM-dd') === props.periode.dari &&
        format(endOfMonth(awal), 'yyyy-MM-dd') === props.periode.sampai &&
        !isSameMonth(awal, new Date())
    );
});

/** Dibulatkan ke bilangan bulat: "58 dari 100 berita" lebih mudah dibaca daripada "58,3%". */
function per100(bagian: number): number {
    return totalKomposisi.value === 0 ? 0 : Math.round((bagian / totalKomposisi.value) * 100);
}

/**
 * Tiga potong nada, satu barisan. Diberi nama sehari-hari, bukan istilah
 * analisis, karena halaman ini dibaca orang yang belum tentu pernah mendengar
 * kata sentimen.
 *
 * `kunci` sekaligus menjadi nilai saringan `sentimen` di arsip berita, jadi
 * tidak ada bidang kedua yang harus dijaga tetap sama dengan yang pertama.
 */
const komposisi = computed(() =>
    (
        [
            {
                kunci: 'positif',
                nama: 'Positif',
                arti: 'memberitakan hal baik',
                jumlah: props.kpi.positif,
                batang: 'bg-sentimen-positif',
                teks: 'text-sentimen-positif',
            },
            {
                kunci: 'netral',
                nama: 'Netral',
                arti: 'menyampaikan informasi',
                jumlah: props.kpi.netral,
                /*
                 * `batang` mewarnai potongan batang komposisi sekaligus titik
                 * di daftar bawahnya, jadi keduanya tidak pernah bisa berbeda.
                 * `teks` tetap nada kuatnya, karena itu angka yang harus
                 * terbaca, bukan bidang yang harus mundur.
                 */
                batang: 'bg-sentimen-netral-batang',
                teks: 'text-sentimen-netral',
            },
            {
                kunci: 'negatif',
                nama: 'Negatif',
                arti: 'menyoroti masalah atau kritik',
                jumlah: props.kpi.negatif,
                batang: 'bg-sentimen-negatif',
                teks: 'text-sentimen-negatif',
            },
        ] as const
    ).filter((p) => p.jumlah > 0),
);

/**
 * Penyebut persentase hanya tiga label itu, bukan seluruh berita berlabel.
 *
 * "Perlu dicek ulang" dikeluarkan dari barisan atas supaya kesimpulannya
 * ringkas. Angkanya tidak boleh ikut hilang: kalau ia tetap jadi penyebut
 * sementara potongnya tidak digambar, ketiga persentase tidak akan berjumlah
 * seratus dan selisihnya tidak ada penjelasannya di layar.
 */
const totalKomposisi = computed(() => komposisi.value.reduce((jumlah, p) => jumlah + p.jumlah, 0));

/**
 * Kesimpulan satu kata, dihitung dari angka dan bukan dari AI.
 *
 * Sengaja tidak memakai `narasi.nada`. Kalau pil di paling atas berasal dari
 * model sedangkan batang di bawahnya dari basis data, suatu hari keduanya akan
 * berbeda di layar yang sama, dan pembaca tidak punya cara tahu mana yang benar.
 *
 * Nada pilnya memakai kosakata `PilKop`, bukan token sentimen. Token sentimen
 * dirancang untuk latar terang, dan hijau setua itu di atas navy kop hanya
 * mencapai rasio 1,9. Pemetaannya tetap menjaga arti warna halaman: hijau untuk
 * kesimpulan positif, merah untuk kesimpulan negatif.
 */
const kondisi = computed(() => {
    if (props.kpi.berlabel === 0) {
        return { teks: 'Belum ada data', ikon: Scale, nada: 'netral' as const };
    }

    if (props.kpi.negatif_persen >= 25) {
        return { teks: 'Perlu perhatian', ikon: TriangleAlert, nada: 'buruk' as const };
    }

    if (per100(props.kpi.positif) >= 50) {
        return { teks: 'Cenderung positif', ikon: CircleCheck, nada: 'baik' as const };
    }

    return { teks: 'Beragam', ikon: Scale, nada: 'netral' as const };
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

/**
 * Arah volume dibanding rentang sebelumnya, dan sengaja tidak berwarna.
 *
 * Berita yang bertambah bukan kabar baik maupun buruk, ia hanya lebih banyak.
 * Panah hijau di sini akan terbaca sebagai pujian, dan halaman ini tidak pernah
 * menilai kinerja siapa pun.
 */
const arahVolume = computed(() => {
    if (props.kpi.berlabel_selisih > 0) return { ikon: TrendingUp, teks: `${formatAngka(props.kpi.berlabel_selisih)} lebih banyak` };
    if (props.kpi.berlabel_selisih < 0) return { ikon: TrendingDown, teks: `${formatAngka(Math.abs(props.kpi.berlabel_selisih))} lebih sedikit` };

    return { ikon: Minus, teks: 'Sama banyak' };
});

const namaSatuan = computed(
    () => ({ harian: 'per hari', mingguan: 'per pekan', dua_mingguan: 'per dua pekan', bulanan: 'per bulan' })[props.deret.satuan],
);

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
 * Berita negatif yang ditunjuk kartu ulasan.
 *
 * Yang pertama dipakai adalah berita negatif terbaru pada rentang ini. Kalau
 * rentangnya bersih, yang tampil adalah berita negatif terakhir yang tercatat,
 * dan kalimat di bawahnya menyatakan bahwa tanggalnya di luar rentang. Bagian
 * yang menghilang saat tidak ada berita negatif membuat pembaca menyimpulkan
 * sistemnya berhenti bekerja, bukan bahwa keadaannya memang sedang tenang.
 */
const negatifDisorot = computed(() => props.beritaPerhatian[0] ?? props.negatifTerakhir);

const negatifDiLuarRentang = computed(() => props.beritaPerhatian.length === 0 && props.negatifTerakhir !== null);

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
 * Itu sebabnya tiga kartu ini berlatar pastel sedangkan keping angka di kop
 * tidak. Yang diambil dari rujukan bentuknya: tile ikon berwarna penuh, tepi
 * tipis, latar kartu yang jauh lebih terang daripada tile-nya.
 */
const NADA_DIJELASKAN = [
    {
        kunci: 'positif',
        judul: 'Sisi baik yang diberitakan',
        ikon: ThumbsUp,
        kartu: 'bg-sentimen-positif-lembut/60 border-sentimen-positif/25',
        tile: 'bg-sentimen-positif',
        aksen: 'text-sentimen-positif',
        pita: 'from-sentimen-positif',
    },
    {
        kunci: 'netral',
        judul: 'Informasi yang disampaikan',
        ikon: Info,
        kartu: 'bg-sentimen-netral-bidang border-sentimen-netral/25',
        tile: 'bg-sentimen-netral',
        aksen: 'text-sentimen-netral',
        pita: 'from-sentimen-netral',
    },
    {
        kunci: 'negatif',
        judul: 'Masalah yang disorot',
        ikon: TriangleAlert,
        kartu: 'bg-sentimen-negatif-lembut/60 border-sentimen-negatif/25',
        tile: 'bg-sentimen-negatif',
        aksen: 'text-sentimen-negatif',
        pita: 'from-sentimen-negatif',
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
 *
 * Paling samar tetap harus terlihat, dan sebelumnya tidak. Diukur sebagai jarak
 * persepsi di bidang oklab terhadap latar halaman, ketiganya berbunyi 0,0342
 * untuk negatif, 0,0193 untuk positif, dan hanya 0,0076 untuk netral. Angka
 * terakhir itu bukan samar, melainkan tidak ada. Netral sekarang memakai
 * `netral-bidang` pada 55 persen dan mencapai 0,0184, tepat di bawah positif,
 * sehingga urutan di atas tetap berlaku sambil kartunya benar benar punya tepi.
 */
const RUPA_TOPIK = {
    negatif: {
        kartu: 'bg-sentimen-negatif-lembut/55 border-sentimen-negatif/25 hover:border-sentimen-negatif/45',
        pita: 'bg-sentimen-negatif',
        aksen: 'text-sentimen-negatif',
        ikon: TriangleAlert,
    },
    netral: {
        kartu: 'bg-sentimen-netral-bidang/55 border-sentimen-netral/25 hover:border-sentimen-netral/45',
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
        <KopEksekutif judul="Kondisi pemberitaan Kota Kendari" :keterangan="keteranganKop" siluet>
            <template #kendali>
                <!--
                    Rentangnya tampil, tetapi tidak bisa dipilih sendiri.
                    Halaman ini menjawab keadaan hari ini, pekan kalender,
                    bulan kalender, dan tiga bulan berjalan. Keempat pintasan memuat
                    seluruhnya. Rentang khusus dilayani halaman Arsip berita,
                    tempat penyaring memang jadi pekerjaan utamanya.
                -->
                <div class="flex flex-col gap-1.5 sm:flex-row sm:flex-wrap sm:items-start">
                    <PermukaanKendaliKop tanpa-padding>
                        <PemilihBulan
                            :dari="periode.dari"
                            :sampai="periode.sampai"
                            :opsi="opsiBulan"
                            @ubah="(dari, sampai) => pindah({ dari, sampai })"
                        />
                    </PermukaanKendaliKop>
                    <!--
                        Pintasan padam saat bulan lampau dibuka. Ulasan bulan
                        itu tetap tampil, dan pemilih bulan di sebelahnya yang
                        mengembalikan halaman ke bulan berjalan.
                    -->
                    <PemilihRentangTanggal
                        v-if="!bulanLampau"
                        :dari="periode.dari"
                        :sampai="periode.sampai"
                        inline
                        kalender
                        tanpa-pilih
                        @ubah="(dari, sampai) => pindah({ dari, sampai })"
                    />
                </div>
            </template>

            <!--
                Pil kesimpulan dan kalimat angka ikut padam saat sentimen belum
                tersedia. Keduanya dihitung dari kolom label yang memang belum
                terisi dalam keadaan itu, dan "Belum ada data" di kop akan
                dibaca sebagai "tidak ada berita", bukan sebagai "sistemnya
                belum bisa menilai".
            -->
            <template #pil>
                <PilKop v-if="sentimenTersedia" :nada="kondisi.nada" :ikon="kondisi.ikon">{{ kondisi.teks }}</PilKop>
                <PilKop :ikon="CalendarRange">{{ formatAngka(jumlahHari) }} hari</PilKop>
                <PilKop v-if="sentimenTersedia && narasi?.ringkasan" :ikon="Clock">Ulasan diperbarui {{ narasiUmur }}</PilKop>
            </template>

            <template v-if="sentimenTersedia">{{ kalimatAngka }}</template>

            <template v-if="sentimenTersedia" #inti>
                <template v-if="totalKomposisi > 0">
                    <!--
                        Batang komposisi menggantikan donat. Panjang yang
                        dibandingkan berdampingan jauh lebih mudah dibaca
                        daripada sudut lingkaran. Batangnya sendiri
                        `aria-hidden`, karena ketiga keping di bawahnya sudah
                        membawa angka yang sama sebagai teks.
                    -->
                    <div class="flex h-3.5 gap-1 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                        <div
                            v-for="(potong, urutan) in komposisi"
                            :key="potong.kunci"
                            :class="potong.batang"
                            class="tumbuh h-full rounded-full"
                            :style="{ width: `${(potong.jumlah / totalKomposisi) * 100}%`, animationDelay: `${320 + urutan * 120}ms` }"
                        ></div>
                    </div>

                    <!--
                        Tiga kolom, juga di layar 375 piksel. Ketiga nada hanya
                        bisa dibandingkan kalau ketiganya terlihat bersamaan, dan
                        menumpuknya ke bawah di ponsel justru menghapus
                        perbandingan yang menjadi alasan blok ini ada.
                    -->
                    <ul class="grid grid-cols-3 gap-1.5">
                        <li
                            v-for="(potong, urutan) in komposisi"
                            :key="potong.kunci"
                            class="muncul"
                            :style="{ animationDelay: `${420 + urutan * 90}ms` }"
                        >
                            <Link
                                :href="`/eksekutif/berita?${kueri({ sentimen: potong.kunci })}`"
                                class="tekan flex h-full flex-col gap-1 rounded-xl px-2 py-2 hover:bg-muted sm:px-3"
                            >
                                <span class="flex items-center gap-1.5">
                                    <span :class="potong.batang" class="size-2.5 shrink-0 rounded-full" aria-hidden="true"></span>
                                    <span class="truncate text-xs font-semibold sm:text-sm">{{ potong.nama }}</span>
                                </span>
                                <span :class="potong.teks" class="angka block text-2xl leading-none font-semibold sm:text-[2rem]">
                                    {{ formatAngka(potong.jumlah) }}
                                </span>
                                <span class="angka block text-[11px] leading-tight text-muted-foreground sm:text-xs">
                                    {{ per100(potong.jumlah) }} dari 100 berita
                                </span>
                                <span class="hidden text-[11px] leading-snug text-muted-foreground sm:block">{{ potong.arti }}</span>
                            </Link>
                        </li>
                    </ul>
                </template>

                <!--
                    Cakupan media memimpin bidang fakta karena ia menjawab
                    langsung berapa banyak media aktif yang benar-benar
                    memberitakan pada periode ini. Dua angka kerja sama adalah
                    pembentuk penyebutnya, bukan pembentuk pembilangnya.

                    Seluruhnya tetap satu bidang bergaris pemisah, bukan lima
                    kartu kecil di dalam kartu kop. Pada ponsel rasio media
                    memakai satu baris penuh, lalu empat fakta pendukung
                    tersusun dua kolom agar labelnya tidak terpotong.
                -->
                <div class="grid grid-cols-2 overflow-hidden rounded-xl border bg-muted/40 sm:grid-cols-5">
                    <Link
                        :href="`/eksekutif/media?${kueri()}`"
                        class="tekan group col-span-2 flex flex-col gap-1 border-b p-3 hover:bg-background sm:col-span-1 sm:border-r sm:border-b-0"
                    >
                        <span class="flex items-center gap-1.5 text-aksen-toska">
                            <Radio class="size-3.5 shrink-0" aria-hidden="true" />
                            <span class="text-[11px] font-semibold tracking-wide uppercase">Media</span>
                            <ArrowRight
                                class="ml-auto size-3 shrink-0 opacity-0 transition-all duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] group-hover:translate-x-0.5 group-hover:opacity-100"
                                aria-hidden="true"
                            />
                        </span>
                        <span class="angka flex items-baseline leading-none font-semibold">
                            <span class="text-2xl">{{ formatAngka(kpi.media_aktif) }}</span>
                            <span class="text-base text-muted-foreground">/{{ formatAngka(kpi.media_total_aktif) }}</span>
                        </span>
                        <span class="block text-[11px] leading-tight text-muted-foreground">Aktif memberitakan periode ini</span>
                    </Link>

                    <div class="flex flex-col gap-1 border-r border-b p-3 sm:border-b-0">
                        <span class="flex items-center gap-1.5 text-aksen-toska">
                            <Handshake class="size-3.5 shrink-0" aria-hidden="true" />
                            <span class="text-[11px] font-semibold tracking-wide uppercase">Bekerja sama</span>
                        </span>
                        <span class="angka block text-2xl leading-none font-semibold">{{ formatAngka(kpi.media_bekerja_sama) }}</span>
                        <span class="block text-[11px] leading-tight text-muted-foreground">Media aktif terdaftar</span>
                    </div>

                    <div class="flex flex-col gap-1 border-b p-3 sm:border-r sm:border-b-0">
                        <span class="flex items-center gap-1.5 text-aksen-toska">
                            <Globe2 class="size-3.5 shrink-0" aria-hidden="true" />
                            <span class="text-[11px] font-semibold tracking-wide uppercase">Tidak bekerja sama</span>
                        </span>
                        <span class="angka block text-2xl leading-none font-semibold">{{ formatAngka(kpi.media_tidak_bekerja_sama) }}</span>
                        <span class="block text-[11px] leading-tight text-muted-foreground">Media aktif terdaftar</span>
                    </div>

                    <Link :href="`/eksekutif/berita?${kueri()}`" class="tekan group flex flex-col gap-1 border-r p-3 hover:bg-background">
                        <span class="flex items-center gap-1.5 text-aksen-biru">
                            <Newspaper class="size-3.5 shrink-0" aria-hidden="true" />
                            <span class="text-[11px] font-semibold tracking-wide uppercase">Berita</span>
                            <ArrowRight
                                class="ml-auto size-3 shrink-0 opacity-0 transition-all duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] group-hover:translate-x-0.5 group-hover:opacity-100"
                                aria-hidden="true"
                            />
                        </span>
                        <span class="flex items-end gap-2">
                            <span class="angka text-2xl leading-none font-semibold">{{ formatAngka(kpi.berlabel) }}</span>
                            <Sparkline :nilai="deretVolume" :lebar="64" :tinggi="22" class="hidden shrink-0 lg:block" />
                        </span>
                        <span class="flex items-start gap-1 text-[11px] leading-tight text-muted-foreground">
                            <component :is="arahVolume.ikon" class="mt-px size-3 shrink-0" aria-hidden="true" />
                            <span class="angka">{{ arahVolume.teks }}</span>
                        </span>
                    </Link>

                    <div class="flex flex-col gap-1 p-3">
                        <span class="flex items-center gap-1.5 text-aksen-ungu">
                            <Gauge class="size-3.5 shrink-0" aria-hidden="true" />
                            <span class="truncate text-[11px] font-semibold tracking-wide uppercase">Per hari</span>
                        </span>
                        <span class="angka block text-2xl leading-none font-semibold">{{ formatAngka(rataPerHari) }}</span>
                        <span class="angka block text-[11px] leading-tight text-muted-foreground">
                            Dari {{ formatAngka(kpi.artikel) }} berita dipantau
                        </span>
                    </div>
                </div>
            </template>
        </KopEksekutif>

        <SentimenBelumTersedia v-if="!sentimenTersedia" :alasan="alasanSentimen" />

        <template v-else>
            <!-- Hanya dirender kalau ada isinya. Kartu kosong bertuliskan "tidak ada peringatan" menghabiskan ruang layar. -->
            <Card
                v-if="peringatan"
                class="muncul relative overflow-hidden border-sentimen-negatif/30 bg-sentimen-negatif-lembut"
                style="animation-delay: 120ms"
            >
                <span class="absolute inset-y-0 left-0 w-1 bg-sentimen-negatif" aria-hidden="true"></span>

                <CardContent class="flex flex-wrap items-center gap-3 py-3.5 pr-4 pl-5">
                    <span class="relative grid size-5 shrink-0 place-items-center">
                        <span class="denyut absolute inset-0 rounded-full bg-sentimen-negatif/25" aria-hidden="true"></span>
                        <TriangleAlert class="relative size-5 text-sentimen-negatif" aria-hidden="true" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="angka text-sm font-semibold text-sentimen-negatif">
                            {{ formatAngka(peringatan.jumlah) }} peringatan belum dibaca dalam 24 jam terakhir
                        </p>
                        <p class="truncate text-xs text-muted-foreground">{{ peringatan.terbaru }}</p>
                    </div>

                    <TautanTujuan :href="`/eksekutif/sentimen?${kueri()}`" rona="negatif">Buka rincian sentimen</TautanTujuan>
                </CardContent>
            </Card>

            <!--
                Penjelasan model berdiri sebagai kartunya sendiri, bukan bagian
                bawah kop.

                Satu blok yang memuat kesimpulan, angka, batang, dan tiga blok
                tulisan model menjadi terlalu panjang untuk dibaca sebagai satu
                unit. Yang di kop dihitung basis data, yang di sini ditulis
                model, dan pemisahan kartu menyatakan itu lebih jelas daripada
                garis mana pun. Judul tulisan model ikut pindah ke sini karena
                alasan yang sama.
            -->
            <div class="muncul" style="animation-delay: 180ms">
                <KartuEksekutif
                    judul="Ulasan pemberitaan periode ini"
                    catatan="Disusun otomatis oleh sistem dari angka yang sudah dihitung"
                    :ikon="Sparkles"
                    rona="ungu"
                >
                    <template v-if="narasi?.ringkasan">
                        <h2 v-if="narasi.judul" class="mb-2 max-w-184 text-lg leading-snug font-semibold text-pretty text-aksen-ungu sm:text-xl">
                            {{ narasi.judul }}
                        </h2>

                        <p class="max-w-184 text-sm leading-relaxed whitespace-pre-line">{{ narasi.ringkasan }}</p>

                        <!--
                            Tiap poin membuka berita yang mendasarinya. Id-nya
                            dikembalikan model bersama kalimatnya lalu divalidasi
                            server, jadi tautan ini tidak pernah menebak. Poin
                            tanpa id tetap dirender sebagai teks biasa, itu
                            keadaan narasi lama yang dibuat sebelum skemanya
                            berubah, dan poin tanpa tautan lebih baik daripada
                            tautan yang membuka daftar kosong.

                            Centangnya bernada merek, bukan hijau. Poin di sini
                            hanya hal yang tercatat pada rentang ini, dan
                            sebagiannya bisa kabar buruk. Centang hijau akan
                            membacanya sebagai kabar baik semua.
                        -->
                        <ul v-if="narasi.poin.length" class="mt-4 grid gap-1 sm:grid-cols-2">
                            <li v-for="poin in narasi.poin" :key="poin.teks">
                                <component
                                    :is="poin.artikel_ids.length ? Link : 'div'"
                                    :href="
                                        poin.artikel_ids.length ? `/eksekutif/berita?${kueri({ artikel: poin.artikel_ids.join(',') })}` : undefined
                                    "
                                    class="tekan group flex h-full gap-2.5 rounded-lg px-2 py-1.5 text-sm text-muted-foreground"
                                    :class="poin.artikel_ids.length ? 'hover:bg-muted' : ''"
                                >
                                    <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-brand/10">
                                        <Check class="size-3 text-brand dark:text-aksen-biru" aria-hidden="true" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        {{ poin.teks }}
                                        <span
                                            v-if="poin.artikel_ids.length"
                                            class="angka ml-1 text-xs font-semibold whitespace-nowrap text-aksen-ungu group-hover:underline"
                                        >
                                            {{ formatAngka(poin.artikel_ids.length) }} berita
                                        </span>
                                    </span>
                                </component>
                            </li>
                        </ul>

                        <!--
                            Tautan ke berita yang jadi bahan ulasan, bukan ke
                            seluruh arsip rentang ini. Kalimat yang dibaca
                            pimpinan harus bisa ditelusuri sampai ke berita
                            aslinya. Satu tautan untuk seluruh kartu, bukan per
                            kalimat: model tidak mengembalikan id artikel untuk
                            tiap butir, jadi tautan per kalimat hanya bisa dibuat
                            dengan menebak.
                        -->
                        <div class="mt-4 flex flex-col flex-wrap items-center justify-between gap-3 border-t pt-4 md:flex-row">
                            <p class="max-w-184 flex-1 text-xs leading-relaxed text-muted-foreground">
                                Ulasan ini menyebut sentimen dan volume pemberitaan, bukan penilaian atas kinerja siapa pun.
                                <template v-if="narasiBasi">
                                    Ia menghitung rentang {{ tanggalTerbaca(narasi.dari) }} sampai {{ tanggalTerbaca(narasi.sampai) }}, sedikit
                                    berbeda dari rentang yang sedang dibuka. Seluruh angka di halaman ini tetap yang terbaru.
                                </template>
                            </p>

                            <TautanTujuan
                                v-if="artikelUlasan.length"
                                :href="`/eksekutif/berita?${kueri({ artikel: artikelUlasan.join(',') })}`"
                                rona="ungu"
                                ukuran="sedang"
                                :ikon="Newspaper"
                            >
                                <span class="angka">Baca {{ formatAngka(artikelUlasan.length) }} berita aslinya</span>
                            </TautanTujuan>
                        </div>
                    </template>

                    <p v-else class="text-sm leading-relaxed text-muted-foreground">
                        Ulasan sedang disusun dari berita terbaru, dan tersedia untuk rentang Hari ini, Minggu ini, 3 bulan, serta tiap bulan
                        kalender. Seluruh angka di halaman ini sudah bisa dibaca sekarang.
                    </p>

                    <!--
                        Berita negatif terakhir, bukan kalimat tindak lanjut
                        tulisan model. Kalimat model tidak membawa id artikel,
                        sehingga pembaca yang ingin memeriksa sendiri harus
                        mencari beritanya di arsip. Baris ini membuka portal
                        aslinya langsung.

                        Satu berita saja, yang paling akhir. Daftar panjang di
                        sini mengulang kartu berita negatif di bawah halaman, dan
                        pengulangan itu membuat keduanya berhenti menarik
                        perhatian.

                        Berdiri di luar blok ulasan supaya tetap tampil saat
                        ulasan model belum ada, dan tetap terisi saat rentang
                        yang dibuka tidak memuat satu pun berita negatif.
                    -->
                    <div v-if="negatifDisorot" class="mt-4 rounded-xl border border-sentimen-review/30 bg-sentimen-review-lembut px-4 py-3">
                        <p class="flex items-center gap-1.5 text-sm font-semibold text-sentimen-review">
                            <TriangleAlert class="size-4 shrink-0" aria-hidden="true" />
                            Berita bersentimen negatif terakhir
                        </p>

                        <!--
                            Rentang berita ini selalu dinyatakan, bukan hanya
                            saat ia jatuh di luar rentang yang dibuka. Kartu ini
                            berdiri di halaman yang seluruh angkanya mengikuti
                            pemilih periode, dan satu berita tanpa keterangan
                            rentang akan dibaca sebagai berita hari ini.
                        -->
                        <p class="mt-1 text-xs leading-relaxed text-muted-foreground">
                            <template v-if="negatifDiLuarRentang">
                                Tidak ada berita negatif pada rentang ini. Yang ditampilkan adalah yang terakhir tercatat, di luar rentang yang sedang
                                dibuka.
                            </template>
                            <template v-else> Berita negatif terakhir pada {{ rentangTerbaca }}, rentang yang sedang dibuka. </template>
                        </p>

                        <KartuArtikel
                            v-bind="{
                                judul: negatifDisorot.judul,
                                url: negatifDisorot.url,
                                detailUrl: `/eksekutif/artikel/${negatifDisorot.id}`,
                                media: negatifDisorot.media,
                                mediaPartner: negatifDisorot.media_partner,
                                diambilAt: negatifDisorot.diambil_at,
                                label: negatifDisorot.label,
                                perluReview: negatifDisorot.perlu_review,
                                ringkasanAi: negatifDisorot.ringkasan_ai,
                            }"
                        />
                    </div>
                </KartuEksekutif>
            </div>

            <div class="muncul" style="animation-delay: 240ms">
                <KartuEksekutif judul="Media yang paling banyak memberitakan" :ikon="Globe2" rona="toska">
                    <template #aksi>
                        <TautanTujuan :href="`/eksekutif/media?${kueri()}`" rona="toska">Peringkat lengkap</TautanTujuan>
                    </template>

                    <ul v-if="peringkatMedia.length" class="grid gap-2 sm:grid-cols-2">
                        <li v-for="(m, urutan) in peringkatMedia" :key="m.id">
                            <Link
                                :href="`/eksekutif/berita?${kueri({ media: m.id })}`"
                                class="tekan flex items-center gap-3 rounded-xl px-2.5 py-2 hover:bg-muted"
                            >
                                <span
                                    class="angka grid size-7 shrink-0 place-items-center rounded-lg bg-aksen-toska/10 text-xs font-semibold text-aksen-toska"
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
                                        daftar ini menjawab siapa yang paling
                                        banyak menulis, dan nada pemberitaannya
                                        sudah punya tempatnya sendiri di kop.
                                    -->
                                    <span class="mt-1.5 block h-2 overflow-hidden rounded-full bg-muted">
                                        <span
                                            class="tumbuh block h-full rounded-full bg-aksen-toska/70"
                                            :style="{
                                                width: `${(m.jumlah_artikel / puncakMedia) * 100}%`,
                                                animationDelay: `${300 + urutan * 60}ms`,
                                            }"
                                        ></span>
                                    </span>
                                </span>
                            </Link>
                        </li>
                    </ul>
                    <p v-else class="py-4 text-sm text-muted-foreground">Belum ada media yang memberitakan Pemkot pada rentang ini.</p>
                </KartuEksekutif>
            </div>

            <!--
                Penjelasan model ditempel tepat di sebelah angka yang
                dijelaskannya. Batang di kop menjawab "berapa", tiga kolom ini
                menjawab "kenapa", dan itu pertanyaan berikutnya yang pasti
                muncul di kepala pembaca.
            -->
            <section v-if="narasi?.nada_ringkas.positif" class="muncul space-y-3" style="animation-delay: 300ms">
                <h2 class="flex items-center gap-2.5 text-base font-semibold text-primary dark:text-aksen-biru">
                    <span class="grid size-7 shrink-0 place-items-center rounded-lg bg-primary/10 dark:bg-aksen-biru/15">
                        <Info class="size-4" aria-hidden="true" />
                    </span>
                    <span class="shrink-0">Apa isi beritanya</span>
                    <!-- Rel judul seksi, warnanya navy merek dan bukan
                         `--border`. Token tepi bernilai 92,8 persen terang dan
                         di atas latar bertinta panel ini ia benar-benar tidak
                         terlihat, jadi garisnya hanya menambah simpul kosong. -->
                    <span class="h-px flex-1 bg-linear-to-r from-brand/30 to-transparent dark:from-aksen-biru/30" aria-hidden="true"></span>
                </h2>

                <!--
                    Warnanya tetap warna sentimen, bukan biru dan jingga seperti
                    gambar rujukan. Ketiga kartu ini menjelaskan tiga potong
                    batang di kop, dan hubungan itu hanya terbaca kalau warnanya
                    sama.
                -->
                <div class="grid gap-4 md:grid-cols-3">
                    <div
                        v-for="(bagian, urutan) in NADA_DIJELASKAN"
                        :key="bagian.kunci"
                        :class="bagian.kartu"
                        class="angkat muncul relative space-y-3 overflow-hidden rounded-2xl border p-5"
                        :style="{ animationDelay: `${360 + urutan * 90}ms` }"
                    >
                        <span
                            :class="bagian.pita"
                            class="tumbuh absolute inset-x-0 top-0 h-[3px] bg-linear-to-r to-transparent"
                            :style="{ animationDelay: `${420 + urutan * 90}ms` }"
                            aria-hidden="true"
                        ></span>

                        <p :class="bagian.aksen" class="flex items-center gap-3 text-sm leading-snug font-semibold">
                            <span
                                :class="bagian.tile"
                                class="grid size-9 shrink-0 place-items-center rounded-xl text-white shadow-xs dark:text-background"
                            >
                                <component :is="bagian.ikon" class="size-[18px]" aria-hidden="true" />
                            </span>
                            {{ bagian.judul }}
                        </p>
                        <p class="text-sm leading-relaxed text-foreground/75">{{ narasi.nada_ringkas[bagian.kunci] }}</p>
                    </div>
                </div>
            </section>

            <!--
                Grafik selalu ditemani satu kalimat. Grafik deret waktu adalah
                bagian halaman yang paling sering salah dibaca.

                Kepala kartunya diserahkan ke BaseChart, bukan dicetak dua kali.
                BaseChart menaruh judul sebaris dengan tombol tabel dan unduh,
                dan tombol "Lihat sebagai tabel" itu satu-satunya cara pembaca
                layar membaca grafik ini, jadi ia tidak boleh dipindah ke tempat
                lain. Garis rona di tepi atas tetap dipasang di sini supaya kartu
                ini tidak menjadi satu-satunya yang keluar dari keluarga bentuk
                halaman.
            -->
            <Card class="muncul relative overflow-hidden" style="animation-delay: 360ms">
                <div
                    class="tumbuh pointer-events-none absolute inset-x-0 top-0 h-[2px] bg-linear-to-r from-aksen-biru to-transparent"
                    aria-hidden="true"
                ></div>

                <CardContent class="space-y-3 p-4 pt-5 sm:p-5">
                    <ChartTrenSentimen judul="Perubahan dari waktu ke waktu" :data="deret.baris as never" :satuan="deret.satuan" :tinggi="280" />
                    <p class="text-xs leading-relaxed text-muted-foreground">
                        Tiap titik menghitung berita {{ namaSatuan }}. Tiap garis berdiri sendiri, jadi tinggi garis pada sumbu kiri adalah jumlah
                        berita dengan sentimen itu.
                    </p>
                    <p v-if="narasi?.penjelasan_tren" class="max-w-184 text-sm leading-relaxed">{{ narasi.penjelasan_tren }}</p>
                </CardContent>
            </Card>

            <!--
                Pasangan grafik di atasnya, bukan pengulangannya.

                Grafik garis menjawab bentuk perubahan sepanjang rentang.
                Grafik ini menjawab di media mana perubahan itu terjadi, satu
                periode pada satu waktu, dengan sumbu nama media yang tidak ikut
                berubah saat garis waktunya berjalan. Pai kecil di kanan atas
                menahan porsi nada periode itu, karena rentang yang penuh
                kegiatan seremonial menaikkan ketiga angka sekaligus dan
                pimpinan menyimpulkan keadaan memburuk, padahal pembagian
                nadanya tidak bergerak sama sekali.
            -->
            <Card class="muncul relative overflow-hidden" style="animation-delay: 400ms">
                <div
                    class="tumbuh pointer-events-none absolute inset-x-0 top-0 h-[2px] bg-linear-to-r from-aksen-toska to-transparent"
                    aria-hidden="true"
                ></div>

                <CardContent class="space-y-3 p-4 pt-5 sm:p-5">
                    <ChartNadaMedia :deret="deretMedia" :tinggi="440" />
                    <p class="text-xs leading-relaxed text-muted-foreground">
                        Tiap media punya tiga batang: jumlah berita positif, netral, dan negatif yang diterbitkannya pada periode yang sedang diputar.
                        Garis waktu di bawah grafik berjalan sendiri, tekan tombol jeda untuk berhenti di satu periode. Sumbu tegaknya dikunci ke
                        batang tertinggi sepanjang rentang, jadi tinggi batang bisa dibandingkan antar periode. Hanya media teramai yang ditampilkan:
                        dua belas di layar lebar, enam di ponsel.
                    </p>
                </CardContent>
            </Card>

            <!--
                Topik, bukan kata kunci. Judulnya kalimat yang menjelaskan
                isunya, dan seluruh angka di kartu dihitung Postgres setelah
                pengelompokan dari Gemini divalidasi.
            -->
            <section v-if="narasi?.topik.length" class="muncul space-y-3" style="animation-delay: 440ms">
                <h2 class="flex items-center gap-2.5 text-base font-semibold text-primary dark:text-aksen-biru">
                    <span class="grid size-7 shrink-0 place-items-center rounded-lg bg-aksen-ungu/10">
                        <Flame class="size-4 text-aksen-ungu" aria-hidden="true" />
                    </span>
                    <span class="shrink-0">Yang paling banyak diberitakan</span>
                    <!-- Rel judul seksi, warnanya navy merek dan bukan
                         `--border`. Token tepi bernilai 92,8 persen terang dan
                         di atas latar bertinta panel ini ia benar-benar tidak
                         terlihat, jadi garisnya hanya menambah simpul kosong. -->
                    <span class="h-px flex-1 bg-linear-to-r from-brand/30 to-transparent dark:from-aksen-biru/30" aria-hidden="true"></span>
                </h2>
                <p class="text-sm text-muted-foreground">Ketuk salah satu untuk membaca berita yang membahasnya.</p>

                <!--
                    Ketiga nada berwarna, tidak lagi hanya yang negatif. Latar
                    menyatakan nada, pita di tepi kiri menegaskannya, dan ikon
                    lambang raksasa di sudut mengulang bentuk yang sama.

                    Satu lambang raksasa saja per kartu. Percobaan dengan nomor
                    urut sebagai angka raksasa di sudut kanan atas dibatalkan
                    setelah dilihat di layar: angkanya jatuh persis di belakang
                    lencana sentimen dan bertabrakan dengan lambang nada di
                    sudut seberangnya, sehingga sudut kanan kartu berisi tiga
                    hal yang saling menutupi. Urutan topik sudah dinyatakan
                    posisinya di dalam petak, dan jumlah beritanya tertulis di
                    kaki kartu.
                -->
                <div class="grid gap-3.5 md:grid-cols-2">
                    <Card
                        v-for="(topik, urutan) in narasi.topik"
                        :key="topik.judul"
                        :class="rupaTopik(topik.sentimen_dominan).kartu"
                        class="angkat muncul tekan relative overflow-hidden"
                        :style="{ animationDelay: `${420 + urutan * 70}ms` }"
                    >
                        <span :class="rupaTopik(topik.sentimen_dominan).pita" class="absolute inset-y-0 left-0 w-1" aria-hidden="true"></span>

                        <component
                            :is="rupaTopik(topik.sentimen_dominan).ikon"
                            :class="rupaTopik(topik.sentimen_dominan).aksen"
                            class="pointer-events-none absolute -right-4 -bottom-5 size-28 opacity-[0.07]"
                            aria-hidden="true"
                        />

                        <CardContent class="relative p-0">
                            <Link
                                :href="`/eksekutif/berita?${kueri({ artikel: topik.artikel_ids.join(',') })}`"
                                class="group flex h-full flex-col gap-2.5 py-4 pr-4 pl-5"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <p class="text-sm leading-snug font-semibold">{{ topik.judul }}</p>
                                    <BadgeSentimen :label="topik.sentimen_dominan" ringkas class="mt-0.5 shrink-0" />
                                </div>

                                <p class="line-clamp-2 text-xs leading-relaxed text-muted-foreground">{{ topik.ringkasan }}</p>

                                <div class="mt-auto flex flex-wrap items-center gap-1.5 pt-1.5">
                                    <span class="angka inline-flex items-center gap-1.5 rounded-lg bg-background/70 px-2 py-1 text-xs font-medium">
                                        <Newspaper :class="rupaTopik(topik.sentimen_dominan).aksen" class="size-3.5" aria-hidden="true" />
                                        {{ formatAngka(topik.jumlah_artikel) }} berita
                                    </span>
                                    <span class="angka inline-flex items-center gap-1.5 rounded-lg bg-background/70 px-2 py-1 text-xs font-medium">
                                        <Globe2 :class="rupaTopik(topik.sentimen_dominan).aksen" class="size-3.5" aria-hidden="true" />
                                        {{ formatAngka(topik.jumlah_media) }} media
                                    </span>
                                    <span
                                        v-if="topik.hari_beruntun >= 2"
                                        class="angka inline-flex items-center gap-1.5 rounded-lg bg-background/70 px-2 py-1 text-xs font-medium"
                                    >
                                        <Clock :class="rupaTopik(topik.sentimen_dominan).aksen" class="size-3.5" aria-hidden="true" />
                                        {{ formatAngka(topik.hari_beruntun) }} hari beruntun
                                    </span>

                                    <ArrowRight
                                        :class="rupaTopik(topik.sentimen_dominan).aksen"
                                        class="ml-auto size-4 shrink-0 transition-transform duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] group-hover:translate-x-1"
                                        aria-hidden="true"
                                    />
                                </div>
                            </Link>
                        </CardContent>
                    </Card>
                </div>
            </section>

            <!--
                Dua kelompok berdampingan, bukan satu daftar kronologis. Pada
                rentang yang penuh kegiatan seremonial, satu-satunya berita
                negatif rentang itu terdorong keluar dari enam baris teratas
                justru pada hari pimpinan paling perlu melihatnya.

                Seluruh kartu diberi warna nada isinya, bukan hanya kepalanya.
                Dua daftar berdampingan dengan badan putih yang sama terbaca
                seperti satu daftar yang terpotong dua, dan pembaca kehilangan
                bahwa keduanya menjawab pertanyaan yang berlawanan.
            -->
            <div class="muncul grid gap-4 lg:grid-cols-2" style="animation-delay: 500ms">
                <KartuEksekutif v-if="beritaPerhatian.length" judul="Berita bersentimen negatif" :ikon="TriangleAlert" rona="negatif" bertinta>
                    <template #aksi>
                        <TautanTujuan :href="`/eksekutif/berita?${kueri({ sentimen: 'negatif' })}`" rona="negatif">Semua</TautanTujuan>
                    </template>

                    <div class="divide-y divide-sentimen-negatif/15">
                        <KartuArtikel
                            v-for="berita in beritaPerhatian"
                            :key="berita.id"
                            v-bind="{
                                judul: berita.judul,
                                url: berita.url,
                                detailUrl: `/eksekutif/artikel/${berita.id}`,
                                media: berita.media,
                                mediaPartner: berita.media_partner,
                                diambilAt: berita.diambil_at,
                                label: berita.label,
                                perluReview: berita.perlu_review,
                                ringkasanAi: berita.ringkasan_ai,
                            }"
                            tampilkan-sentimen
                        />
                    </div>
                </KartuEksekutif>

                <KartuEksekutif v-if="beritaPositif.length" judul="Berita bersentimen positif" :ikon="ThumbsUp" rona="positif" bertinta>
                    <template #aksi>
                        <TautanTujuan :href="`/eksekutif/berita?${kueri({ sentimen: 'positif' })}`" rona="positif">Semua</TautanTujuan>
                    </template>

                    <div class="divide-y divide-sentimen-positif/15">
                        <KartuArtikel
                            v-for="berita in beritaPositif"
                            :key="berita.id"
                            v-bind="{
                                judul: berita.judul,
                                url: berita.url,
                                detailUrl: `/eksekutif/artikel/${berita.id}`,
                                media: berita.media,
                                mediaPartner: berita.media_partner,
                                diambilAt: berita.diambil_at,
                                label: berita.label,
                                perluReview: berita.perlu_review,
                                ringkasanAi: berita.ringkasan_ai,
                            }"
                            tampilkan-sentimen
                        />
                    </div>
                </KartuEksekutif>
            </div>

            <div class="muncul" style="animation-delay: 560ms">
                <KartuEksekutif judul="Berita terbaru" :ikon="Clock" rona="biru">
                    <template #aksi>
                        <TautanTujuan :href="`/eksekutif/berita?${kueri()}`" rona="biru">Arsip berita</TautanTujuan>
                    </template>

                    <!--
                        Lini masa, bukan daftar bergaris. Kartu ini satu-satunya
                        di halaman yang isinya berurut menurut waktu, dan rel
                        menurun dengan titik menyatakan urutan itu sebelum satu
                        keterangan waktu pun dibaca. Relnya turun dari kepala
                        daftar saat halaman terbuka, arah baca barisnya.

                        Titiknya berwarna nada beritanya, sehingga sebaran nada
                        sepanjang hari terbaca dari bentuk relnya saja. Warna
                        bukan satu-satunya penanda: lencana sentimen di kanan
                        tiap baris tetap membawa ikon dan teksnya.
                    -->
                    <ol v-if="beritaTerbaru.length" class="relative">
                        <span
                            class="tumbuh-turun absolute top-4 bottom-4 left-[5px] w-px bg-linear-to-b from-aksen-biru/40 via-border to-transparent"
                            aria-hidden="true"
                        ></span>

                        <li v-for="berita in beritaTerbaru" :key="berita.id" class="tekan relative rounded-lg py-1 pr-2 pl-7 hover:bg-muted/60">
                            <span
                                :class="rupaTopik(berita.label ?? 'netral').pita"
                                class="absolute top-[18px] left-0 size-2.5 rounded-full ring-4 ring-card"
                                aria-hidden="true"
                            ></span>

                            <KartuArtikel
                                v-bind="{
                                    judul: berita.judul,
                                    url: berita.url,
                                    detailUrl: `/eksekutif/artikel/${berita.id}`,
                                    media: berita.media,
                                    mediaPartner: berita.media_partner,
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
                </KartuEksekutif>
            </div>
        </template>
    </LayoutEksekutif>
</template>
