<script setup lang="ts">
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import KopHalaman from '@/components/domain/KopHalaman.vue';
import PilKop from '@/components/domain/PilKop.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import {
    ArrowLeft,
    Building2,
    CalendarDays,
    Check,
    ChevronRight,
    Copy,
    DownloadCloud,
    ExternalLink,
    FileText,
    Loader2,
    Minus,
    PenLine,
    Quote,
    Radar,
    Rss,
    ScanSearch,
    Sparkles,
    TrendingDown,
    TrendingUp,
    UserRoundCheck,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Label = 'negatif' | 'netral' | 'positif';

interface Analisis {
    id: number;
    relevan: boolean;
    relevan_manual: boolean | null;
    label_model: Label | null;
    label_manual: Label | null;
    label_efektif: Label | null;
    perlu_review: boolean;
    model_versi: string | null;
    provider: string | null;
    reason_code: string | null;
    reason_summary: string | null;
    evidence: string[] | null;
    catatan_koreksi: string | null;
    dikoreksi_at: string | null;
    pengoreksi: { id: number; name: string } | null;
}

const props = defineProps<{
    artikel: {
        id: number;
        judul: string;
        url: string;
        isi: string | null;
        penulis: string | null;
        jumlah_kata: number | null;
        dipublikasikan_at: string | null;
        diambil_at: string;
        status_proses: string;
        media: { id: number; nama: string } | null;
        sumber_feed: { id: number; nama: string } | null;
        analisis_sentimen: Analisis[];
    };
}>();

const { formatAngka } = useFormatAngka();

const sedangKoreksi = ref<number | null>(null);

const form = useForm({ label_manual: null as Label | null, catatan_koreksi: '' });

function mulaiKoreksi(analisis: Analisis) {
    sedangKoreksi.value = analisis.id;
    form.label_manual = analisis.label_manual;
    form.catatan_koreksi = analisis.catatan_koreksi ?? '';
}

function simpan(analisis: Analisis) {
    form.put(`/admin/analisis/${analisis.id}`, {
        preserveScroll: true,
        onSuccess: () => (sedangKoreksi.value = null),
    });
}

function cabut(analisis: Analisis) {
    form.label_manual = null;
    form.catatan_koreksi = '';
    simpan(analisis);
}

/**
 * Koreksi manusia bisa dicabut, dan mencabutnya berarti menilai ulang.
 *
 * Sentimen menyimpan `label_model` dan `label_manual` terpisah, jadi mencabut
 * koreksi sentimen saja akan memunculkan kembali putusan AI dengan sendirinya.
 * Relevansi tidak: kolomnya cuma satu dan sudah ditimpa keputusan admin,
 * sehingga putusan AI yang lama memang tidak tersimpan di mana pun.
 */
const konfirmasiReset = ref(false);

const sedangReset = ref(false);

function reset() {
    konfirmasiReset.value = false;
    sedangReset.value = true;

    router.post(
        `/admin/artikel/${props.artikel.id}/reset`,
        {},
        // Sama seperti di halaman daftar: tombolnya sudah terkunci dengan ikon
        // berputar, jadi bilah progres bawaan Inertia hanya menambah penanda
        // kedua untuk satu pekerjaan yang sama.
        { preserveScroll: true, showProgress: false, onFinish: () => (sedangReset.value = false) },
    );
}

const adaKoreksi = (analisis: Analisis) => analisis.relevan_manual !== null || analisis.label_manual !== null;

