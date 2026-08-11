<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import IndikatorKesehatan from '@/components/domain/IndikatorKesehatan.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { Loader2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Nilai {
    label: string;
    nilai: string | number | null;
    env: string;
    diukur: string | null;
}

interface PengaturanAi {
    model: string;
    penyedia_relevansi: 'gemini' | 'indobert';
    versi_prompt_relevansi: string;
    prompt_relevansi: string;
    versi_prompt_sentimen: string;
    prompt_sentimen: string;
}

interface Kunci {
    id: number;
    label: string;
    aktif: boolean;
    limit_sampai: string | null;
    alasan_limit: string | null;
    terakhir_dipakai_at: string | null;
    galat_terakhir: string | null;
    galat_at: string | null;
    rpd_terpakai: number;
    rpd_google: number | null;
    rpd_google_at: string | null;
    rpd_manual: number | null;
    rpd_berlaku: number;
}

const props = defineProps<{
    pengaturanAi: PengaturanAi;
    kunci: Kunci[];
    resetHarian: string;
    kelompok: { judul: string; catatan: string | null; nilai: Nilai[] }[];
    layanan: { nama: string; sehat: boolean; url: string }[];
    evaluasi: { f1_macro: number; jumlah_sampel: number; dievaluasi_at: string } | null;
    modelRelevansiAktif: string | null;
}>();

const page = usePage<SharedData>();

const { formatAngka } = useFormatAngka();

const formAi = useForm({ ...props.pengaturanAi });
const formKunci = useForm({ label: '', kunci: '' });

function simpanAi() {
    formAi.put('/admin/pengaturan/ai', { preserveScroll: true });
}

/**
 * Dua penilai relevansi, dan hanya itu yang boleh tersimpan.
 *
 * Sentimen tidak ikut dipilih di sini karena memang tidak ada pilihannya.
 * IndoBERT hanya dilatih menilai relevan atau tidak, jadi nada berita selalu
 * dikerjakan Gemini betapapun opsi ini disetel.
 */
const penyediaRelevansi = [
    {
        nilai: 'gemini' as const,
        label: 'Gemini',
        keterangan: 'Menilai lewat prompt di bawah, menyertakan kutipan sebagai bukti, dan memakai kuota harian.',
    },
    {
        nilai: 'indobert' as const,
        label: 'IndoBERT',
        keterangan: 'Menilai di server sendiri tanpa kuota. Artikel yang ditolaknya tidak pernah dikirim ke Gemini.',
    },
];

/**
 * IndoBERT tidak bisa dipilih selama belum ada model yang diaktifkan.
 *
 * Cermin dari penolakan di PengaturanAiController, bukan penegakannya.
 * Permintaannya tetap bisa dikirim langsung, dan server yang menolaknya.
 */
const bisaIndoBert = computed(() => props.modelRelevansiAktif !== null);

function tambahKunci() {
    formKunci.post('/admin/pengaturan/kunci', {
        preserveScroll: true,
        onSuccess: () => formKunci.reset(),
    });
}

function ubahAktif(k: Kunci) {
    router.put(`/admin/pengaturan/kunci/${k.id}`, { aktif: !k.aktif }, { preserveScroll: true });
}

/**
 * Batas harian yang sedang diketik, per kunci.
 *
 * Disimpan terpisah dari `props.kunci` supaya ketikan yang belum disimpan tidak
 * ikut tertimpa saat Inertia memuat ulang halaman setelah aksi lain, misalnya
 * menguji kunci di baris sebelahnya.
 */
const batasKetikan = ref<Record<number, string>>({});

function batasHarian(k: Kunci): string {
    return batasKetikan.value[k.id] ?? (k.rpd_manual === null ? '' : String(k.rpd_manual));
}

/**
 * Kosong berarti kembali ke angka bawaan, bukan berarti nol.
 *
 * Nol akan diterima validasi sebagai angka lalu membuat penjaga kuota
 * menganggap kunci ini tidak punya jatah sama sekali.
 *
 * Dikirim sebagai string kosong, bukan null. Controller membedakan "kosongkan
 * batas" dari "ubah sakelar aktif" lewat ada tidaknya bidang `rpd_manual` di
 * badan permintaan, dan bidang bernilai null hilang seluruhnya saat badannya
 * dikodekan sebagai form. String kosong selalu sampai, dan middleware bawaan
 * Laravel mengubahnya menjadi null sebelum validasi membacanya.
 */
