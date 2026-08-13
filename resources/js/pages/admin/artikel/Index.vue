<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import KopHalaman from '@/components/domain/KopHalaman.vue';
import PilKop from '@/components/domain/PilKop.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { KolomDefinisi, OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import {
    BrainCircuit,
    ExternalLink,
    Filter,
    Loader2,
    Minus,
    Newspaper,
    RotateCcw,
    Sparkles,
    Target,
    ThumbsDown,
    ThumbsUp,
    Trash2,
    TrendingDown,
    TrendingUp,
    UserPen,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch, type Component } from 'vue';

type LabelSentimen = 'negatif' | 'netral' | 'positif';
type JalurKlasifikasi = 'gemini' | 'indobert';

interface Analisis {
    id: number;
    relevan: boolean;
    relevan_manual: boolean | null;
    label_efektif: LabelSentimen | null;
    label_manual: LabelSentimen | null;
    perlu_review: boolean;
    provider: string | null;
    reason_summary: string | null;
    evidence: string[] | null;
}

interface BarisArtikel {
    id: number;
    judul: string;
    url: string;
    media: string | null;
    ditambahkan_media: boolean;
    dipublikasikan_at: string | null;
    diambil_at: string;
    status_proses: string;
    analisis: Analisis | null;
}

const props = defineProps<{
    tahap: string;
    daftarTahap: { nilai: string; label: string; jumlah: number }[];
    relevansi: string | null;
    daftarRelevansi: { nilai: string; label: string; jumlah: number }[];
    sentimen: LabelSentimen | null;
    media: string | null;
    penyedia: string | null;
    asal: string | null;
    koreksi: boolean;
    pantauan: string;
    opsiMedia: OpsiFilter[];
    tanggal: { dari: string | null; sampai: string | null };
    artikel: { data: BarisArtikel[] } & PaginasiMeta;
    pembuangan: { boleh: boolean; jumlah: number };
}>();

const page = usePage();

// Kolom centang hanya ada saat pembuangan diizinkan, yaitu pada daftar Tidak
// relevan dan Menunggu review. Membiarkannya selalu tampil berarti kotak centang
// menganggur di daftar artikel relevan, dan kotak centang yang tidak menuju
// tindakan apa pun terbaca sebagai fitur yang rusak.
const kolom = computed<KolomDefinisi[]>(() => [
    ...(props.pembuangan.boleh ? [{ kunci: 'pilih', judul: '', lebar: 'w-10' }] : []),
    { kunci: 'no', judul: 'No', lebar: 'w-12', kelas: 'angka' },
    { kunci: 'judul', judul: 'Berita' },
    { kunci: 'media', judul: 'Media', lebar: 'w-36' },
    // Dua tanggal dalam satu kolom. Keduanya sering berbeda beberapa hari, dan
    // menjejerkannya membuat jeda antara berita terbit dan crawler mengambilnya
    // terbaca sekali lihat.
    { kunci: 'tanggal', judul: 'Terbit / Masuk', lebar: 'w-44' },
    { kunci: 'hasil', judul: 'Hasil AI', lebar: 'w-72' },
    { kunci: 'aksi', judul: '', lebar: 'w-52' },
]);

/**
 * Mengubah satu parameter tanpa menghapus sisanya.
 *
 * Filter tahap, media, tanggal, dan pencarian saling menumpuk. Menulis ulang
 * seluruh query string setiap kali satu berubah akan membuang pilihan yang
 * lain, dan admin harus menyetelnya kembali setiap menekan tombol.
 */