/**
 * Klasifikasi ulang dari halaman ini, dua jalur yang sama dengan halaman daftar.
 *
 * Rute dan validasinya sudah ada sejak tombol serupa dipasang di halaman daftar
 * (`in:gemini,indobert` di ArtikelController), jadi yang ditambahkan di sini
 * hanya jalan masuknya. Halaman detail justru tempat paling wajar untuk menekan
 * tombol ini: admin sampai ke sini karena sedang memeriksa satu putusan yang
 * mencurigakan, dan sebelumnya ia harus kembali ke daftar hanya untuk menilai
 * ulang artikel yang sedang dibacanya.
 *
 * Berbeda dari Reset, keduanya tidak memakai dialog konfirmasi. Reset menghapus
 * koreksi manusia yang tidak tersimpan di mana pun, sedangkan ini menimpa
 * putusan AI dengan putusan AI yang baru. Yang hilang cuma hasil yang memang
 * sedang diragukan.
 */
type JalurKlasifikasi = 'gemini' | 'indobert';

const sedangJalur = ref<JalurKlasifikasi | null>(null);

/**
 * Satu penanda untuk seluruh aksi AI di halaman ini.
 *
 * Reset dan kedua tombol klasifikasi memanggil Gemini lewat jalur yang sama dan
 * memakai kuota yang sama. Membiarkan tombol lain tetap bisa ditekan selama
 * salah satunya berjalan berarti dua permintaan untuk artikel yang sama, dan
 * yang datang belakangan menimpa yang duluan tanpa ada yang tahu urutannya.
 */
const sibuk = computed(() => sedangReset.value || sedangJalur.value !== null);

function klasifikasi(jalur: JalurKlasifikasi) {
    sedangJalur.value = jalur;

    router.post(
        `/admin/artikel/${props.artikel.id}/klasifikasi`,
        { jalur },
        { preserveScroll: true, showProgress: false, onFinish: () => (sedangJalur.value = null) },
    );
}

/**
 * Status mentah tidak layak dibaca manusia.
 *
 * `tidak_relevan` dan `perlu_review` adalah nama kolom, bukan kalimat. Yang
 * tampil di lencana harus sama dengan nama tahap di halaman daftar, supaya
 * admin tahu di tab mana artikel ini akan ditemukannya lagi.
 *
 * Nadanya menempel di keadaan proses, bukan di nada berita. Selesai memakai
 * hijau karena ia pekerjaan yang tuntas, menunggu review memakai kuning karena
 * ia menunggu keputusan manusia, dan gagal memakai merah. Ronanya diambil dari
 * PilKop, satu-satunya tempat yang mengurus warna di atas navy kop.
 */
const gayaProses: Record<string, { teks: string; nada: 'netral' | 'baik' | 'tunggu' | 'buruk' }> = {
    mentah: { teks: 'Belum diklasifikasi', nada: 'netral' },
    isi_diambil: { teks: 'Belum diklasifikasi', nada: 'netral' },
    perlu_review: { teks: 'Menunggu review', nada: 'tunggu' },
    dianalisis: { teks: 'Selesai', nada: 'baik' },
    selesai: { teks: 'Selesai', nada: 'baik' },
    tidak_relevan: { teks: 'Selesai', nada: 'baik' },
    gagal: { teks: 'Gagal', nada: 'buruk' },
};

const proses = computed(() => gayaProses[props.artikel.status_proses] ?? { teks: props.artikel.status_proses, nada: 'netral' as const });

/**
 * Tidak relevan diwarnai abu, bukan merah.
 *
 * Versi sebelumnya memakai rose untuk "tidak relevan" dan itu meminjam warna
 * yang di seluruh aplikasi berarti nada negatif. Artikel yang tidak membahas
 * Pemkot bukan berita buruk, ia cuma di luar cakupan. Toska dipakai untuk
 * relevan, sama dengan halaman daftar berita, sehingga satu warna berarti satu
 * hal di kedua layar.
 */
const tileRelevansi = (relevan: boolean) => (relevan ? 'bg-aksen-toska text-white dark:text-background' : 'bg-muted-foreground/80 text-background');