function simpanBatas(k: Kunci) {
    router.put(
        `/admin/pengaturan/kunci/${k.id}`,
        { rpd_manual: batasHarian(k).trim() },
        {
            preserveScroll: true,
            onSuccess: () => delete batasKetikan.value[k.id],
        },
    );
}

function hapusKunci(k: Kunci) {
    if (confirm(`Hapus kunci ${k.label}? Klasifikasi akan memakai kunci yang tersisa.`)) {
        router.delete(`/admin/pengaturan/kunci/${k.id}`, { preserveScroll: true });
    }
}

/** Kunci yang sedang diketuk, supaya tombolnya terkunci satu per satu. */
const sedangUji = ref<number | null>(null);

/**
 * Jeda 15 detik per kunci setelah diuji, dihitung mundur di layar.
 *
 * Penegakan yang sebenarnya ada di server, karena tombol yang diredupkan bukan
 * aturan dan permintaannya tetap bisa dikirim langsung. Yang di sini hanya
 * supaya admin melihat sisa waktunya alih-alih menekan tombol lalu menerima
 * penolakan yang terasa seperti kerusakan.
 */
const JEDA_UJI = 15;

const jedaUji = ref<Record<number, number>>({});

setInterval(() => {
    for (const [id, sisa] of Object.entries(jedaUji.value)) {
        if (sisa <= 1) delete jedaUji.value[Number(id)];
        else jedaUji.value[Number(id)] = sisa - 1;
    }
}, 1000);

/**
 * Hasil uji dibaca dari flash session, bukan disimpan di komponen.
 *
 * Menyegarkan halaman membuangnya, dan itu memang benar: hasil uji adalah
 * potret sesaat, dan potret yang bertahan setelah reload akan terbaca sebagai
 * keadaan kunci sekarang padahal bisa saja sudah berubah.
 */
const uji = computed(() => page.props.flash?.ujiKunci ?? null);

function ujiKunci(k: Kunci) {
    sedangUji.value = k.id;
    jedaUji.value[k.id] = JEDA_UJI;

    router.post(
        `/admin/pengaturan/kunci/${k.id}/uji`,
        {},
        {
            preserveScroll: true,
            showProgress: false,
            onFinish: () => (sedangUji.value = null),
        },
    );
}

const bisaDiuji = (k: Kunci) => sedangUji.value === null && !jedaUji.value[k.id];

const waktu = (iso: string) => format(new Date(iso), 'd MMM yyyy HH:mm', { locale: id });

const alasan: Record<string, string> = {
    kuota_harian: 'kuota harian habis',
    kuota_menit: 'kuota per menit habis',
    retry_delay: 'diminta menunggu oleh Google',
    // Ditahan hitungan sendiri, sebelum Google sempat menolak. Dibedakan dari
    // dua yang di atas supaya admin tahu kuncinya belum benar-benar ditolak.
    kuota_harian_lokal: 'batas harian tercapai, ditahan sendiri',
    kuota_menit_lokal: 'batas per menit tercapai, ditahan sendiri',
};

const jumlahAktif = computed(() => props.kunci.filter((k) => k.aktif).length);

/** Menyalakan selalu boleh. Mematikan hanya boleh selama masih ada kunci menyala lain. */
function bisaDimatikan(k: Kunci): boolean {
    return !k.aktif || jumlahAktif.value > 1;
}

/** Kunci yang sudah mati tidak mengurangi kunci menyala, jadi menghapusnya aman. */
function bisaDihapus(k: Kunci): boolean {
    return props.kunci.length > 1 && (!k.aktif || jumlahAktif.value > 1);
}