function pindah(perubahan: Record<string, string | number | null>) {
    // Dibaca dari `page.url` milik Inertia, bukan `window.location`, supaya
    // sumber query string di halaman ini sama dengan yang dipakai DataTable.
    // Dua sumber yang berbeda pernah membuat pencarian dan filter saling
    // menghapus tanpa ada yang menyadarinya.
    const params = Object.fromEntries(new URLSearchParams(page.url.split('?')[1] ?? ''));

    for (const [kunci, nilai] of Object.entries(perubahan)) {
        if (nilai === null || nilai === '') delete params[kunci];
        else params[kunci] = String(nilai);
    }

    delete params.halaman;

    router.get('/admin/artikel', params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

/**
 * Reka UI memakai string kosong sebagai penanda "belum dipilih", jadi opsi
 * "semua" perlu nilainya sendiri. Diterjemahkan menjadi null saat dikirim ke
 * server supaya parameternya hilang dari URL alih-alih terisi kata "semua".
 */
const SEMUA = 'semua';

const mediaTerpilih = computed({
    get: () => props.media ?? SEMUA,
    set: (nilai: string) => pindah({ media: nilai === SEMUA ? null : nilai }),
});

/*
 * Pencarian media disaring di peramban, bukan lewat permintaan ke server.
 *
 * Seluruh daftar media memang sudah ikut terkirim bersama halaman lewat
 * `opsiMedia`, dan jumlahnya puluhan, bukan puluhan ribu. Menyaringnya lewat
 * server berarti satu permintaan untuk setiap huruf yang diketik, untuk
 * menyaring daftar yang sudah ada seluruhnya di memori peramban.
 */
const cariMedia = ref('');

const opsiMediaTersaring = computed(() => {
    const kata = cariMedia.value.trim().toLowerCase();

    if (kata === '') return props.opsiMedia;

    return props.opsiMedia.filter((opsi) => opsi.label.toLowerCase().includes(kata));
});

/**
 * Tombol navigasi tetap milik dropdown, huruf dan angka milik kotak cari.
 *
 * Reka UI menyalakan lompat-ketik pada daftarnya: menekan huruf akan memindahkan
 * sorotan ke item yang berawalan huruf itu. Tanpa penghalang ini, mengetik di
 * kotak cari akan sekaligus melompati daftar di bawahnya, dan huruf yang sama
 * mengerjakan dua hal yang saling berlawanan. Panah, Enter, Escape, dan Tab
 * sengaja dibiarkan naik supaya dropdown tetap bisa dijelajahi dan ditutup lewat
 * papan ketik.
 */
const TOMBOL_DROPDOWN = ['ArrowUp', 'ArrowDown', 'Home', 'End', 'Enter', 'Escape', 'Tab'];

function ketikanCari(peristiwa: KeyboardEvent) {
    if (TOMBOL_DROPDOWN.includes(peristiwa.key)) return;

    peristiwa.stopPropagation();
}

/**
 * Penilai yang mengerjakan barisnya, bukan penilai yang sedang disetel.
 *
 * Mengganti opsi di halaman Pengaturan tidak menilai ulang apa pun, jadi arsip
 * berisi campuran keduanya. Saringan ini yang membuat campuran itu bisa
 * dipisahkan, misalnya untuk membandingkan berapa banyak keputusan IndoBERT
 * yang akhirnya dikoreksi admin dibandingkan keputusan Gemini.
 */
const penyediaTerpilih = computed({
    get: () => props.penyedia ?? SEMUA,
    set: (nilai: string) => pindah({ penyedia: nilai === SEMUA ? null : nilai }),
});

/**
 * Jalan masuk artikel, bukan penilaian atas isinya.
 *
 * `dilaporkan_oleh` terisi hanya untuk berita yang diketik pengguna media di
 * portal. Saringan ini menjawab pertanyaan yang tidak bisa dijawab kolom mana
 * pun sebelumnya: berapa banyak arsip yang datang karena feed bekerja, dan
 * berapa banyak yang datang karena media menambalnya sendiri. Berlaku di
 * ketiga tahap, karena kolomnya sudah terisi sejak baris artikel dibuat.
 */
const asalTerpilih = computed({
    get: () => props.asal ?? SEMUA,
    set: (nilai: string) => pindah({ asal: nilai === SEMUA ? null : nilai }),
});

/**
 * Tidak ditawarkan di Belum diklasifikasi.
 *
 * Artikel yang belum dinilai tidak punya penilai, jadi menyaringnya di sana
 * selalu mengosongkan tabel. Sama seperti saringan relevansi.
 */
const penyediaBisaDisaring = computed(() => props.tahap !== 'belum');

/**
 * Pindah tahap, membuang saringan yang tidak berlaku di tujuannya.
 *
 * Relevansi dan sentimen selalu dibuang karena keduanya hanya ada di tahap
 * Selesai. Penyedia hanya dibuang saat menuju Belum diklasifikasi: di Selesai
 * dan Menunggu review ia tetap berguna, dan membuangnya di sana berarti admin
 * yang membandingkan kedua penilai harus menyetel ulang setiap berpindah tab.
 */
function pindahTahap(nilai: string) {
    pindah({
        tahap: nilai,
        relevansi: null,
        sentimen: null,
        ...(nilai === 'belum' ? { penyedia: null } : {}),
    });
}

/**
 * Relevansi hanya bisa disaring pada tahap Selesai.
 *
 * Di Belum diklasifikasi belum ada baris analisis sama sekali. Di Menunggu
 * review seluruh barisnya bernilai relevan false, bukan karena Gemini
 * memutuskan artikelnya tidak relevan melainkan karena ia menolak memutuskan.
 * Menawarkan saringan Relevan di sana menghasilkan tabel kosong yang terlihat
 * seperti kerusakan, padahal justru sesuai rancangan.
 */
const relevansiBisaDisaring = computed(() => props.tahap === 'selesai');

/**
 * Sentimen hanya ada pada artikel yang relevan.
 *
 * Gemini baru menilai nada setelah relevansinya berbunyi relevan, jadi
 * menyaringnya pada daftar Tidak relevan atau Belum diklasifikasi selalu
 * mengosongkan tabel.
 */
const sentimenBisaDisaring = computed(() => props.relevansi === 'relevan');

/**
 * Tanpa nilai bawaan, dan bisa dimatikan lagi.
 *
 * Ketiga nada setara, jadi memilih salah satunya sebagai bawaan akan
 * menyembunyikan dua per tiga isi tabel tanpa diminta. Menekan tombol yang
 * sedang menyala mengembalikan daftar ke seluruh nada.
 */
const saringanSentimen: { nilai: LabelSentimen; label: string }[] = [
    { nilai: 'positif', label: 'Positif' },
    { nilai: 'netral', label: 'Netral' },
    { nilai: 'negatif', label: 'Negatif' },
];

/**
 * Id artikel yang sedang dinilai, supaya tombolnya terkunci satu per satu.
 *
 * Klasifikasi berjalan sinkron dan memakan satu sampai tiga detik. Tanpa
 * penanda ini, klik kedua pada baris yang sama mengirim permintaan kedua ke
 * Gemini untuk artikel yang hasilnya sebentar lagi datang.
 */
const sedangJalan = ref<number | null>(null);
const sedangJalur = ref<JalurKlasifikasi | null>(null);

/**
 * Opsi yang sama untuk ketiga aksi baris: klasifikasi, relevansi, dan reset.
 *
 * `showProgress: false` mematikan bilah progres bawaan Inertia di puncak
 * halaman. Bilah itu berguna untuk perpindahan halaman, tetapi di sini ia
 * menunjuk tempat yang salah: yang sedang bekerja adalah satu baris, dan
 * barisnya sudah punya penandanya sendiri berupa tombol yang terkunci dengan
 * ikon berputar. Dua penanda untuk satu pekerjaan membuat halaman terlihat
 * sedang dimuat ulang seluruhnya, padahal tidak.
 */
/**
 * Sisa waktu sampai salah satu kunci Gemini bisa dipakai.
 *
 * Tidak dimulai setelah setiap klasifikasi. Server mengirim angkanya hanya
 * ketika seluruh kunci aktif dipakai dalam 15 detik terakhir. Selama masih ada
 * kunci lain yang siap, panggilan berikutnya langsung berjalan dengan kunci
 * itu. Pemeriksaan ini juga berlaku pada sentimen jalur IndoBERT + Gemini.
 *
 * Penegakan yang sebenarnya ada di server. Tombol yang diredupkan bukan aturan,
 * permintaannya tetap bisa dikirim langsung.
 */
const jeda = ref(0);

/*
 * Dipasang dan dilepas mengikuti umur komponen.
 *
 * Dulu `setInterval` dipanggil langsung di badan setup. Di peramban itu lolos
 * karena halamannya dibongkar bersama seluruh dokumen. Sejak halaman ini ikut
 * dirender di server, badan setup dijalankan sekali per permintaan di proses
 * Node yang hidup terus, dan timer yang tidak pernah dihentikan menumpuk satu
 * per kunjungan sampai prosesnya kehabisan memori.
 */
let pencacah: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    pencacah = setInterval(() => {
        if (jeda.value > 0) jeda.value--;
    }, 1000);
});