/** Tombol koreksi memakai warna nada yang diwakilinya, plus ikon yang sama dengan lencana sentimen. */
const pilihanLabel: Array<{ nilai: Label; ikon: typeof TrendingUp; aktif: string; diam: string }> = [
    {
        nilai: 'negatif',
        ikon: TrendingDown,
        aktif: 'border-transparent bg-sentimen-negatif text-white dark:text-background',
        diam: 'border-sentimen-negatif/30 text-sentimen-negatif hover:bg-sentimen-negatif-lembut',
    },
    {
        nilai: 'netral',
        ikon: Minus,
        aktif: 'border-transparent bg-sentimen-netral text-white dark:text-background',
        diam: 'border-sentimen-netral/30 text-sentimen-netral hover:bg-sentimen-netral-lembut',
    },
    {
        nilai: 'positif',
        ikon: TrendingUp,
        aktif: 'border-transparent bg-sentimen-positif text-white dark:text-background',
        diam: 'border-sentimen-positif/30 text-sentimen-positif hover:bg-sentimen-positif-lembut',
    },
];

const waktu = (nilai: string | null) => (nilai ? format(new Date(nilai), 'd MMMM yyyy, HH:mm', { locale: id }) : '-');

/** Baris keterangan di kop. Ikon dipakai supaya matanya bisa melompat tanpa membaca labelnya. */
const keterangan = computed(() => [
    { ikon: Building2, label: 'Media', nilai: props.artikel.media?.nama ?? 'Belum ditautkan' },
    { ikon: PenLine, label: 'Penulis', nilai: props.artikel.penulis ?? '-' },
    { ikon: FileText, label: 'Kata', nilai: formatAngka(props.artikel.jumlah_kata) },
    { ikon: CalendarDays, label: 'Terbit', nilai: waktu(props.artikel.dipublikasikan_at) },
    { ikon: DownloadCloud, label: 'Diambil', nilai: waktu(props.artikel.diambil_at) },
    { ikon: Rss, label: 'Sumber', nilai: props.artikel.sumber_feed?.nama ?? '-' },
]);

const tersalin = ref(false);

/**
 * Judul dan isi disalin sekaligus, dipisah baris kosong.
 *
 * Yang ditempel pengguna hampir selalu artikel utuh, bukan potongannya, jadi dua
 * tombol terpisah cuma memaksa dua kali klik dan dua kali pindah aplikasi.
 */
async function salin() {
    await navigator.clipboard.writeText([props.artikel.judul, props.artikel.isi].filter(Boolean).join('\n\n'));
    tersalin.value = true;
    setTimeout(() => (tersalin.value = false), 1500);
}
</script>

