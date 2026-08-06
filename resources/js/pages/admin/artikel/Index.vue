<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
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
import { ExternalLink, Loader2, RotateCcw, Sparkles, ThumbsDown, ThumbsUp, Trash2, UserPen } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type LabelSentimen = 'negatif' | 'netral' | 'positif';

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
    dianalisis_at: string | null;
}

interface BarisArtikel {
    id: number;
    judul: string;
    url: string;
    media: string | null;
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
    { kunci: 'aksi', judul: '', lebar: 'w-40' },
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
 * Jeda antar penilaian manual, dihitung mundur di layar.
 *
 * Satu klik adalah satu sampai dua permintaan Gemini yang dihitung penuh oleh
 * Google, dan kuotanya kuota yang sama dengan yang dipakai antrean otomatis.
 * Tanpa jeda, admin yang menyapu daftar dengan klik beruntun bisa menghabiskan
 * jatah harian dalam beberapa menit, dan antrean latar belakang berhenti
 * sampai tengah malam waktu Pasifik tanpa ada yang tahu sebabnya.
 *
 * Penegakan yang sebenarnya ada di server. Tombol yang diredupkan bukan aturan,
 * permintaannya tetap bisa dikirim langsung.
 */
const JEDA_DETIK = 15;

const jeda = ref(0);

setInterval(() => {
    if (jeda.value > 0) jeda.value--;
}, 1000);

/** Terkunci selama satu penilaian berjalan, dan selama jeda setelahnya. */
const terkunci = computed(() => sedangJalan.value !== null || jeda.value > 0);

const opsiAksi = {
    preserveScroll: true,
    showProgress: false,
    onFinish: () => {
        sedangJalan.value = null;
        jeda.value = JEDA_DETIK;
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

const semuaTercentang = computed(
    () => idHalamanIni.value.length > 0 && terpilih.value.length === idHalamanIni.value.length,
);

function alihkanSemua() {
    terpilih.value = semuaTercentang.value ? [] : [...idHalamanIni.value];
}

function alihkanSatu(id: number) {
    terpilih.value = terpilih.value.includes(id)
        ? terpilih.value.filter((satu) => satu !== id)
        : [...terpilih.value, id];
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

/** Baris yang sudah pernah dinilai dan tombolnya baru saja ditekan lagi. */
const konfirmasi = ref<BarisArtikel | null>(null);

function klasifikasi(baris: BarisArtikel) {
    // Sudah ada hasilnya. Jangan langsung menembak Gemini lagi: admin biasanya
    // menekan tombol karena mengira belum jalan, bukan karena ingin mengulang.
    if (baris.analisis !== null) {
        konfirmasi.value = baris;

        return;
    }

    jalankan(baris.id);
}

function jalankan(id: number) {
    konfirmasi.value = null;
    sedangJalan.value = id;

    router.post(`/admin/artikel/${id}/klasifikasi`, {}, opsiAksi);
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

/**
 * Warna badge, dipakai bersama filter dan tabel.
 *
 * `variant="outline"` wajib menyertainya. Varian bawaan Badge membawa
 * `hover:bg-primary/80`, dan tailwind-merge mempertahankannya karena `bg-` dan
 * `hover:bg-` adalah dua grup properti yang berbeda. Akibatnya badge berwarna
 * berubah menjadi gelap begitu kursor lewat, seolah ia tombol yang bisa
 * ditekan padahal bukan.
 */
const warnaRelevansi = (relevan: boolean) =>
    relevan
        ? 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
        : 'border-transparent bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300';

const warnaSentimen: Record<LabelSentimen, string> = {
    positif: 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    netral: 'border-transparent bg-slate-200 text-slate-800 dark:bg-slate-800 dark:text-slate-300',
    negatif: 'border-transparent bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
};

const { formatAngka } = useFormatAngka();

const kapital = (teks: string) => teks.charAt(0).toUpperCase() + teks.slice(1);

const waktu = (n: string) => format(new Date(n), 'd MMM yyyy', { locale: id });

const dari = ref(props.tanggal.dari ?? '');
const sampai = ref(props.tanggal.sampai ?? '');

watch([dari, sampai], ([d, s]) => pindah({ dari: d || null, sampai: s || null }));
</script>

<template>
    <Head title="Artikel" />

    <LayoutAdmin>
        <div class="space-y-4 p-4">
            <div>
                <h1 class="text-xl font-semibold">Artikel</h1>
                <p class="text-sm text-muted-foreground">
                    Berita masuk dinilai Gemini lewat tombol Klasifikasi, satu artikel satu klik. Yang dinilai: {{ pantauan }}.
                </p>
            </div>

            <!-- Dua kelompok tombol, relevansi di kiri dan tahap di kanan.
                 Keduanya filter, tetapi menjawab pertanyaan yang berbeda:
                 tahap menentukan sudah sampai mana artikelnya, relevansi
                 menentukan apa keputusannya. Menjejerkan keduanya dalam satu
                 baris membuat pasangan yang tidak masuk akal terlihat sendiri,
                 misalnya Belum diklasifikasi yang disaring Relevan. -->
            <div class="flex flex-wrap items-center justify-between gap-2">
                <!-- Placeholder kosong menjaga tahap tetap di kanan saat filter
                     relevansi disembunyikan. Tanpa ini justify-between menarik
                     kelompok tahap ke kiri, dan posisinya berpindah setiap kali
                     admin membuka Belum diklasifikasi. -->
                <div v-if="relevansiBisaDisaring" class="flex flex-wrap gap-1 rounded-lg bg-muted p-1">
                    <button
                        v-for="r in daftarRelevansi"
                        :key="r.nilai"
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                        :class="relevansi === r.nilai ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                        :aria-pressed="relevansi === r.nilai"
                        @click="pindah({ relevansi: r.nilai, sentimen: null })"
                    >
                        {{ r.label }}
                        <span class="angka ml-1 text-xs opacity-70">{{ formatAngka(r.jumlah) }}</span>
                    </button>
                </div>
                <div v-else />

                <!-- Sentimen di tengah, memakai warna yang sama dengan badge di
                     kolom Hasil AI supaya hijau di filter dan hijau di tabel
                     berarti hal yang sama. -->
                <div v-if="sentimenBisaDisaring" class="flex flex-wrap gap-1 rounded-lg bg-muted p-1">
                    <button
                        v-for="s in saringanSentimen"
                        :key="s.nilai"
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                        :class="sentimen === s.nilai ? warnaSentimen[s.nilai] + ' shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                        :aria-pressed="sentimen === s.nilai"
                        @click="pindah({ sentimen: sentimen === s.nilai ? null : s.nilai })"
                    >
                        {{ s.label }}
                    </button>
                </div>
                <div v-else />

                <div class="flex flex-wrap gap-1 rounded-lg bg-muted p-1">
                    <!-- Pindah tahap ikut membuang saringan relevansi. Kalau
                         tidak, membuka Belum diklasifikasi dari tab lain
                         menyembunyikan kontrolnya sementara filternya tetap
                         menyaring, dan tabel kosongnya terlihat seperti bug. -->
                    <button
                        v-for="t in daftarTahap"
                        :key="t.nilai"
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                        :class="tahap === t.nilai ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                        :aria-pressed="tahap === t.nilai"
                        @click="pindah({ tahap: t.nilai, relevansi: null, sentimen: null })"
                    >
                        {{ t.label }}
                        <span class="angka ml-1 text-xs opacity-70">{{ formatAngka(t.jumlah) }}</span>
                    </button>
                </div>
            </div>

            <DataTable
                :kolom="kolom"
                :data="artikel.data"
                :meta="artikel"
                pencarian
                url-basis="/admin/artikel"
                judul-kosong="Tidak ada berita pada tahap ini"
                keterangan-kosong="Pilih tahap lain di atas, atau tunggu crawler mengambil berita baru."
            >
                <template #aksi>
                    <Select v-model="mediaTerpilih">
                        <SelectTrigger class="h-8 w-44" aria-label="Saring menurut media">
                            <SelectValue placeholder="Semua media" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="SEMUA">Semua media</SelectItem>
                            <SelectItem v-for="m in opsiMedia" :key="m.nilai" :value="m.nilai">
                                {{ m.label }}
                            </SelectItem>
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

                        <Button
                            v-if="terpilih.length > 0"
                            size="sm"
                            variant="outline"
                            class="h-8"
                            :disabled="sedangBuang"
                            @click="buangTerpilih"
                        >
                            <Trash2 class="size-3.5" />
                            Buang {{ terpilih.length }} terpilih
                        </Button>

                        <Button
                            size="sm"
                            variant="destructive"
                            class="h-8"
                            :disabled="sedangBuang || pembuangan.jumlah === 0"
                            @click="buangSemua"
                        >
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
                    <Checkbox
                        :checked="terpilih.includes(baris.id)"
                        :aria-label="`Pilih ${baris.judul}`"
                        @update:checked="alihkanSatu(baris.id)"
                    />
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

                <template #sel-media="{ baris }">
                    <span class="text-sm text-muted-foreground">{{ baris.media ?? '-' }}</span>
                </template>

                <!-- Tanda hubung untuk tanggal terbit yang kosong, bukan
                     diisi tanggal masuk. Tidak semua feed mencantumkannya, dan
                     menyalin tanggal masuk ke sana membuat jeda yang justru
                     ingin dibaca menjadi selalu nol. -->
                <template #sel-tanggal="{ baris }">
                    <span class="text-sm whitespace-nowrap text-muted-foreground">
                        {{ baris.dipublikasikan_at ? waktu(baris.dipublikasikan_at) : '-' }}
                        <span class="opacity-40">/</span>
                        {{ waktu(baris.diambil_at) }}
                    </span>
                </template>

                <template #sel-hasil="{ baris }">
                    <div v-if="baris.analisis === null" class="text-sm text-muted-foreground">Belum dinilai</div>

                    <div v-else class="space-y-1.5">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <Badge variant="outline" :class="warnaRelevansi(baris.analisis.relevan)">
                                {{ baris.analisis.relevan ? 'Relevan' : 'Tidak relevan' }}
                            </Badge>

                            <!-- Sentimen hanya ditampilkan untuk artikel relevan. Baris yang
                                 pernah dinilai relevan lalu dikoreksi menjadi tidak relevan
                                 tetap menyimpan label lamanya, dan label itu sudah tidak
                                 dihitung agregasi mana pun. Menampilkannya membuat admin
                                 membaca angka yang tidak ada di dashboard. -->
                            <Badge
                                v-if="baris.analisis.relevan && baris.analisis.label_efektif"
                                variant="outline"
                                :class="warnaSentimen[baris.analisis.label_efektif]"
                            >
                                {{ kapital(baris.analisis.label_efektif) }}
                            </Badge>

                            <Badge v-if="baris.analisis.perlu_review" variant="outline"> Perlu review </Badge>

                            <!-- Penanda bahwa nilainya keputusan manusia, bukan Gemini.
                                 Tanpa ini admin tidak bisa membedakan baris yang sudah
                                 diperiksa dari baris yang kebetulan sependapat. -->
                            <Badge v-if="baris.analisis.relevan_manual !== null || baris.analisis.label_manual" variant="outline"> Dikoreksi </Badge>
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
                        <Button
                            size="sm"
                            class="bg-violet-600 text-white hover:bg-violet-700 disabled:opacity-70"
                            :disabled="terkunci"
                            @click="klasifikasi(baris)"
                        >
                            <Loader2 v-if="sedangJalan === baris.id" class="size-3.5 animate-spin" />
                            <Sparkles v-else class="size-3.5" />
                            <!-- Hitungan mundur tampil di seluruh baris, bukan
                                 hanya di baris yang barusan ditekan. Jedanya
                                 memang berlaku untuk seluruh tabel, dan tombol
                                 redup tanpa keterangan terbaca sebagai rusak. -->
                            <template v-if="sedangJalan === baris.id">Menilai</template>
                            <template v-else-if="jeda > 0">Tunggu {{ jeda }}s</template>
                            <template v-else>Klasifikasi</template>
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

                        <div v-if="bisaDiputuskan" class="flex gap-1">
                            <button
                                type="button"
                                class="inline-flex flex-1 items-center justify-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 transition-colors hover:bg-emerald-200 disabled:opacity-50 dark:bg-emerald-950 dark:text-emerald-300 dark:hover:bg-emerald-900"
                                :disabled="terkunci"
                                @click="tanyakan(baris, true)"
                            >
                                <ThumbsUp class="size-3" />
                                Relevan
                            </button>
                            <button
                                type="button"
                                class="inline-flex flex-1 items-center justify-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-800 transition-colors hover:bg-rose-200 disabled:opacity-50 dark:bg-rose-950 dark:text-rose-300 dark:hover:bg-rose-900"
                                :disabled="terkunci"
                                @click="tanyakan(baris, false)"
                            >
                                <ThumbsDown class="size-3" />
                                Tidak
                            </button>
                        </div>

                        <button
                            v-if="pembuangan.boleh"
                            type="button"
                            class="inline-flex items-center justify-center gap-1 rounded-md border px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-rose-50 hover:text-rose-600 disabled:opacity-50 dark:hover:bg-rose-950"
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

        <Dialog :open="konfirmasi !== null" @update:open="(buka) => !buka && (konfirmasi = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Artikel ini sudah dianalisis AI</DialogTitle>
                    <DialogDescription>
                        Gemini sudah menilai artikel ini
                        <template v-if="konfirmasi?.analisis?.dianalisis_at"> pada {{ waktu(konfirmasi.analisis.dianalisis_at) }} </template>
                        dan hasilnya sudah tampil di kolom Hasil AI.
                    </DialogDescription>
                </DialogHeader>

                <div v-if="konfirmasi?.analisis" class="space-y-2 rounded-md bg-muted/50 p-3 text-sm">
                    <div class="flex flex-wrap gap-1.5">
                        <Badge variant="outline" :class="warnaRelevansi(konfirmasi.analisis.relevan)">
                            {{ konfirmasi.analisis.relevan ? 'Relevan' : 'Tidak relevan' }}
                        </Badge>
                        <Badge
                            v-if="konfirmasi.analisis.relevan && konfirmasi.analisis.label_efektif"
                            variant="outline"
                            :class="warnaSentimen[konfirmasi.analisis.label_efektif]"
                        >
                            {{ kapital(konfirmasi.analisis.label_efektif) }}
                        </Badge>
                    </div>
                    <p v-if="konfirmasi.analisis.reason_summary" class="text-xs text-muted-foreground">
                        {{ konfirmasi.analisis.reason_summary }}
                    </p>
                </div>

                <p class="text-xs text-muted-foreground">
                    Menilai ulang mengirim permintaan baru ke Gemini dan memakai kuota. Koreksi manusia yang sudah tercatat tidak akan tertimpa.
                </p>

                <DialogFooter>
                    <Button variant="outline" @click="konfirmasi = null">Tutup</Button>
                    <Button class="bg-violet-600 text-white hover:bg-violet-700" @click="konfirmasi && jalankan(konfirmasi.id)"> Nilai ulang </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

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
                    <Button
                        :class="
                            konfirmasiRelevansi?.relevan
                                ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                                : 'bg-rose-600 text-white hover:bg-rose-700'
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
                    <Button class="bg-violet-600 text-white hover:bg-violet-700" @click="reset"> Cabut dan nilai ulang </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </LayoutAdmin>
</template>