onUnmounted(() => {
    if (pencacah) clearInterval(pencacah);
});

/** Terkunci selama satu penilaian berjalan, dan selama jeda setelahnya. */
const terkunci = computed(() => sedangJalan.value !== null || jeda.value > 0);

const opsiAksi = {
    preserveScroll: true,
    showProgress: false,
    onFinish: () => {
        sedangJalan.value = null;
        sedangJalur.value = null;

        // `onFinish` jalan setelah propsnya diperbarui. Nilainya adalah sisa
        // aktual kunci yang paling cepat pulih, bukan boolean pemakaian Gemini.
        const sisa = (page.props.flash as { jedaGemini?: number | false } | undefined)?.jedaGemini;
        jeda.value = typeof sisa === 'number' ? sisa : 0;
    },
};

const terpilih = ref<number[]>([]);
const sedangBuang = ref(false);

/**
 * Pilihan dibersihkan tiap kali isi tabel berganti.
 *
 * Pindah halaman, ganti saringan, atau ganti tahap membuat baris yang tadi
 * dicentang tidak terlihat lagi. Membiarkan id-nya tetap tersimpan berarti
 * tombol "Buang 12 terpilih" merujuk baris yang tidak ada satu pun di layar,
 * dan admin menekan tombol yang menghapus sesuatu yang tidak sedang dilihatnya.
 */
watch(
    () => props.artikel.data,
    () => (terpilih.value = []),
);

const idHalamanIni = computed(() => props.artikel.data.map((b) => b.id));

const semuaTercentang = computed(() => idHalamanIni.value.length > 0 && terpilih.value.length === idHalamanIni.value.length);

function alihkanSemua() {
    terpilih.value = semuaTercentang.value ? [] : [...idHalamanIni.value];
}

function alihkanSatu(id: number) {
    terpilih.value = terpilih.value.includes(id) ? terpilih.value.filter((satu) => satu !== id) : [...terpilih.value, id];
}

const opsiBuang = {
    preserveScroll: true,
    preserveState: true,
    showProgress: false,
    onFinish: () => {
        sedangBuang.value = false;
        terpilih.value = [];
    },
};

function buang(id: number[]) {
    sedangBuang.value = true;
    router.delete('/admin/artikel/buang', { data: { id }, ...opsiBuang });
}

function buangSatu(baris: BarisArtikel) {
    if (confirm(`Buang artikel "${baris.judul}"? URL-nya dicatat supaya tidak masuk lagi lewat crawl.`)) {
        buang([baris.id]);
    }
}

function buangTerpilih() {
    if (confirm(`Buang ${terpilih.value.length} artikel terpilih? Tindakan ini tidak bisa dibatalkan.`)) {
        buang([...terpilih.value]);
    }
}

function buangSemua() {
    // Dua pertanyaan, bukan satu. Yang pertama menyebut angkanya, yang kedua
    // memaksa jeda sebelum ribuan baris hilang tanpa bisa dikembalikan.
    if (!confirm(`Buang seluruh ${props.pembuangan.jumlah} artikel yang cocok dengan saringan ini?`)) {
        return;
    }

    if (!confirm('Sekali lagi: artikel dan analisisnya hilang permanen. Lanjutkan?')) {
        return;
    }

    sedangBuang.value = true;

    // Saringan yang sedang terpasang ikut dikirim lewat query string, supaya
    // yang terhapus persis yang terlihat di tabel, bukan seluruh tabel.
    router.delete(`/admin/artikel/buang-semua${window.location.search}`, opsiBuang);
}

/**
 * Baris yang koreksi manusianya akan dicabut.
 *
 * Dicabut berarti dinilai ulang, bukan dikembalikan ke putusan AI yang lama.
 * Relevansi hanya punya satu kolom yang sudah ditimpa keputusan admin, jadi
 * putusan AI sebelumnya memang tidak tersimpan di mana pun.
 */
const konfirmasiReset = ref<BarisArtikel | null>(null);

function reset() {
    if (konfirmasiReset.value === null) return;

    const id = konfirmasiReset.value.id;

    konfirmasiReset.value = null;
    sedangJalan.value = id;

    router.post(`/admin/artikel/${id}/reset`, {}, opsiAksi);
}

/** Hanya baris yang benar-benar punya koreksi manusia yang bisa direset. */
const adaKoreksi = (baris: BarisArtikel) =>
    baris.analisis !== null && (baris.analisis.relevan_manual !== null || baris.analisis.label_manual !== null);

function klasifikasi(baris: BarisArtikel, jalur: JalurKlasifikasi) {
    // Kedua tombol adalah aksi langsung, termasuk ketika artikel sudah pernah
    // dinilai. Jalur yang dipilih dikirim eksplisit dan hasil lama ditimpa oleh
    // penilaian baru tanpa dialog konfirmasi tambahan.
    jalankan(baris.id, jalur);
}

function jalankan(id: number, jalur: JalurKlasifikasi) {
    sedangJalan.value = id;
    sedangJalur.value = jalur;

    router.post(`/admin/artikel/${id}/klasifikasi`, { jalur }, opsiAksi);
}

/**
 * Baris yang sedang ditanyakan konfirmasi keputusan relevansinya.
 *
 * Keputusan ini menulis `relevan_manual`, penanda yang membuat klasifikasi
 * ulang berikutnya melewati artikel ini sepenuhnya. Salah tekan berarti label
 * manusia yang keliru bertahan melewati setiap penilaian ulang, dan tidak ada
 * yang akan menyadarinya karena barisnya justru terlihat sudah beres.
 */
const konfirmasiRelevansi = ref<{ baris: BarisArtikel; relevan: boolean } | null>(null);

function tanyakan(baris: BarisArtikel, relevan: boolean) {
    konfirmasiRelevansi.value = { baris, relevan };
}

function putuskan() {
    if (konfirmasiRelevansi.value === null) return;

    const { baris, relevan } = konfirmasiRelevansi.value;

    konfirmasiRelevansi.value = null;
    sedangJalan.value = baris.id;

    router.post(`/admin/artikel/${baris.id}/relevansi`, { relevan }, opsiAksi);
}