<template>
    <Head :title="artikel.judul" />

    <LayoutAdmin
        :breadcrumbs="[
            { title: 'Artikel', href: '/admin/artikel' },
            { title: 'Detail', href: '#' },
        ]"
    >
        <!-- Tombol kembali, bukan hanya breadcrumb. Halaman ini dibuka dari
             tombol Lihat di dalam toast, jadi pengguna sering sampai di sini
             tanpa pernah melewati daftar artikel dan tidak punya jalan pulang
             yang jelas. -->
        <Link
            href="/admin/artikel"
            class="group inline-flex items-center gap-1.5 rounded-md text-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
        >
            <ArrowLeft class="size-4 transition-transform duration-200 group-hover:-translate-x-0.5 motion-reduce:transition-none" />
            Kembali ke daftar artikel
        </Link>

        <!--
            Kop artikel memakai navy merek, bukan kartu putih seperti sisanya.

            Halaman ini punya satu subjek tunggal dan seluruh panel di bawahnya
            berbicara tentang subjek itu. Bidang gelap di puncak halaman
            menjadikan judulnya jangkar yang tidak bisa tertukar dengan kartu
            data, dan sekaligus membawa warna merek ke layar yang selama ini
            seluruhnya abu dan putih.
        -->
        <KopHalaman :judul="artikel.judul">
            <template #aksi>
                <!-- Tombol utama halaman ini: membuka sumbernya. Verifikasi
                     admin selalu berakhir di halaman aslinya, jadi itu yang
                     mendapat bidang penuh. -->
                <a
                    :href="artikel.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="tekan group inline-flex items-center gap-2 rounded-lg bg-white px-3.5 py-2 text-xs font-semibold text-brand transition-colors hover:bg-white/90 focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-brand focus-visible:outline-hidden"
                >
                    Buka halaman aslinya
                    <ExternalLink
                        class="size-3.5 transition-transform duration-200 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 motion-reduce:transition-none"
                        aria-hidden="true"
                    />
                </a>

                <button
                    type="button"
                    class="tekan inline-flex items-center gap-2 rounded-lg bg-white/10 px-3.5 py-2 text-xs font-medium text-white ring-1 ring-white/25 transition-colors ring-inset hover:bg-white/20 focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-brand focus-visible:outline-hidden"
                    @click="salin"
                >
                    <component :is="tersalin ? Check : Copy" class="size-3.5" aria-hidden="true" />
                    {{ tersalin ? 'Tersalin' : 'Salin artikel' }}
                </button>
            </template>

            <PilKop :nada="proses.nada" :ikon="ScanSearch">{{ proses.teks }}</PilKop>
            <PilKop v-if="artikel.media" :ikon="Building2">{{ artikel.media.nama }}</PilKop>

            <template #bawah>
                <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-2 text-xs sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="item in keterangan" :key="item.label" class="flex items-center gap-2 text-white/75">
                        <component :is="item.ikon" class="size-3.5 shrink-0 text-white/50" aria-hidden="true" />
                        <dt class="sr-only">{{ item.label }}</dt>
                        <dd class="truncate" :class="item.label === 'Kata' ? 'angka' : ''" :title="item.nilai">
                            <span class="text-white/50">{{ item.label }}</span>
                            <span class="mx-1.5 text-white/25">/</span>
                            <span class="font-medium text-white">{{ item.nilai }}</span>
                        </dd>
                    </div>
                </dl>
            </template>
        </KopHalaman>

        <div class="grid gap-4 lg:grid-cols-5">
            <div class="space-y-4 lg:col-span-3">
                <Card v-if="artikel.isi" class="muncul overflow-hidden" style="animation-delay: 90ms">
                    <CardHeader class="flex-row items-center justify-between gap-3 space-y-0 border-b bg-muted/30 py-3">
                        <CardTitle class="text-sm font-semibold">Isi hasil ekstraksi</CardTitle>
                        <span class="angka rounded-full bg-background px-2 py-0.5 text-[11px] text-muted-foreground ring-1 ring-border ring-inset">
                            {{ formatAngka(artikel.jumlah_kata) }} kata
                        </span>
                    </CardHeader>

                    <!--
                        Bayangan pudar di kaki bidang gulir. Teks yang terpotong
                        rata di tepi kartu terbaca seperti sudah habis, dan admin
                        berhenti menggulir padahal artikelnya masih separuh.
                    -->
                    <CardContent class="relative p-0">
                        <p class="max-h-112 overflow-y-auto px-5 py-4 text-sm leading-[1.75] text-pretty whitespace-pre-line">
                            {{ artikel.isi }}
                        </p>
                        <div
                            class="pointer-events-none absolute inset-x-0 bottom-0 h-10 bg-linear-to-t from-card to-transparent"
                            aria-hidden="true"
                        ></div>
                    </CardContent>
                </Card>

                <Card v-else class="muncul" style="animation-delay: 90ms">
                    <CardContent class="flex flex-col items-center gap-2 py-10 text-center">
                        <div class="grid size-10 place-items-center rounded-full bg-muted text-muted-foreground">
                            <FileText class="size-5" aria-hidden="true" />
                        </div>
                        <p class="text-sm font-medium">Isi artikel belum terekstrak</p>
                        <p class="max-w-xs text-xs text-muted-foreground">
                            Crawler sudah mencatat tautannya, tapi teksnya belum berhasil diambil. Tanpa teks, artikel ini tidak bisa dinilai.
                        </p>
                    </CardContent>
                </Card>
            </div>

            <div class="lg:col-span-2">
                <Card class="muncul lg:sticky lg:top-4" style="animation-delay: 160ms">
                    <CardHeader class="flex-row items-center gap-2 space-y-0 border-b py-3">
                        <div class="grid size-7 place-items-center rounded-md bg-aksen-biru/10 text-aksen-biru">
                            <Radar class="size-4" aria-hidden="true" />
                        </div>
                        <CardTitle class="text-sm font-semibold">Putusan sistem</CardTitle>
                    </CardHeader>

                    <CardContent class="pt-4">
                        <div v-if="!artikel.analisis_sentimen.length" class="flex flex-col items-center gap-2 py-6 text-center">
                            <div class="grid size-10 place-items-center rounded-full bg-muted text-muted-foreground">
                                <ScanSearch class="size-5" aria-hidden="true" />
                            </div>
                            <p class="text-sm font-medium">Belum diklasifikasi</p>
                            <p class="max-w-[26ch] text-xs text-muted-foreground">
                                Jalankan penilaiannya lewat tombol di halaman Antrean Klasifikasi.
                            </p>
                        </div>

                        <!--
                            Putusan dibaca sebagai rangkaian, bukan tumpukan
                            kartu di dalam kartu.

                            Relevansi menentukan apakah sentimen pernah dinilai,
                            dan koreksi manusia menimpa keduanya. Rel bertitik
                            menampilkan urutan sebab akibat itu sebagai satu
                            garis, sehingga admin melihat di langkah mana
                            keputusan berhenti tanpa harus membaca kalimatnya.
                        -->
                        <ol v-for="analisis in artikel.analisis_sentimen" :key="analisis.id" class="space-y-0">
                            <li class="rel relative pb-5 pl-9">
                                <span
                                    class="absolute top-0 left-0 grid size-7 place-items-center rounded-full"
                                    :class="tileRelevansi(analisis.relevan)"
                                >
                                    <component :is="analisis.relevan ? Check : Minus" class="size-4" aria-hidden="true" />
                                </span>

                                <p class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Relevansi</p>
                                <p class="mt-0.5 text-sm font-semibold">
                                    {{ analisis.relevan ? 'Relevan dengan Pemkot Kendari' : 'Di luar cakupan Pemkot Kendari' }}
                                </p>
                                <p v-if="analisis.provider" class="mt-1 text-xs text-muted-foreground">Dinilai {{ analisis.provider }}.</p>
                            </li>

                            <li class="rel relative pb-5 pl-9">
                                <span
                                    class="absolute top-0 left-0 grid size-7 place-items-center rounded-full"
                                    :class="analisis.relevan ? 'bg-brand-lembut text-brand dark:text-white' : 'bg-muted text-muted-foreground'"
                                >
                                    <TrendingUp class="size-4" aria-hidden="true" />
                                </span>

                                <p class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Sentimen</p>

                                <template v-if="analisis.relevan">
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                        <BadgeSentimen :label="analisis.label_efektif" :perlu-review="analisis.perlu_review" />
                                        <span
                                            v-if="analisis.perlu_review"
                                            class="denyut size-1.5 rounded-full bg-sentimen-review"
                                            aria-hidden="true"
                                        ></span>
                                    </div>
                                    <p class="mt-1.5 text-xs text-muted-foreground">
                                        Analisis otomatis: cenderung {{ analisis.label_model ?? 'belum diputuskan'
                                        }}<span v-if="analisis.perlu_review">, ditandai untuk diperiksa manusia</span>.
                                        <template v-if="analisis.model_versi"> Dinilai {{ analisis.model_versi }}.</template>
                                    </p>
                                </template>

                                <p v-else class="mt-0.5 text-xs text-muted-foreground">
                                    Tidak dinilai sentimennya. Artikel yang tidak membahas Pemkot tidak punya sentimen terhadap Pemkot.
                                </p>
                            </li>

                            <!--
                                Alasan dan kutipan bukti, bukan angka skor. Gemini tidak
                                mengeluarkan probabilitas, ia menunjuk kalimat di artikel.
                                Kutipan di bawah sudah diverifikasi ada di isi artikel;
                                yang tidak lolos verifikasi tidak pernah menjadi label.
                            -->
                            <li v-if="analisis.reason_summary" class="rel relative pb-5 pl-9">
                                <span class="absolute top-0 left-0 grid size-7 place-items-center rounded-full bg-aksen-biru/10 text-aksen-biru">
                                    <Quote class="size-4" aria-hidden="true" />
                                </span>

                                <p class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Alasan</p>
                                <p class="mt-0.5 text-xs leading-relaxed">{{ analisis.reason_summary }}</p>

                                <details v-if="analisis.evidence?.length" class="group mt-2">
                                    <summary
                                        class="inline-flex cursor-pointer list-none items-center gap-1 rounded text-xs font-medium text-aksen-biru transition-colors hover:text-brand focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
                                    >
                                        <ChevronRight
                                            class="size-3.5 transition-transform duration-200 group-open:rotate-90 motion-reduce:transition-none"
                                            aria-hidden="true"
                                        />
                                        Kutipan bukti ({{ analisis.evidence.length }})
                                    </summary>

                                    <ul class="mt-2 space-y-1.5 border-l border-aksen-biru/30 pl-3">
                                        <li
                                            v-for="(kutipan, i) in analisis.evidence"
                                            :key="i"
                                            class="text-xs leading-relaxed text-muted-foreground italic"
                                        >
                                            &ldquo;{{ kutipan }}&rdquo;
                                        </li>
                                    </ul>
                                </details>
                            </li>

                            <li class="rel rel-akhir relative pl-9">
                                <span
                                    class="absolute top-0 left-0 grid size-7 place-items-center rounded-full"
                                    :class="adaKoreksi(analisis) ? 'bg-aksen-ungu text-white dark:text-background' : 'bg-muted text-muted-foreground'"
                                >
                                    <UserRoundCheck class="size-4" aria-hidden="true" />
                                </span>

                                <p class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Koreksi manusia</p>

                                <p v-if="analisis.label_manual" class="mt-0.5 text-xs leading-relaxed">
                                    Diubah menjadi <strong class="capitalize">{{ analisis.label_manual }}</strong> oleh
                                    {{ analisis.pengoreksi?.name ?? '-' }}, {{ waktu(analisis.dikoreksi_at) }}.
                                    <span v-if="analisis.catatan_koreksi" class="text-muted-foreground"
                                        >&ldquo;{{ analisis.catatan_koreksi }}&rdquo;</span
                                    >
                                </p>
                                <p v-else-if="!adaKoreksi(analisis)" class="mt-0.5 text-xs text-muted-foreground">
                                    Belum ada. Putusan di atas sepenuhnya hasil model.
                                </p>

                                <!-- Koreksi hanya masuk akal untuk artikel yang
                                     relevan. Yang di luar cakupan tidak punya
                                     nada untuk dikoreksi. -->
                                <template v-if="analisis.relevan">
                                    <div v-if="sedangKoreksi === analisis.id" class="mt-3 space-y-2 rounded-lg bg-muted/40 p-3">
                                        <div class="grid grid-cols-3 gap-1.5">
                                            <button
                                                v-for="pilihan in pilihanLabel"
                                                :key="pilihan.nilai"
                                                type="button"
                                                class="tekan inline-flex items-center justify-center gap-1 rounded-md border px-2 py-1.5 text-xs font-medium capitalize transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
                                                :class="form.label_manual === pilihan.nilai ? pilihan.aktif : pilihan.diam"
                                                :aria-pressed="form.label_manual === pilihan.nilai"
                                                @click="form.label_manual = pilihan.nilai"
                                            >
                                                <component :is="pilihan.ikon" class="size-3.5" aria-hidden="true" />
                                                {{ pilihan.nilai }}
                                            </button>
                                        </div>

                                        <Input v-model="form.catatan_koreksi" placeholder="Alasan koreksi" class="h-8 bg-background text-xs" />

                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <Button size="sm" class="h-7 text-xs" :disabled="form.processing" @click="simpan(analisis)">
                                                <Loader2
                                                    v-if="form.processing"
                                                    class="size-3 animate-spin motion-reduce:animate-none"
                                                    aria-hidden="true"
                                                />
                                                Simpan koreksi
                                            </Button>
                                            <Button size="sm" variant="ghost" class="h-7 text-xs" @click="sedangKoreksi = null">Batal</Button>
                                            <Button
                                                v-if="analisis.label_manual"
                                                size="sm"
                                                variant="ghost"
                                                class="h-7 text-xs text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                @click="cabut(analisis)"
                                            >
                                                Cabut koreksi
                                            </Button>
                                        </div>
                                    </div>

                                    <div v-else class="mt-3 flex flex-wrap items-center gap-1.5">
                                        <button
                                            type="button"
                                            class="tekan inline-flex items-center gap-1.5 rounded-md border border-brand/30 px-2.5 py-1.5 text-xs font-medium text-brand transition-colors hover:bg-brand-lembut focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden dark:border-white/25 dark:text-white dark:hover:bg-white/10"
                                            @click="mulaiKoreksi(analisis)"
                                        >
                                            <PenLine class="size-3.5" aria-hidden="true" />
                                            {{ analisis.label_manual ? 'Ubah koreksi' : 'Koreksi label' }}
                                        </button>

                                        <!-- Reset hanya muncul kalau memang ada yang bisa
                                             dicabut. Tombol yang selalu tampil mengundang
                                             klik pada artikel yang tidak pernah dikoreksi,
                                             dan itu berarti satu panggilan Gemini terbuang
                                             untuk mengulang hasil yang sudah ada. Ungu
                                             dipakai di seluruh aplikasi untuk aksi yang
                                             memanggil ulang AI dan memakan kuota. -->
                                        <button
                                            v-if="adaKoreksi(analisis)"
                                            type="button"
                                            class="tekan inline-flex items-center gap-1.5 rounded-md border border-aksen-ungu/40 px-2.5 py-1.5 text-xs font-medium text-aksen-ungu transition-colors hover:bg-aksen-ungu/10 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:opacity-50"
                                            :disabled="sibuk"
                                            @click="konfirmasiReset = true"
                                        >
                                            <Loader2 v-if="sedangReset" class="size-3.5 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                                            <ScanSearch v-else class="size-3.5" aria-hidden="true" />
                                            {{ sedangReset ? 'Menilai ulang...' : 'Reset dan nilai ulang' }}
                                        </button>
                                    </div>
                                </template>
                            </li>
                        </ol>
                    </CardContent>

                    <!-- Di luar CardContent, bukan di dalamnya, dan ada dua
                         alasannya.
                         Pertama, CardContent membawa padding `p-6`, sehingga
                         garis pemisah di dalamnya berhenti sebelum tepi kartu
                         dan terbaca sebagai kotak yang salah ukuran. Kedua, di
                         sini ia berada di luar cabang `v-if` daftar putusan,
                         jadi artikel yang belum pernah diklasifikasi pun tetap
                         punya tombolnya. Itu justru artikel yang paling butuh. -->
                    <div class="space-y-2 border-t bg-muted/20 p-4">
                        <p class="text-xs font-medium text-muted-foreground">Klasifikasi ulang</p>

                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <button
                                type="button"
                                class="tekan inline-flex items-center justify-center gap-1.5 rounded-md border border-aksen-ungu/40 px-2.5 py-2 text-xs font-medium text-aksen-ungu transition-colors hover:bg-aksen-ungu/10 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:opacity-50"
                                :disabled="sibuk"
                                @click="klasifikasi('gemini')"
                            >
                                <Loader2
                                    v-if="sedangJalur === 'gemini'"
                                    class="size-3.5 animate-spin motion-reduce:animate-none"
                                    aria-hidden="true"
                                />
                                <Sparkles v-else class="size-3.5" aria-hidden="true" />
                                {{ sedangJalur === 'gemini' ? 'Menilai...' : 'Gemini penuh' }}
                            </button>

                            <button
                                type="button"
                                class="tekan inline-flex items-center justify-center gap-1.5 rounded-md border border-aksen-biru/40 px-2.5 py-2 text-xs font-medium text-aksen-biru transition-colors hover:bg-aksen-biru/10 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:opacity-50"
                                :disabled="sibuk"
                                @click="klasifikasi('indobert')"
                            >
                                <Loader2
                                    v-if="sedangJalur === 'indobert'"
                                    class="size-3.5 animate-spin motion-reduce:animate-none"
                                    aria-hidden="true"
                                />
                                <Radar v-else class="size-3.5" aria-hidden="true" />
                                {{ sedangJalur === 'indobert' ? 'Menilai...' : 'IndoBERT + Gemini' }}
                            </button>
                        </div>

                        <p class="text-xs text-muted-foreground">
                            Gemini penuh menilai relevansi dan sentimen sekaligus. IndoBERT menentukan relevansinya lebih dulu, dan Gemini hanya
                            dipanggil bila beritanya relevan, sehingga lebih hemat kuota.
                        </p>
                    </div>
                </Card>
            </div>
        </div>

        <Dialog :open="konfirmasiReset" @update:open="(buka) => (konfirmasiReset = buka)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Cabut koreksi dan nilai ulang?</DialogTitle>
                    <DialogDescription>
                        Koreksi manusia pada artikel ini dihapus, lalu Gemini menilainya kembali dari nol. Satu permintaan akan terkirim dan memakai
                        kuota.
                    </DialogDescription>
                </DialogHeader>

                <p class="rounded-md bg-muted/50 p-3 text-sm font-medium">{{ artikel.judul }}</p>

                <p class="text-xs text-muted-foreground">
                    Hasilnya bisa berbeda dari penilaian AI sebelumnya, karena yang dijalankan penilaian baru, bukan pemulihan yang lama.
                </p>

                <DialogFooter>
                    <Button variant="outline" @click="konfirmasiReset = false">Batal</Button>
                    <Button class="bg-aksen-ungu text-white hover:bg-aksen-ungu/90 dark:text-background" @click="reset">Cabut dan nilai ulang</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </LayoutAdmin>