/**
 * Hasil uji menggantikan kotak galat terakhir untuk kunci yang sama.
 *
 * Uji yang gagal menulis galatnya ke kolom `galat_terakhir` kunci itu juga,
 * jadi tanpa penjaga ini kalimat galat yang sama muncul dua kali bertumpuk
 * persis setelah tombol Uji ditekan. Yang ditampilkan hasil ujinya, karena ia
 * yang baru saja diminta admin dan ia memuat lebih banyak keterangan.
 */
const adaHasilUji = (k: Kunci) => uji.value !== null && uji.value.id === k.id;

function status(k: Kunci): string {
    if (!k.aktif) return 'Dimatikan';
    if (k.limit_sampai) {
        return `Kena limit sampai ${waktu(k.limit_sampai)}` + (k.alasan_limit ? ` (${alasan[k.alasan_limit] ?? k.alasan_limit})` : '');
    }
    return 'Siap dipakai';
}
</script>

<template>
    <Head title="Pengaturan sistem" />

    <LayoutAdmin judul="Pengaturan sistem" :breadcrumbs="[{ title: 'Pengaturan', href: '/admin/pengaturan' }]">
        <div class="space-y-1 rounded-md border bg-muted/40 p-3 text-sm">
            <p class="font-medium">Hanya pengaturan Gemini yang bisa disunting di sini.</p>
            <p class="text-muted-foreground">
                Model, kedua prompt, dan daftar kunci API disetel dari layar ini karena keduanya perlu diperbaiki saat itu juga. Nilai lain di
                bawahnya hanya ditampilkan. Ambang tersebut mengubah setiap angka dashboard secara surut, termasuk untuk periode yang sudah dilaporkan
                ke pimpinan, jadi perubahannya lewat <code class="text-xs">.env</code> dan deploy supaya tercatat di git bersama alasannya. Kolom
                "diukur dari" ada supaya angka-angka ini tidak terbaca sebagai selera.
            </p>
        </div>

        <Card>
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">Layanan</CardTitle>
            </CardHeader>
            <CardContent class="space-y-2">
                <IndikatorKesehatan
                    v-for="l in props.layanan"
                    :key="l.nama"
                    :label="l.nama"
                    :status="l.sehat ? 'hijau' : 'merah'"
                    :keterangan="
                        l.sehat
                            ? l.url
                            : `Tidak menjawab di ${l.url}. Job yang membutuhkannya menumpuk di antrean dan jalan lagi setelah layanan hidup.`
                    "
                />
            </CardContent>
        </Card>

        <Card v-if="props.evaluasi">
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">Evaluasi model terakhir</CardTitle>
            </CardHeader>
            <CardContent class="text-sm">
                F1 macro <span class="angka font-medium">{{ props.evaluasi.f1_macro.toFixed(4) }}</span> dari
                {{ props.evaluasi.jumlah_sampel }} sampel gold set,
                {{ format(new Date(props.evaluasi.dievaluasi_at), 'd MMMM yyyy', { locale: id }) }}.
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">Kunci API Gemini</CardTitle>
                <p class="text-xs text-muted-foreground">
                    Satu kunci berarti satu kuota harian. Kunci yang kena limit ditandai beserta waktu pulihnya dan dilewati sampai waktu itu, jadi
                    kuota tidak habis untuk permintaan yang sudah pasti ditolak.
                </p>
            </CardHeader>
            <CardContent class="space-y-4">
                <!-- Waktu reset ditulis sekali di sini, bukan diulang di tiap
                     kunci. Google memulangkan jatah harian pada pergantian hari
                     kalender waktu Pasifik, jadi seluruh kunci pulih pada detik
                     yang sama betapapun berbedanya jam mereka kehabisan. Yang
                     memang berbeda per kunci adalah limit per menit, dan itu
                     ditempelkan pada kuncinya masing-masing di bawah. -->
                <div v-if="props.kunci.length > 0" class="rounded-md border bg-muted/40 px-3 py-2 text-xs">
                    <p>
                        <span class="text-muted-foreground">Kuota harian seluruh kunci reset bersamaan:</span>
                        <span class="angka font-medium">{{ waktu(props.resetHarian) }}</span>
                    </p>
                    <p class="mt-0.5 text-muted-foreground">
                        Tengah malam waktu Pasifik, jam kalender Google, bukan 24 jam setelah kunci mulai dipakai. Limit per menit pulih sendiri jauh
                        lebih cepat dan waktunya berbeda tiap kunci, jadi ditampilkan pada kuncinya masing-masing.
                    </p>
                </div>

                <p v-if="props.kunci.length === 0" class="text-sm text-muted-foreground">
                    Belum ada kunci di database, jadi klasifikasi tidak bisa dijalankan sampai ada satu kunci.
                </p>

                <ul v-else class="divide-y rounded-md border">
                    <li v-for="k in props.kunci" :key="k.id" class="space-y-2 px-3 py-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="space-y-0.5">
                                <p class="text-sm">{{ k.label }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ status(k) }}
                                    <template v-if="k.terakhir_dipakai_at"> · terakhir dipakai {{ waktu(k.terakhir_dipakai_at) }} </template>
                                </p>
                                <!-- Sisa kuota sengaja tidak ada di sini.
                                     Menghitungnya menuntut batas yang benar,
                                     dan batas yang benar hanya diketahui untuk
                                     kunci yang pernah kehabisan sampai Google
                                     menyebut angkanya di badan galat 429. Untuk
                                     kunci lain sisanya adalah pengurangan
                                     terhadap tebakan config, dan angka tebakan
                                     yang ditulis setegas angka fakta pernah
                                     berbunyi "497 sisa" untuk kunci yang detik
                                     itu juga ditolak karena kuotanya habis. -->
                                <!-- Batasnya selalu disebut beserta asalnya.
                                     Tiga sumber yang mungkin punya derajat yang
                                     berbeda jauh: angka Google adalah fakta,
                                     angka admin adalah keterangan yang bisa
                                     salah ketik, dan angka bawaan adalah
                                     salinan dokumentasi free tier yang meleset
                                     untuk kunci berbayar. Menuliskan ketiganya
                                     dengan kalimat yang sama membuat yang
                                     terakhir terbaca sepasti yang pertama. -->
                                <p class="text-xs text-muted-foreground">
                                    <span class="angka text-foreground">{{ formatAngka(k.rpd_terpakai) }}</span>
                                    permintaan terkirim hari ini · batas
                                    <span class="angka text-foreground">{{ formatAngka(k.rpd_berlaku) }}</span>
                                    <template v-if="k.rpd_google !== null">
                                        dari Google<template v-if="k.rpd_google_at">, {{ waktu(k.rpd_google_at) }}</template>
                                    </template>
                                    <template v-else-if="k.rpd_manual !== null"> yang Anda isi sendiri </template>
                                    <template v-else> bawaan free tier, Google belum pernah menyebut batas kunci ini </template>
                                </p>
                            </div>
                            <!-- Satu kunci harus selalu tersisa menyala. Tombol yang akan mematikan
                                 kunci terakhir tidak ditampilkan, karena menghentikan klasifikasi
                                 seluruh sistem bukan yang dimaksud siapa pun yang menekannya. -->
                            <div class="flex gap-2">
                                <!-- Menguji memakai kuota sungguhan, jadi tombolnya
                                     terkunci selama permintaannya berjalan supaya
                                     klik kedua tidak mengirim ketukan kedua. -->
                                <!-- Ketiganya diwarnai berbeda karena akibatnya
                                     berbeda jauh. Uji hanya memakai satu
                                     permintaan, Matikan menghentikan kunci ini
                                     dipakai, dan Hapus tidak bisa dibatalkan
                                     karena kuncinya tersimpan terenkripsi dan
                                     tidak pernah bisa dibaca kembali. Tiga
                                     tombol abu-abu berjajar membuat ketiganya
                                     terlihat sama beratnya. -->
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="border-sky-500/40 text-sky-700 hover:bg-sky-50 hover:text-sky-800 dark:text-sky-300 dark:hover:bg-sky-950/40"
                                    :disabled="!bisaDiuji(k)"
                                    @click="ujiKunci(k)"
                                >
                                    <Loader2 v-if="sedangUji === k.id" class="size-3.5 animate-spin" />
                                    <template v-if="sedangUji === k.id">Menguji</template>
                                    <template v-else-if="jedaUji[k.id]">Tunggu {{ jedaUji[k.id] }}s</template>
                                    <template v-else>Uji</template>
                                </Button>
                                <Button
                                    v-if="bisaDimatikan(k)"
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :class="
                                        k.aktif
                                            ? 'border-amber-500/40 text-amber-700 hover:bg-amber-50 hover:text-amber-800 dark:text-amber-300 dark:hover:bg-amber-950/40'
                                            : 'border-emerald-500/40 text-emerald-700 hover:bg-emerald-50 hover:text-emerald-800 dark:text-emerald-300 dark:hover:bg-emerald-950/40'
                                    "
                                    @click="ubahAktif(k)"
                                >
                                    {{ k.aktif ? 'Matikan' : 'Nyalakan' }}
                                </Button>
                                <Button v-if="bisaDihapus(k)" type="button" variant="destructive" size="sm" @click="hapusKunci(k)">Hapus</Button>
                            </div>
                        </div>

                        <!-- Kotak batas harian.
                             Hanya ditampilkan selama Google belum menyebut
                             angkanya sendiri. Begitu 429 harian pernah terjadi,
                             angka resminya masuk dan mengambil alih, dan kotak
                             yang tetap terbuka setelah itu hanya menerima
                             ketikan yang tidak akan pernah dipakai.

                             Ini bukan kenyamanan. Penjaga kuota menahan kunci
                             sebelum permintaan yang memicu 429 sempat dikirim,
                             jadi kunci berbayar tidak punya jalan lain untuk
                             memberitahukan jatahnya, dan tanpa kotak ini ia
                             terkunci selamanya di 500 dengan satu-satunya
                             gejala berupa antrean yang berjalan pelan. -->
                        <form v-if="k.rpd_google === null" class="flex flex-wrap items-center gap-2" @submit.prevent="simpanBatas(k)">
                            <Label :for="`rpd-${k.id}`" class="text-xs font-normal text-muted-foreground"> Batas harian kunci ini </Label>
                            <Input
                                :id="`rpd-${k.id}`"
                                :model-value="batasHarian(k)"
                                type="number"
                                min="1"
                                max="100000"
                                inputmode="numeric"
                                class="h-8 w-28 text-sm"
                                placeholder="500"
                                @update:model-value="batasKetikan[k.id] = String($event)"
                            />
                            <Button type="submit" variant="outline" size="sm">Simpan</Button>
                            <span class="text-xs text-muted-foreground">
                                Isi kalau tier kunci ini bukan free. Kosongkan untuk memakai angka bawaan.
                            </span>
                        </form>

                        <!-- Galat terakhir yang belum tercabut oleh pemakaian
                             yang berhasil. Menempel pada kuncinya sendiri, bukan
                             di log: dengan tiga kunci, satu kunci yang salah
                             ketik hanya terbaca sebagai "klasifikasi kadang
                             gagal" kalau galatnya tidak ditunjukkan di sini.
                             Hilang sendiri begitu kunci ini berhasil dipakai
                             lagi, baik oleh antrean maupun oleh tombol Uji.

                             Disembunyikan saat ada hasil uji untuk kunci ini,
                             karena uji yang gagal menulis galatnya ke kolom yang
                             sama. Tanpa penjaga itu kalimat galat yang sama
                             muncul dua kali bertumpuk persis setelah tombol Uji
                             ditekan. -->
                        <div
                            v-if="k.galat_terakhir && !adaHasilUji(k)"
                            class="rounded-md border border-rose-500/40 bg-rose-50 p-2 text-xs dark:bg-rose-950/40"
                        >
                            <p class="font-medium text-rose-700 dark:text-rose-300">
                                Galat terakhir
                                <span v-if="k.galat_at" class="font-normal opacity-70">· {{ waktu(k.galat_at) }}</span>
                            </p>
                            <p class="mt-1 break-words text-rose-700 dark:text-rose-300">{{ k.galat_terakhir }}</p>
                            <p class="mt-1 text-muted-foreground">Peringatan ini hilang sendiri begitu kunci berhasil dipakai lagi.</p>
                        </div>

                        <!-- Hasil uji ditempel di bawah kuncinya sendiri, bukan di
                             toast. Jawaban model dan kalimat galat dari Google
                             adalah isi yang ingin dibaca dan dibandingkan, bukan
                             pemberitahuan yang lewat lalu hilang. -->
                        <div
                            v-if="uji && uji.id === k.id"
                            class="rounded-md border p-2 text-xs"
                            :class="
                                uji.berhasil
                                    ? 'border-emerald-500/40 bg-emerald-50 dark:bg-emerald-950/40'
                                    : 'border-rose-500/40 bg-rose-50 dark:bg-rose-950/40'
                            "
                        >
                            <p
                                class="font-medium"
                                :class="uji.berhasil ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300'"
                            >
                                <!-- Jawaban yang kembali tetapi tidak memuat label
                                     kunci bukan kegagalan kunci. Paketnya sampai,
                                     yang meleset instruksinya, dan menyebutnya
                                     "gagal dipakai" akan membuat admin mengganti
                                     kunci yang sebenarnya baik. -->
                                {{ uji.berhasil ? 'Kunci berfungsi' : uji.jawaban ? 'Jawaban tidak sesuai' : 'Kunci gagal dipakai' }}
                                <span class="angka font-normal opacity-70">({{ formatAngka(uji.ms) }} ms)</span>
                            </p>
                            <p v-if="uji.jawaban" class="mt-1 text-muted-foreground">
                                Jawaban Gemini: <span class="text-foreground">{{ uji.jawaban }}</span>
                            </p>
                            <p v-if="uji.galat" class="mt-1 break-words text-rose-700 dark:text-rose-300">
                                {{ uji.galat }}
                            </p>
                        </div>
                    </li>
                </ul>

                <form class="flex flex-wrap items-end gap-2" @submit.prevent="tambahKunci">
                    <div class="grid flex-1 gap-1.5">
                        <Label for="label-kunci">Label</Label>
                        <Input id="label-kunci" v-model="formKunci.label" placeholder="Akun cadangan 1" />
                        <InputError :message="formKunci.errors.label" />
                    </div>
                    <div class="grid flex-[2] gap-1.5">
                        <Label for="isi-kunci">Kunci API</Label>
                        <Input id="isi-kunci" v-model="formKunci.kunci" type="password" autocomplete="off" placeholder="AIza..." />
                        <InputError :message="formKunci.errors.kunci" />
                    </div>
                    <Button type="submit" :disabled="formKunci.processing">Tambah kunci</Button>
                </form>

                <p class="text-xs text-muted-foreground">
                    Kunci disimpan terenkripsi dan tidak pernah ditampilkan kembali. Kunci yang salah tempel harus dihapus lalu ditambahkan ulang.
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">Klasifikasi</CardTitle>
                <p class="text-xs text-muted-foreground">
                    Menyimpan pengaturan di sini tidak mengubah artikel yang sudah dinilai. Setiap hasil menyimpan nama penilai dan versinya sendiri,
                    jadi hasil lama dan hasil baru tetap bisa dibedakan di halaman Berita.
                </p>
            </CardHeader>
            <CardContent>
                <form class="space-y-4" @submit.prevent="simpanAi">
                    <div class="grid gap-1.5">
                        <Label>Penilai relevansi</Label>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <button
                                v-for="p in penyediaRelevansi"
                                :key="p.nilai"
                                type="button"
                                :disabled="p.nilai === 'indobert' && !bisaIndoBert"
                                class="rounded-lg border p-3 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                                :class="
                                    formAi.penyedia_relevansi === p.nilai
                                        ? 'border-foreground bg-muted'
                                        : 'text-muted-foreground hover:border-foreground/40'
                                "
                                :aria-pressed="formAi.penyedia_relevansi === p.nilai"
                                @click="formAi.penyedia_relevansi = p.nilai"
                            >
                                <span class="block text-sm font-medium text-foreground">{{ p.label }}</span>
                                <span class="mt-1 block text-xs">{{ p.keterangan }}</span>
                            </button>
                        </div>

                        <!-- Nama modelnya disebut, bukan sekadar "aktif".
                             Halaman Model Relevansi bisa berisi belasan
                             pelatihan dengan metrik yang berbeda jauh, dan yang
                             perlu diketahui sebelum menyerahkan keputusan buang
                             atau simpan adalah yang mana di antara mereka. -->
                        <p v-if="props.modelRelevansiAktif" class="text-xs text-muted-foreground">
                            Model yang akan bekerja: <span class="font-medium text-foreground">{{ props.modelRelevansiAktif }}</span
                            >. Ganti lewat halaman Model Relevansi. Sentimen tetap dinilai Gemini.
                        </p>
                        <p v-else class="text-xs text-muted-foreground">
                            IndoBERT belum bisa dipilih. Latih lalu aktifkan satu model di halaman Model Relevansi terlebih dahulu.
                        </p>

                        <!-- Antrean tidak perlu dihentikan saat opsi ini
                             diganti. Job hanya membawa id barisnya, dan
                             penyedianya dibaca ulang saat job benar-benar
                             dieksekusi. Ditulis di layar karena inilah
                             pertanyaan pertama yang muncul sebelum menekan
                             tombol simpan. -->
                        <p class="text-xs text-muted-foreground">
                            Pergantian berlaku untuk artikel yang belum dinilai, termasuk yang sedang mengantre. Artikel yang sudah dinilai tidak
                            berubah dan tetap bertanda penilai lamanya.
                        </p>
                        <InputError :message="formAi.errors.penyedia_relevansi" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="model">Model Gemini</Label>
                        <Input id="model" v-model="formAi.model" placeholder="gemini-3.5-flash-lite" />
                        <InputError :message="formAi.errors.model" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="versi-relevansi">Label versi prompt relevansi</Label>
                        <Input id="versi-relevansi" v-model="formAi.versi_prompt_relevansi" placeholder="relevance-v2" />
                        <p class="text-xs text-muted-foreground">
                            Sidik isi prompt ditambahkan otomatis, jadi lupa menaikkan label tidak membuat dua prompt berbeda tercatat dengan versi
                            yang sama.
                        </p>
                        <InputError :message="formAi.errors.versi_prompt_relevansi" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="prompt-relevansi">Prompt relevansi</Label>
                        <textarea
                            id="prompt-relevansi"
                            v-model="formAi.prompt_relevansi"
                            rows="16"
                            class="rounded-md border border-input bg-background px-3 py-2 font-mono text-xs"
                        />
                        <InputError :message="formAi.errors.prompt_relevansi" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="versi-sentimen">Label versi prompt sentimen</Label>
                        <Input id="versi-sentimen" v-model="formAi.versi_prompt_sentimen" placeholder="sentiment-v2" />
                        <InputError :message="formAi.errors.versi_prompt_sentimen" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="prompt-sentimen">Prompt sentimen</Label>
                        <textarea
                            id="prompt-sentimen"
                            v-model="formAi.prompt_sentimen"
                            rows="16"
                            class="rounded-md border border-input bg-background px-3 py-2 font-mono text-xs"
                        />
                        <InputError :message="formAi.errors.prompt_sentimen" />
                    </div>

                    <Button type="submit" :disabled="formAi.processing">Simpan pengaturan</Button>
                </form>
            </CardContent>
        </Card>

        <Card v-for="k in props.kelompok" :key="k.judul">
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">{{ k.judul }}</CardTitle>
                <p v-if="k.catatan" class="text-xs text-muted-foreground">{{ k.catatan }}</p>
            </CardHeader>
            <CardContent class="p-0">
                <ul class="divide-y">
                    <li v-for="n in k.nilai" :key="n.env" class="space-y-1 px-4 py-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="text-sm">{{ n.label }}</p>
                            <span class="angka text-sm font-medium">{{ n.nilai }}</span>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            <code class="text-[11px]">{{ n.env }}</code>
                            <template v-if="n.diukur"> · {{ n.diukur }}</template>
                        </p>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </LayoutAdmin>
</template>