/**
 * Tombol relevan dan tidak relevan hanya di tahap Menunggu review.
 *
 * Di situlah Gemini menyatakan dirinya ragu, jadi keputusan manusia memang
 * yang ditunggu. Pada Belum diklasifikasi belum ada yang perlu dikoreksi, dan
 * pada Selesai keputusannya sudah diambil, sehingga tombol di kedua tahap itu
 * hanya mengundang perubahan yang tidak disengaja.
 */
const bisaDiputuskan = computed(() => props.tahap === 'review');

/*
 * Sistem warna halaman ini: satu rona, satu arti.
 *
 * Sebelumnya halaman ini memakai palet Tailwind mentah, emerald, rose, violet,
 * sky, dan slate, sementara sisa aplikasi memakai token domain di app.css. Dua
 * akibatnya nyata. Badge "Positif" di sini hijau emerald sedangkan badge
 * "Positif" di panel eksekutif hijau token, dua warna berbeda untuk satu arti
 * yang sama persis. Dan satu rona dipakai untuk arti yang berbeda: hijau
 * menandai nada positif sekaligus tombol "tandai relevan", merah menandai nada
 * negatif sekaligus tombol buang. Admin melihat tombol merah "Tidak" persis di
 * sebelah badge merah "Negatif", dua hal yang tidak berhubungan sama sekali.
 *
 * Pembagian yang berlaku sekarang, dan tidak boleh dilanggar di halaman ini:
 *
 * | Rona            | Arti                              |
 * |-----------------|-----------------------------------|
 * | Hijau sentimen  | Nada pemberitaan positif          |
 * | Abu sentimen    | Nada pemberitaan netral           |
 * | Merah sentimen  | Nada pemberitaan negatif          |
 * | Kuning sentimen | Menunggu keputusan manusia        |
 * | Aksen ungu      | Gemini, penilai LLM eksternal     |
 * | Aksen biru      | IndoBERT, model lokal             |
 * | Aksen toska     | Masuk lingkup pantauan            |
 * | Abu redup       | Di luar lingkup pantauan          |
 * | Destructive     | Penghapusan permanen              |
 *
 * Ketiga rona aksen dipilih karena app.css memang menyediakannya untuk
 * keperluan di luar nada, dan rona-nya sengaja dijauhkan dari palet sentimen.
 * Ketiganya juga sudah punya nilai mode gelap tersendiri, jadi tidak ada warna
 * yang tenggelam saat temanya dibalik.
 */

/**
 * Relevansi diwarnai menurut lingkup, bukan menurut baik dan buruk.
 *
 * Dulu Relevan hijau dan Tidak relevan merah. Selain meminjam rona sentimen,
 * merah menyiratkan berita yang buruk, padahal artinya hanya di luar cakupan
 * Pemkot. Berita kegiatan perusahaan swasta bukan kabar buruk, ia sekadar bukan
 * urusan sistem ini. Sekarang relevan memakai toska yang berarti terhitung, dan
 * tidak relevan memakai abu redup yang berarti dikesampingkan.
 *
 * `variant="outline"` wajib menyertainya. Varian bawaan Badge membawa
 * `hover:bg-primary/80`, dan tailwind-merge mempertahankannya karena `bg-` dan
 * `hover:bg-` adalah dua grup properti yang berbeda. Akibatnya badge berwarna
 * berubah menjadi gelap begitu kursor lewat, seolah ia tombol yang bisa
 * ditekan padahal bukan.
 */
const warnaRelevansi = (relevan: boolean) =>
    relevan ? 'border-transparent bg-aksen-toska/10 text-aksen-toska' : 'border-transparent bg-muted text-muted-foreground';

/**
 * Rona nada, diambil dari token yang sama dengan BadgeSentimen dan grafik.
 *
 * Dipakai saringan nada di kepala halaman. Badge di dalam tabel tidak memakai
 * peta ini melainkan komponen BadgeSentimen langsung, karena komponen itu
 * menyatakan dirinya satu-satunya tempat yang boleh merender indikator sentimen
 * dan ia sudah membawa ikon serta teks sebagai penanda kedua.
 */
const warnaSentimen: Record<LabelSentimen, string> = {
    positif: 'bg-sentimen-positif-lembut text-sentimen-positif',
    netral: 'bg-sentimen-netral-lembut text-sentimen-netral',
    negatif: 'bg-sentimen-negatif-lembut text-sentimen-negatif',
};

/** Ikon nada, supaya saringan tidak bergantung pada warna saja. */
const ikonSentimen: Record<LabelSentimen, Component> = {
    positif: TrendingUp,
    netral: Minus,
    negatif: TrendingDown,
};

const { formatAngka } = useFormatAngka();

const waktu = (n: string) => format(new Date(n), 'd MMM yyyy', { locale: id });

const dari = ref(props.tanggal.dari ?? '');
const sampai = ref(props.tanggal.sampai ?? '');

watch([dari, sampai], ([d, s]) => pindah({ dari: d || null, sampai: s || null }));
</script>