</template>

<style scoped>
/*
 * Rel penghubung antar langkah putusan.
 *
 * Digambar sebagai pseudo elemen, bukan div, supaya tidak ada satu pun simpul
 * kosong di dalam daftar yang dibacakan pembaca layar. Garisnya berhenti di
 * langkah terakhir, kalau tidak ia menjuntai ke bawah tanpa tujuan.
 *
 * Tingginya dianimasikan dari nol saat halaman terbuka, jadi rel terbaca
 * mengalir dari relevansi ke koreksi mengikuti urutan sebab akibatnya.
 */
.rel::before {
    content: '';
    position: absolute;
    left: 0.8125rem;
    top: 1.875rem;
    bottom: 0.375rem;
    width: 1px;
    background: linear-gradient(180deg, hsl(var(--border)) 0%, hsl(var(--border) / 0.35) 100%);
    transform-origin: top;
    animation: rel-turun 700ms cubic-bezier(0.32, 0.72, 0, 1) both;
    animation-delay: 260ms;
}

.rel-akhir::before {
    display: none;
}

@keyframes rel-turun {
    from {
        transform: scaleY(0);
    }

    to {
        transform: scaleY(1);
    }
}

@media (prefers-reduced-motion: reduce) {
    .rel::before {
        animation: none;
    }
}
</style>