<template>
    <Head title="Artikel" />

    <!-- Bilah kop aplikasi mengisi dirinya dari daftar ini. Tanpa daftar itu
         yang tampil hanya tombol sidebar dengan sisa ruang kosong sepanjang
         layar, dan halaman ini menjadi satu-satunya halaman admin yang kopnya
         tidak menyebutkan sedang berada di mana. -->
    <LayoutAdmin :breadcrumbs="[{ title: 'Berita', href: '/admin/artikel' }]">
        <!--
            Jarak tepi tidak ditulis lagi di sini. LayoutAdmin sudah membungkus
            slotnya dengan `p-4`, jadi `p-4` kedua di halaman ini memberi bidang
            isinya dua lapis jarak dan membuat tabelnya lebih sempit daripada
            tabel di seluruh halaman admin lain.
        -->
        <div class="space-y-4">
            <KopHalaman
                judul="Berita"
                keterangan="Arsip berita beserta putusan relevansi dan nadanya. Setiap baris bisa dinilai ulang lewat Gemini penuh atau kombinasi IndoBERT dan Gemini."
            >
                <PilKop :ikon="Target">Yang dinilai: {{ pantauan }}</PilKop>
                <PilKop :ikon="Newspaper">
                    <span class="angka">{{ formatAngka(artikel.total) }}</span> berita pada saringan ini
                </PilKop>
                <!-- Jeda kuota diangkat ke kop, bukan hanya tercetak di tombol
                     tiap baris. Selama jeda berjalan seluruh tabel terkunci, dan
                     itu keadaan halaman, bukan keadaan satu baris. -->
                <PilKop v-if="jeda > 0" nada="tunggu" :ikon="Loader2" berputar>
                    Kuota Gemini pulih dalam <span class="angka">{{ jeda }}</span> detik
                </PilKop>
            </KopHalaman>

            <!--
                Tiga kelompok saringan, dijejer mengikuti urutan menyempitnya.

                Tahap lebih dulu karena ia menentukan dua kelompok lainnya
                muncul atau tidak: relevansi hanya ada di Selesai, dan sentimen
                hanya ada setelah relevansi disetel ke Relevan. Sebelumnya
                urutannya terbalik dan posisinya dijaga `justify-between` dengan
                dua `div` kosong sebagai pengganjal, sehingga kelompok tahap
                berpindah tempat setiap kali admin membuka Belum diklasifikasi.

                Panah di antara kelompok menyatakan penyempitan itu sebagai
                bentuk, jadi tidak perlu satu kalimat pun untuk menjelaskan
                kenapa dua kelompok lainnya kadang tidak ada.
            -->
            <div class="muncul flex flex-wrap items-center gap-2" style="animation-delay: 60ms">
                <Filter class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />

                <div class="flex flex-wrap gap-1 rounded-lg bg-muted p-1">
                    <!-- Pindah tahap ikut membuang saringan relevansi. Kalau
                         tidak, membuka Belum diklasifikasi dari tab lain
                         menyembunyikan kontrolnya sementara filternya tetap
                         menyaring, dan tabel kosongnya terlihat seperti bug. -->
                    <button
                        v-for="t in daftarTahap"
                        :key="t.nilai"
                        type="button"
                        class="tekan rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                        :class="
                            tahap === t.nilai
                                ? 'bg-brand text-white shadow-sm dark:bg-brand-terang'
                                : 'text-muted-foreground hover:bg-background hover:text-foreground'
                        "
                        :aria-pressed="tahap === t.nilai"
                        @click="pindahTahap(t.nilai)"
                    >
                        {{ t.label }}
                        <span class="angka ml-1 text-xs opacity-70">{{ formatAngka(t.jumlah) }}</span>
                    </button>
                </div>

                <svg
                    v-if="relevansiBisaDisaring"
                    viewBox="0 0 12 24"
                    class="h-5 w-3 shrink-0 text-muted-foreground/40"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    aria-hidden="true"
                >
                    <path d="M3 6l5 6-5 6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>

                <div v-if="relevansiBisaDisaring" class="flex flex-wrap gap-1 rounded-lg bg-muted p-1">
                    <button
                        v-for="r in daftarRelevansi"
                        :key="r.nilai"
                        type="button"
                        class="tekan rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                        :class="
                            relevansi === r.nilai
                                ? 'bg-aksen-toska text-white shadow-sm dark:text-background'
                                : 'text-muted-foreground hover:bg-background hover:text-foreground'
                        "
                        :aria-pressed="relevansi === r.nilai"
                        @click="pindah({ relevansi: r.nilai, sentimen: null })"
                    >
                        {{ r.label }}
                        <span class="angka ml-1 text-xs opacity-70">{{ formatAngka(r.jumlah) }}</span>
                    </button>
                </div>

                <svg
                    v-if="sentimenBisaDisaring"
                    viewBox="0 0 12 24"
                    class="h-5 w-3 shrink-0 text-muted-foreground/40"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    aria-hidden="true"
                >
                    <path d="M3 6l5 6-5 6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>

                <!-- Memakai token nada yang sama dengan BadgeSentimen di kolom
                     Hasil AI supaya hijau di saringan dan hijau di tabel berarti
                     hal yang sama persis. Ikonnya juga sama, karena warna
                     sendirian bukan penanda yang cukup. -->
                <div v-if="sentimenBisaDisaring" class="flex flex-wrap gap-1 rounded-lg bg-muted p-1">
                    <button
                        v-for="s in saringanSentimen"
                        :key="s.nilai"
                        type="button"
                        class="tekan inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                        :class="
                            sentimen === s.nilai
                                ? warnaSentimen[s.nilai] + ' shadow-sm'
                                : 'text-muted-foreground hover:bg-background hover:text-foreground'
                        "
                        :aria-pressed="sentimen === s.nilai"
                        @click="pindah({ sentimen: sentimen === s.nilai ? null : s.nilai })"
                    >
                        <component :is="ikonSentimen[s.nilai]" class="size-3.5 shrink-0" aria-hidden="true" />
                        {{ s.label }}
                    </button>
                </div>
            </div>

            <DataTable
                class="muncul"
                style="animation-delay: 120ms"
                :kolom="kolom"
                :data="artikel.data"
                :meta="artikel"
                pencarian
                url-basis="/admin/artikel"
                judul-kosong="Tidak ada berita pada tahap ini"
                keterangan-kosong="Pilih tahap lain di atas, atau tunggu crawler mengambil berita baru."
            >
                <template #aksi>
                    <!-- Kotak cari dikosongkan setiap dropdown ditutup. Saringan
                         yang tertinggal dari pemakaian sebelumnya membuat daftar
                         terbuka dalam keadaan sudah terpotong, dan media yang
                         hilang darinya terbaca seperti media yang terhapus. -->
                    <Select v-model="mediaTerpilih" @update:open="(terbuka) => terbuka || (cariMedia = '')">
                        <SelectTrigger class="h-8 w-44" aria-label="Saring menurut media">
                            <SelectValue placeholder="Semua media" />
                        </SelectTrigger>
                        <SelectContent>
                            <template #atas>
                                <div class="border-b p-1">
                                    <Input
                                        v-model="cariMedia"
                                        class="h-8 border-0 text-sm shadow-none focus-visible:ring-0 focus-visible:ring-offset-0"
                                        placeholder="Cari media"
                                        aria-label="Cari nama media"
                                        @keydown="ketikanCari"
                                    />
                                </div>
                            </template>

                            <SelectItem :value="SEMUA">Semua media</SelectItem>
                            <SelectItem v-for="m in opsiMediaTersaring" :key="m.nilai" :value="m.nilai">
                                {{ m.label }}
                            </SelectItem>

                            <p v-if="opsiMediaTersaring.length === 0" class="px-2 py-3 text-center text-xs text-muted-foreground">
                                Tidak ada media bernama "{{ cariMedia }}".
                            </p>
                        </SelectContent>
                    </Select>

                    <Select v-if="penyediaBisaDisaring" v-model="penyediaTerpilih">
                        <SelectTrigger class="h-8 w-40" aria-label="Saring menurut penilai">
                            <SelectValue placeholder="Semua penilai" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="SEMUA">Semua penilai</SelectItem>
                            <SelectItem value="gemini">Gemini</SelectItem>
                            <SelectItem value="indobert">IndoBERT</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select v-model="asalTerpilih">
                        <SelectTrigger class="h-8 w-44" aria-label="Saring menurut asal berita">
                            <SelectValue placeholder="Semua asal" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="SEMUA">Semua asal</SelectItem>
                            <SelectItem value="crawler">Otomatis (crawler)</SelectItem>
                            <SelectItem value="portal">Ditambahkan media</SelectItem>
                        </SelectContent>
                    </Select>

                    <!-- Menyala berarti hanya baris yang pernah disentuh
                         manusia. Penanda yang sama sudah tampil sebagai badge
                         Dikoreksi di kolom Hasil AI, jadi tombol ini menyaring
                         hal yang persis sama, bukan pengertian baru. -->
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 rounded-md border px-3 text-sm font-medium transition-colors"
                        :class="koreksi ? 'border-transparent bg-foreground text-background' : 'text-muted-foreground hover:text-foreground'"
                        :aria-pressed="koreksi"
                        @click="pindah({ koreksi: koreksi ? null : 1 })"
                    >
                        <UserPen class="size-3.5" />
                        Dikoreksi
                    </button>

                    <div class="flex items-center gap-1">
                        <Label for="dari" class="text-xs text-muted-foreground">Dari</Label>
                        <Input id="dari" v-model="dari" type="date" class="h-8 w-36" />
                        <Label for="sampai" class="text-xs text-muted-foreground">sampai</Label>
                        <Input id="sampai" v-model="sampai" type="date" class="h-8 w-36" />
                    </div>

                    <!-- Hanya pada daftar Tidak relevan dan Menunggu review.
                         Artikel relevan tidak pernah dapat tombol buang, berapa
                         pun sentimennya, karena justru itu isi laporan. -->
                    <template v-if="pembuangan.boleh">
                        <button
                            v-if="artikel.data.length > 0"
                            type="button"
                            class="inline-flex h-8 items-center gap-1.5 rounded-md border px-3 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                            @click="alihkanSemua"
                        >
                            {{ semuaTercentang ? 'Bersihkan pilihan' : 'Pilih halaman ini' }}
                        </button>

                        <Button v-if="terpilih.length > 0" size="sm" variant="outline" class="h-8" :disabled="sedangBuang" @click="buangTerpilih">
                            <Trash2 class="size-3.5" />
                            Buang {{ terpilih.length }} terpilih
                        </Button>

                        <Button size="sm" variant="destructive" class="h-8" :disabled="sedangBuang || pembuangan.jumlah === 0" @click="buangSemua">
                            <Loader2 v-if="sedangBuang" class="size-3.5 animate-spin" />
                            <Trash2 v-else class="size-3.5" />
                            Buang semua ({{ formatAngka(pembuangan.jumlah) }})
                        </Button>
                    </template>
                </template>

                <template #sel-pilih="{ baris }">
                    <!-- radix-vue v1 memakai `checked`, bukan `model-value`.
                         Nama yang salah membuat prop-nya diabaikan dan kotaknya
                         jalan sendiri: tampak tercentang, tetapi `terpilih`
                         tidak pernah terisi dan tombol buang tidak muncul. -->
                    <Checkbox :checked="terpilih.includes(baris.id)" :aria-label="`Pilih ${baris.judul}`" @update:checked="alihkanSatu(baris.id)" />
                </template>

                <!-- Nomor urut meneruskan hitungan halaman, bukan mulai dari 1
                     lagi di tiap halaman. Nomor yang berulang membuat rujukan
                     lisan antar-admin menunjuk baris yang berbeda. -->
                <template #sel-no="{ indeks }">
                    <span class="text-sm text-muted-foreground">{{ (artikel.from ?? 1) + indeks }}.</span>
                </template>

                <template #sel-judul="{ baris }">
                    <div class="flex items-start gap-1">
                        <Link :href="`/admin/artikel/${baris.id}`" class="font-medium hover:underline">
                            {{ baris.judul }}
                        </Link>
                        <a :href="baris.url" target="_blank" rel="noopener noreferrer" :aria-label="`Buka ${baris.judul} di situs aslinya`">
                            <ExternalLink class="mt-1 size-3 shrink-0 opacity-60" />
                        </a>
                    </div>
                </template>

                <!-- Penanda asal ikut di kolom Media, bukan kolom sendiri.
                     Mayoritas baris datang dari crawler, jadi kolom penuh yang
                     hampir selalu berbunyi sama hanya memakan lebar tanpa
                     memberi tahu apa pun. -->
                <template #sel-media="{ baris }">
                    <span class="text-sm text-muted-foreground">{{ baris.media ?? '-' }}</span>
                    <Badge v-if="baris.ditambahkan_media" variant="outline" class="ml-1.5 font-normal"> Dari media </Badge>
                </template>

                <!-- Tanda hubung untuk tanggal terbit yang kosong, bukan
                     diisi tanggal masuk. Tidak semua feed mencantumkannya, dan
                     menyalin tanggal masuk ke sana membuat jeda yang justru
                     ingin dibaca menjadi selalu nol. -->
                <template #sel-tanggal="{ baris }">
                    <span class="whitespace-nowrap text-sm text-muted-foreground">
                        {{ baris.dipublikasikan_at ? waktu(baris.dipublikasikan_at) : '-' }}
                        <span class="opacity-40">/</span>
                        {{ waktu(baris.diambil_at) }}
                    </span>
                </template>

                <template #sel-hasil="{ baris }">
                    <div v-if="baris.analisis === null" class="text-sm text-muted-foreground">Belum dinilai</div>

                    <div v-else class="space-y-1.5">
                        <!-- Seluruh penanda di baris ini memakai bentuk yang sama,
                             `rounded-md` dengan bobot medium, mengikuti bentuk
                             BadgeSentimen. Pil bulat berdampingan dengan chip
                             bersudut di satu baris terbaca sebagai dua jenis
                             benda padahal keduanya penanda yang setara. -->
                        <div class="flex flex-wrap items-center gap-1.5">
                            <Badge variant="outline" class="rounded-md font-medium" :class="warnaRelevansi(baris.analisis.relevan)">
                                {{ baris.analisis.relevan ? 'Relevan' : 'Tidak relevan' }}
                            </Badge>

                            <!-- Sentimen hanya ditampilkan untuk artikel relevan. Baris yang
                                 pernah dinilai relevan lalu dikoreksi menjadi tidak relevan
                                 tetap menyimpan label lamanya, dan label itu sudah tidak
                                 dihitung agregasi mana pun. Menampilkannya membuat admin
                                 membaca angka yang tidak ada di dashboard.

                                 Dirender BadgeSentimen, bukan Badge berwarna sendiri.
                                 Komponen itu menyatakan dirinya satu-satunya tempat yang
                                 boleh merender indikator sentimen, dan halaman ini dulu
                                 melanggarnya dengan palet emerald dan rose buatan sendiri.
                                 Akibatnya hijau "Positif" di sini berbeda dari hijau
                                 "Positif" di panel eksekutif. -->
                            <BadgeSentimen v-if="baris.analisis.relevan && baris.analisis.label_efektif" :label="baris.analisis.label_efektif" />

                            <Badge
                                v-if="baris.analisis.perlu_review"
                                variant="outline"
                                class="rounded-md bg-sentimen-review-lembut font-medium text-sentimen-review"
                            >
                                Perlu review
                            </Badge>

                            <!-- Penanda bahwa nilainya keputusan manusia, bukan Gemini.
                                 Tanpa ini admin tidak bisa membedakan baris yang sudah
                                 diperiksa dari baris yang kebetulan sependapat. Ikonnya
                                 sama dengan tombol saringan Dikoreksi di atas tabel,
                                 karena keduanya menyaring dan menandai hal yang sama. -->
                            <Badge
                                v-if="baris.analisis.relevan_manual !== null || baris.analisis.label_manual"
                                variant="outline"
                                class="rounded-md font-medium"
                            >
                                <UserPen class="size-3 shrink-0" aria-hidden="true" />
                                Dikoreksi
                            </Badge>

                            <!-- Penanda penilai relevansi, hanya untuk IndoBERT.
                                 Gemini tidak diberi badge karena ia yang
                                 mengerjakan hampir seluruh arsip, dan penanda
                                 yang muncul di setiap baris berhenti menjadi
                                 penanda. Yang perlu terlihat sekali lihat adalah
                                 baris yang keputusannya datang dari model baru
                                 dan karena itu perlu diperiksa lebih dulu.

                                 Biru aksen dan ikon BrainCircuit, sama persis
                                 dengan tombol IndoBERT di kolom aksi, jadi
                                 penanda hasil dan tombol yang menghasilkannya
                                 terbaca sebagai satu hal. -->
                            <Badge
                                v-if="baris.analisis.provider === 'indobert'"
                                variant="outline"
                                class="rounded-md bg-aksen-biru/10 font-medium text-aksen-biru"
                            >
                                <BrainCircuit class="size-3 shrink-0" aria-hidden="true" />
                                IndoBERT
                            </Badge>
                        </div>

                        <p v-if="baris.analisis.reason_summary" class="text-xs text-muted-foreground">
                            {{ baris.analisis.reason_summary }}
                        </p>

                        <details v-if="baris.analisis.evidence?.length" class="text-xs">
                            <summary class="cursor-pointer text-muted-foreground hover:text-foreground">
                                Bukti ({{ baris.analisis.evidence.length }})
                            </summary>
                            <ul class="mt-1 space-y-1 border-l pl-2 text-muted-foreground">
                                <li v-for="(kutipan, i) in baris.analisis.evidence" :key="i">&ldquo;{{ kutipan }}&rdquo;</li>
                            </ul>
                        </details>
                    </div>
                </template>

                <template #sel-aksi="{ baris }">
                    <div class="flex flex-col gap-1.5">
                        <!--
                            Seluruh tabel dikunci selama satu artikel dinilai,
                            bukan barisnya saja. Klasifikasi berjalan sinkron dan
                            memakan satu sampai tiga detik, dan permintaan kedua
                            yang berangkat di tengah jalan memakai satu jatah
                            kuota Gemini untuk hasil yang belum tentu sempat
                            terbaca. Barisnya sendiri tetap dibedakan lewat ikon
                            berputar, jadi admin tahu mana yang sedang dikerjakan.
                        -->
                        <!-- Ungu berarti Gemini, biru berarti IndoBERT, dan
                             pasangan itu berlaku di seluruh halaman: tombolnya,
                             badge penilai di kolom Hasil AI, dan tombol
                             konfirmasi di dialog reset. Isian penuh untuk
                             Gemini karena ia jalur bawaan, garis tepi untuk
                             IndoBERT karena ia pilihan kedua.

                             `dark:text-background`, bukan putih terus. Token
                             aksen dicerahkan di mode gelap, jadi isian penuhnya
                             membalik arah dan teks putih di atasnya hilang. -->
                        <Button
                            size="sm"
                            class="w-full bg-aksen-ungu text-white hover:bg-aksen-ungu/90 disabled:opacity-70 dark:text-background"
                            :disabled="terkunci"
                            title="Relevansi dan sentimen dinilai dengan Gemini"
                            @click="klasifikasi(baris, 'gemini')"
                        >
                            <Loader2 v-if="sedangJalan === baris.id && sedangJalur === 'gemini'" class="size-3.5 animate-spin" />
                            <Sparkles v-else class="size-3.5" />
                            <!-- Hitungan mundur tampil di seluruh baris, bukan
                                 hanya di baris yang barusan ditekan. Jedanya
                                 memang berlaku untuk seluruh tabel, dan tombol
                                 redup tanpa keterangan terbaca sebagai rusak. -->
                            <template v-if="sedangJalan === baris.id && sedangJalur === 'gemini'">Menilai</template>
                            <template v-else-if="jeda > 0">Tunggu {{ jeda }}s</template>
                            <template v-else>Klasifikasi Gemini</template>
                        </Button>

                        <Button
                            size="sm"
                            variant="outline"
                            class="w-full border-aksen-biru/40 text-aksen-biru hover:bg-aksen-biru/10 hover:text-aksen-biru disabled:opacity-70"
                            :disabled="terkunci"
                            title="Relevansi dinilai IndoBERT, sentimen dinilai Gemini"
                            @click="klasifikasi(baris, 'indobert')"
                        >
                            <Loader2 v-if="sedangJalan === baris.id && sedangJalur === 'indobert'" class="size-3.5 animate-spin" />
                            <BrainCircuit v-else class="size-3.5" />
                            <template v-if="sedangJalan === baris.id && sedangJalur === 'indobert'">Menilai</template>
                            <template v-else-if="jeda > 0">Tunggu {{ jeda }}s</template>
                            <template v-else>IndoBERT + Gemini</template>
                        </Button>

                        <button
                            v-if="adaKoreksi(baris)"
                            type="button"
                            class="inline-flex items-center justify-center gap-1 rounded-md border px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-50"
                            :disabled="terkunci"
                            @click="konfirmasiReset = baris"
                        >
                            <RotateCcw class="size-3" />
                            Reset koreksi
                        </button>

                        <!-- Kedua tombol memakai warna hasil yang akan mereka
                             tuliskan: toska untuk masuk pantauan, abu redup
                             untuk di luar pantauan. Sebelumnya keduanya hijau
                             dan merah, dan tombol merah "Tidak" berdiri persis
                             di sebelah badge merah "Negatif" yang artinya sama
                             sekali berbeda. Ibu jari naik dan turun tetap ada
                             sebagai penanda kedua, karena keputusan ini tidak
                             boleh bergantung pada warna saja. -->
                        <div v-if="bisaDiputuskan" class="flex gap-1">
                            <button
                                type="button"
                                class="inline-flex flex-1 items-center justify-center gap-1 rounded-md bg-aksen-toska/10 px-2 py-1 text-xs font-medium text-aksen-toska transition-colors hover:bg-aksen-toska/20 disabled:opacity-50"
                                :disabled="terkunci"
                                @click="tanyakan(baris, true)"
                            >
                                <ThumbsUp class="size-3" aria-hidden="true" />
                                Relevan
                            </button>
                            <button
                                type="button"
                                class="inline-flex flex-1 items-center justify-center gap-1 rounded-md bg-muted px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-50"
                                :disabled="terkunci"
                                @click="tanyakan(baris, false)"
                            >
                                <ThumbsDown class="size-3" aria-hidden="true" />
                                Tidak
                            </button>
                        </div>

                        <button
                            v-if="pembuangan.boleh"
                            type="button"
                            class="inline-flex items-center justify-center gap-1 rounded-md border px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:border-sentimen-negatif/40 hover:bg-sentimen-negatif-lembut hover:text-sentimen-negatif disabled:opacity-50"
                            :disabled="sedangBuang"
                            @click="buangSatu(baris)"
                        >
                            <Trash2 class="size-3" />
                            Buang
                        </button>
                    </div>
                </template>
            </DataTable>
        </div>

        <Dialog :open="konfirmasiRelevansi !== null" @update:open="(buka) => !buka && (konfirmasiRelevansi = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Tandai
                        {{ konfirmasiRelevansi?.relevan ? 'relevan' : 'tidak relevan' }}?
                    </DialogTitle>
                    <DialogDescription>
                        Keputusan ini tercatat sebagai keputusan manusia, dan klasifikasi ulang berikutnya akan melewatinya. Gemini tidak akan
                        menimpanya.
                    </DialogDescription>
                </DialogHeader>

                <p class="rounded-md bg-muted/50 p-3 text-sm font-medium">
                    {{ konfirmasiRelevansi?.baris.judul }}
                </p>

                <p class="text-xs text-muted-foreground">
                    <template v-if="konfirmasiRelevansi?.relevan">
                        Sentimennya langsung dinilai Gemini setelah ini, jadi satu permintaan akan terkirim.
                    </template>
                    <template v-else>
                        Artikel dikeluarkan dari dashboard dan tidak dinilai sentimennya, jadi tidak ada permintaan yang dikirim ke Gemini.
                    </template>
                </p>

                <DialogFooter>
                    <Button variant="outline" @click="konfirmasiRelevansi = null">Batal</Button>
                    <!-- Warnanya mengikuti tombol yang membuka dialog ini, jadi
                         admin menekan dua tombol berwarna sama untuk satu
                         keputusan. Menandai tidak relevan bukan tindakan
                         merusak, artikelnya tetap tersimpan dan keputusannya
                         bisa dicabut, jadi tombolnya tidak memakai merah. -->
                    <Button
                        :class="
                            konfirmasiRelevansi?.relevan
                                ? 'bg-aksen-toska text-white hover:bg-aksen-toska/90 dark:text-background'
                                : 'bg-foreground text-background hover:bg-foreground/90'
                        "
                        @click="putuskan"
                    >
                        {{ konfirmasiRelevansi?.relevan ? 'Ya, relevan' : 'Ya, tidak relevan' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="konfirmasiReset !== null" @update:open="(buka) => !buka && (konfirmasiReset = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Cabut koreksi dan nilai ulang?</DialogTitle>
                    <DialogDescription>
                        Koreksi manusia pada artikel ini dihapus, lalu Gemini menilainya kembali dari nol. Satu permintaan akan terkirim dan memakai
                        kuota.
                    </DialogDescription>
                </DialogHeader>

                <p class="rounded-md bg-muted/50 p-3 text-sm font-medium">
                    {{ konfirmasiReset?.judul }}
                </p>

                <!-- Bukan sekadar mengembalikan tampilan ke putusan AI. Kolom
                     relevansi hanya satu dan sudah ditimpa keputusan admin, jadi
                     putusan AI yang lama memang tidak tersimpan di mana pun dan
                     harus dihitung ulang. -->
                <p class="text-xs text-muted-foreground">
                    Hasilnya bisa berbeda dari penilaian AI sebelumnya, karena yang dijalankan penilaian baru, bukan pemulihan yang lama.
                </p>

                <DialogFooter>
                    <Button variant="outline" @click="konfirmasiReset = null">Batal</Button>
                    <!-- Ungu, sama seperti tombol Klasifikasi Gemini, karena
                         yang dijalankan tombol ini memang satu penilaian Gemini
                         baru dan memakai kuotanya. -->
                    <Button class="bg-aksen-ungu text-white hover:bg-aksen-ungu/90 dark:text-background" @click="reset">
                        Cabut dan nilai ulang
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </LayoutAdmin>
</template>
