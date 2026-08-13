<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label as LabelUi } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useFormatAngka } from '@/composables/useFormatAngka';
import type { SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { Check, Copy, FlaskConical, History, Loader2, Maximize2, Target, Trash2, TriangleAlert } from 'lucide-vue-next';
import { computed, onUnmounted, ref, watch } from 'vue';
import type { Pelatihan, RiwayatUji } from './tipe';
import { IKON_LABEL, SEBUTAN_LABEL, WARNA_LABEL } from './tipe';

const props = defineProps<{ pelatihan: Pelatihan[]; riwayat: RiwayatUji[] }>();

const page = usePage<SharedData>();
const { formatAngka, formatPersen } = useFormatAngka();

/**
 * Di bawah angka ini hasilnya diberi peringatan.
 *
 * Confidence di sini adalah jarak dari keraguan penuh, bukan peluang kelas yang
 * menang. Model yang menjawab 0,55 mengeluarkan confidence 0,10, dan jawaban
 * semacam itu memang layak ditinjau manusia.
 */
const AMBANG_RAGU = 0.4;

const siap = computed(() => props.pelatihan.filter((p) => p.status === 'berhasil' && p.artefak_path !== null));

const aktif = computed(() => siap.value.find((p) => p.aktif) ?? siap.value[0] ?? null);

const form = useForm({
    pelatihan_model_relevansi_id: '' as string | number,
    teks: '',
});

// Model aktif dipilih sendiri saat halaman dibuka. Hampir setiap pengujian
// ditujukan ke model yang sedang berlaku, dan memaksa memilihnya tiap kali
// hanya menambah satu ketukan yang jawabannya sudah bisa ditebak.
watch(
    aktif,
    (p) => {
        if (p && !form.pelatihan_model_relevansi_id) {
            form.pelatihan_model_relevansi_id = String(p.id);
        }
    },
    { immediate: true },
);

const hasil = computed(() => page.props.flash?.hasilUji ?? null);

const ragu = computed(() => hasil.value !== null && hasil.value.confidence < AMBANG_RAGU);

/** Panjang minimal yang diterima server, dipakai juga oleh bilah kecukupan teks. */
const MIN_TEKS = 20;

const cukup = computed(() => form.teks.trim().length >= MIN_TEKS);

function uji() {
    form.post('/admin/model-relevansi/uji', {
        preserveScroll: true,
        // Tanpa bilah progres global di puncak layar. Tombol Uji Model sudah
        // berputar dan mengunci dirinya selama permintaan berjalan, jadi bilah
        // itu melaporkan hal yang sama untuk kedua kalinya di ujung layar yang
        // berlawanan dari tempat mata sedang menunggu jawabannya.
        showProgress: false,
    });
}

function reset() {
    form.teks = '';
    form.clearErrors();
}

const waktu = (nilai: string | null) => (nilai ? format(new Date(nilai), 'd MMM yyyy HH:mm:ss', { locale: id }) : '-');

/*
 * Detail satu baris riwayat, dibuka sebagai modal.
 *
 * Kolom Teks di tabel dipotong dua baris, dan itu memang benar untuk memindai.
 * Yang tidak bisa dilakukan tabel adalah menjawab "teks persis apa yang
 * menghasilkan jawaban ini", padahal itulah pertanyaan yang muncul begitu satu
 * baris terlihat janggal. Modal ini yang menjawabnya, sekaligus menyediakan
 * teksnya untuk disalin dan diuji ulang.
 */
const detail = ref<RiwayatUji | null>(null);

/**
 * Teks utuh ditarik saat modal dibuka, bukan ikut dikirim bersama daftar.
 *
 * Alasannya tertulis di ModelRelevansiController::riwayatUji(): halaman ini
 * menarik dirinya sendiri tiap tiga detik selama pelatihan berjalan, dan isi
 * berita utuh dikali tiga puluh baris pada setiap tarikan adalah muatan yang
 * tumbuh bersama panjang artikelnya.
 */
const teksPenuh = ref<string | null>(null);
const memuatTeks = ref(false);
const galatTeks = ref<string | null>(null);

/** Yang benar-benar disalin: teks utuh kalau berhasil ditarik, potongan kalau tidak. */
const teksSalin = computed(() => teksPenuh.value ?? detail.value?.potongan ?? '');

const tersalin = ref(false);
const galatSalin = ref<string | null>(null);

let pewaktuSalin: ReturnType<typeof setTimeout> | undefined;

async function bukaDetail(baris: RiwayatUji) {
    detail.value = baris;
    teksPenuh.value = null;
    galatTeks.value = null;
    galatSalin.value = null;
    tersalin.value = false;
    memuatTeks.value = true;

    try {
        const respons = await fetch(`/admin/model-relevansi/uji/${baris.id}`, {
            headers: { Accept: 'application/json' },
        });

        if (!respons.ok) throw new Error(String(respons.status));

        teksPenuh.value = ((await respons.json()) as { teks?: string }).teks ?? '';
    } catch {
        // Modalnya tetap terbuka dengan potongan yang sudah ada di tangan.
        // Menutupnya karena satu permintaan gagal akan membuang seluruh angka
        // yang justru sudah dimuat dan sudah benar.
        galatTeks.value = 'Teks utuhnya gagal diambil. Yang tampil di bawah adalah potongan yang sudah ada di daftar.';
    } finally {
        memuatTeks.value = false;
    }
}

function tutupDetail() {
    detail.value = null;
}

/**
 * Menyalin ke papan klip, dengan jalan keluar saat peramban menolak.
 *
 * `navigator.clipboard` hanya tersedia di konteks aman, dan sebagian peramban
 * juga menolaknya kalau tab-nya tidak sedang difokus. Kegagalannya senyap kalau
 * tidak ditangkap, dan tombol yang tidak melakukan apa-apa tanpa berkata apa-apa
 * adalah tombol yang ditekan berkali-kali.
 */
async function salin() {
    if (!teksSalin.value) return;

    galatSalin.value = null;

    try {
        await navigator.clipboard.writeText(teksSalin.value);

        tersalin.value = true;
        clearTimeout(pewaktuSalin);
        pewaktuSalin = setTimeout(() => (tersalin.value = false), 2000);
    } catch {
        galatSalin.value = 'Peramban menolak akses papan klip. Pilih teksnya di kotak atas lalu tekan Ctrl+C.';
    }
}

onUnmounted(() => clearTimeout(pewaktuSalin));

/*
 * Menghapus riwayat, dua tingkat untuk dua kebutuhan yang berbeda.
 *
 * "Hapus semua" di kepala kartu untuk membersihkan seluruh catatan sebelum
 * mulai menguji satu model baru, dan itu penggunaan yang paling sering. Hapus
 * per baris ada di dalam modal detail, bukan sebagai tombol sampah di setiap
 * baris tabel, karena tombol sampah yang berdiri tepat di sebelah tombol detail
 * pada tabel sepadat ini terlalu mudah tertekan salah. Di dalam modal, barisnya
 * sudah dibuka dan dibaca lebih dulu.
 *
 * Hanya "Hapus semua" yang meminta konfirmasi. Satu baris riwayat adalah satu
 * kali tempel teks yang bisa diulang dalam sepuluh detik, sedangkan mengosongkan
 * seluruhnya membuang tiga puluh baris sekaligus. Meminta konfirmasi untuk
 * keduanya akan melatih penggunanya menekan "Ya" tanpa membaca.
 */
const formHapus = useForm({});

const konfirmasiKosongkan = ref(false);

function hapusBaris(baris: RiwayatUji) {
    formHapus.delete(`/admin/model-relevansi/uji/${baris.id}`, {
        preserveScroll: true,
        // Modalnya wajib ditutup sendiri. Tabel di belakangnya sudah diperbarui
        // Inertia begitu kunjungannya selesai, tetapi `detail` adalah keadaan
        // lokal komponen dan tidak ikut berubah. Tanpa baris ini modal tetap
        // terbuka memajang baris yang barusan dihapus, dan yang terbaca di layar
        // adalah halaman yang seolah tidak melakukan apa-apa.
        onFinish: () => (detail.value = null),
    });
}

function kosongkanRiwayat() {
    formHapus.delete('/admin/model-relevansi/uji', {
        preserveScroll: true,
        onFinish: () => (konfirmasiKosongkan.value = false),
    });
}
</script>

<template>
    <div class="space-y-4">
        <Card class="muncul overflow-hidden" style="animation-delay: 120ms">
            <CardHeader class="flex-row items-center gap-2 space-y-0 border-b py-3">
                <div class="grid size-7 place-items-center rounded-md bg-aksen-toska/10 text-aksen-toska">
                    <FlaskConical class="size-4" aria-hidden="true" />
                </div>
                <CardTitle class="text-sm font-semibold">Uji model dengan teks sendiri</CardTitle>
            </CardHeader>

            <CardContent class="space-y-4 pt-4">
                <!-- Kuning berarti menunggu syarat, bukan rusak. Model yang
                     belum dilatih adalah pekerjaan yang belum dilakukan. -->
                <div
                    v-if="siap.length === 0"
                    class="flex items-start gap-2 rounded-lg bg-sentimen-review-lembut p-3 text-sm text-sentimen-review ring-1 ring-sentimen-review/25 ring-inset"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <p>Belum ada model yang selesai dilatih. Jalankan pelatihan di tab Pelatihan terlebih dahulu.</p>
                </div>

                <template v-else>
                    <div class="space-y-1">
                        <LabelUi for="model-uji">Model yang diuji</LabelUi>
                        <Select id="model-uji" v-model="form.pelatihan_model_relevansi_id">
                            <SelectTrigger><SelectValue placeholder="Pilih model" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="p in siap" :key="p.id" :value="String(p.id)">
                                    {{ p.nama }} ({{ p.base_model }}){{ p.aktif ? ' - model aktif' : '' }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.pelatihan_model_relevansi_id" />
                    </div>

                    <div class="space-y-1">
                        <LabelUi for="teks-uji">Judul, isi berita, atau gabungan keduanya</LabelUi>
                        <!-- Textarea polos, bukan komponen ui. Proyek ini belum
                             punya pembungkus textarea, dan satu kotak teks tidak
                             cukup alasan untuk membuatnya. -->
                        <textarea
                            id="teks-uji"
                            v-model="form.teks"
                            rows="8"
                            class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm leading-relaxed shadow-xs outline-hidden transition-colors focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            placeholder="Tempelkan judul di baris pertama, lalu isi beritanya di bawahnya."
                        />

                        <!-- Bilah kecukupan, bukan hanya angka karakter. Yang
                             ditanyakan pengguna bukan "berapa karakter" melainkan
                             "sudah cukup belum", dan bilah menjawabnya tanpa
                             membandingkan dua angka. Ia penuh lalu berhenti,
                             tidak tumbuh terus mengikuti panjang teks. -->
                        <div class="flex items-center gap-2 pt-0.5">
                            <div class="h-1 flex-1 overflow-hidden rounded-full bg-muted">
                                <div
                                    class="h-full rounded-full transition-all duration-300 ease-out"
                                    :class="cukup ? 'bg-aksen-toska' : 'bg-sentimen-review'"
                                    :style="{ width: `${Math.min(100, (form.teks.trim().length / MIN_TEKS) * 100)}%` }"
                                />
                            </div>
                            <span class="angka shrink-0 text-xs" :class="cukup ? 'text-muted-foreground' : 'text-sentimen-review'">
                                {{ formatAngka(form.teks.length) }} karakter
                            </span>
                        </div>
                        <p class="text-xs text-muted-foreground">Sekurangnya {{ MIN_TEKS }} karakter.</p>
                        <InputError :message="form.errors.teks" />
                    </div>

                    <!-- Toska, warna yang di halaman ini berarti relevansi.
                         Tombol ini menjalankan penilaian relevansi, bukan
                         pelatihan, jadi ia sengaja tidak memakai ungu yang
                         berarti pekerjaan model yang memakan sumber daya. -->
                    <div class="flex flex-wrap gap-2">
                        <Button
                            :disabled="form.processing || !cukup"
                            class="tekan bg-aksen-toska text-white hover:bg-aksen-toska/90 dark:text-background"
                            @click="uji"
                        >
                            <Loader2 v-if="form.processing" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                            <Target v-else class="size-4" aria-hidden="true" />
                            Uji Model
                        </Button>
                        <Button variant="outline" class="tekan" :disabled="form.processing" @click="reset">Reset</Button>
                    </div>
                </template>
            </CardContent>
        </Card>

        <!-- Hasil prediksi -->
        <Card v-if="hasil" class="muncul overflow-hidden">
            <CardHeader class="flex-row items-center justify-between gap-2 space-y-0 border-b py-3">
                <CardTitle class="text-sm font-semibold">Hasil prediksi</CardTitle>
                <span class="angka text-xs text-muted-foreground">{{ formatAngka(hasil.inferensi_ms) }} ms</span>
            </CardHeader>

            <CardContent class="space-y-4 pt-4">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-base font-semibold" :class="WARNA_LABEL[hasil.label]">
                        <component :is="IKON_LABEL[hasil.label]" class="size-4 shrink-0" aria-hidden="true" />
                        {{ SEBUTAN_LABEL[hasil.label] }}
                    </span>
                    <span class="angka text-sm text-muted-foreground">Confidence {{ formatPersen(hasil.confidence * 100) }}</span>
                </div>

                <!--
                    Satu sumbu keputusan, bukan dua batang terpisah.

                    Kedua probabilitas selalu berjumlah seratus persen, jadi dua
                    batang yang berdiri sendiri menggambar satu angka dua kali
                    dan tetap tidak menjawab pertanyaan yang sebenarnya dicari:
                    seberapa dekat jawabannya ke ambang. Satu sumbu dengan garis
                    ambang di tengah menjawab ketiganya sekaligus. Semakin dekat
                    tepi warna ke garis tengah, semakin ragu modelnya, dan itulah
                    persis arti confidence di halaman ini.
                -->
                <!--
                    Nama kedua sisi ditulis di keterangan bawah, bukan di dalam
                    bilahnya.

                    Dulu keduanya diletakkan melayang di dalam bilah, yang kiri
                    putih dan yang kanan abu. Itu hanya terbaca selama tepi
                    warnanya kebetulan berada dekat tengah. Pada 99,9 persen
                    relevan, isian toska menutup hampir seluruh lebar dan kata
                    "Tidak Relevan" yang berwarna abu tua berdiri di atas bidang
                    toska tanpa kontras sama sekali. Kebalikannya juga terjadi:
                    pada peluang relevan yang sangat kecil, kata "Relevan" yang
                    berwarna putih berdiri di atas bidang abu terang.

                    Keterangan di bawah sudah menyebut kedua nama itu beserta
                    angkanya, jadi label di dalam bilah bukan hanya bertabrakan,
                    ia juga mengulang. Menghapusnya memperbaiki keduanya
                    sekaligus, dan titik warna di keterangan yang menautkan tiap
                    nama ke bagian bilah yang diwakilinya.
                -->
                <div class="space-y-2">
                    <div
                        class="relative h-8 overflow-hidden rounded-lg bg-muted"
                        role="img"
                        :aria-label="`Peluang relevan ${formatPersen(hasil.probabilitas_relevan * 100)}, peluang tidak relevan ${formatPersen(hasil.probabilitas_tidak_relevan * 100)}, ambang keputusan 50 persen`"
                    >
                        <div
                            class="h-full bg-aksen-toska/85 transition-[width] duration-500 ease-out"
                            :style="{ width: `${hasil.probabilitas_relevan * 100}%` }"
                        />

                        <!-- Garis ambang. Satu piksel, dan sengaja putus-putus
                             supaya tidak tertukar dengan tepi bidang warnanya. -->
                        <div class="absolute inset-y-0 left-1/2 w-px -translate-x-1/2 bg-foreground/50" aria-hidden="true">
                            <div class="h-full w-full bg-linear-to-b from-foreground/60 via-transparent to-foreground/60"></div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-xs">
                        <span class="inline-flex items-center gap-1.5 font-medium text-aksen-toska">
                            <span class="size-2 shrink-0 rounded-full bg-aksen-toska/85" aria-hidden="true"></span>
                            Relevan <span class="angka">{{ formatPersen(hasil.probabilitas_relevan * 100) }}</span>
                        </span>

                        <span class="text-muted-foreground">Ambang keputusan 50%</span>

                        <span class="inline-flex items-center gap-1.5 text-muted-foreground">
                            <span class="size-2 shrink-0 rounded-full bg-muted ring-1 ring-border ring-inset" aria-hidden="true"></span>
                            Tidak Relevan <span class="angka">{{ formatPersen(hasil.probabilitas_tidak_relevan * 100) }}</span>
                        </span>
                    </div>
                </div>

                <div
                    v-if="ragu"
                    class="flex items-start gap-2 rounded-lg bg-sentimen-review-lembut p-3 text-sm text-sentimen-review ring-1 ring-sentimen-review/25 ring-inset"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <p>
                        Confidence di bawah {{ formatPersen(AMBANG_RAGU * 100) }}. Kedua label hampir sama kuat, jadi hasil ini perlu ditinjau manual
                        sebelum dipakai sebagai dasar keputusan.
                    </p>
                </div>

                <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border bg-border text-xs sm:grid-cols-4">
                    <div class="min-w-0 bg-card p-2.5">
                        <dt class="text-muted-foreground">Model</dt>
                        <dd class="font-medium wrap-break-word">{{ hasil.model }}</dd>
                    </div>
                    <div class="min-w-0 bg-card p-2.5">
                        <dt class="text-muted-foreground">Versi base model</dt>
                        <dd class="font-medium break-all">{{ hasil.base_model }}</dd>
                    </div>
                    <div class="min-w-0 bg-card p-2.5">
                        <dt class="text-muted-foreground">Waktu inferensi</dt>
                        <dd class="angka font-medium">{{ formatAngka(hasil.inferensi_ms) }} ms</dd>
                    </div>
                    <div class="min-w-0 bg-card p-2.5">
                        <dt class="text-muted-foreground">Waktu pengujian</dt>
                        <dd class="font-medium">{{ waktu(hasil.diuji_at) }}</dd>
                    </div>
                </dl>
            </CardContent>
        </Card>

        <!-- Riwayat pengujian -->
        <Card class="muncul overflow-hidden" style="animation-delay: 200ms">
            <CardHeader class="flex-row items-center justify-between gap-2 space-y-0 border-b py-3">
                <div class="flex items-center gap-2">
                    <div class="grid size-7 place-items-center rounded-md bg-muted text-muted-foreground">
                        <History class="size-4" aria-hidden="true" />
                    </div>
                    <CardTitle class="text-sm font-semibold">Riwayat pengujian</CardTitle>
                </div>
                <div v-if="riwayat.length" class="flex shrink-0 items-center gap-2">
                    <span class="angka rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">
                        {{ formatAngka(riwayat.length) }}
                    </span>

                    <Button
                        variant="outline"
                        size="sm"
                        class="tekan gap-1.5 border-destructive/35 text-destructive hover:bg-destructive/10 hover:text-destructive"
                        :disabled="formHapus.processing"
                        @click="konfirmasiKosongkan = true"
                    >
                        <Trash2 class="size-4" aria-hidden="true" />
                        Hapus semua
                    </Button>
                </div>
            </CardHeader>

            <CardContent class="p-0">
                <KeadaanKosong
                    v-if="riwayat.length === 0"
                    judul="Belum ada pengujian"
                    keterangan="Masukkan teks berita di atas lalu tekan Uji Model."
                />

                <div v-else class="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Teks</TableHead>
                                <TableHead>Hasil</TableHead>
                                <TableHead class="text-right">Confidence</TableHead>
                                <TableHead>Model</TableHead>
                                <TableHead class="text-right">Inferensi</TableHead>
                                <TableHead>Waktu</TableHead>
                                <TableHead class="w-10"><span class="sr-only">Detail</span></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="r in riwayat" :key="r.id" class="transition-colors hover:bg-muted/40">
                                <TableCell class="max-w-md">
                                    <p class="line-clamp-2 text-xs">{{ r.potongan }}</p>
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        variant="outline"
                                        class="gap-1 rounded-md border-transparent font-medium"
                                        :class="WARNA_LABEL[r.label_prediksi]"
                                    >
                                        <component :is="IKON_LABEL[r.label_prediksi]" class="size-3 shrink-0" aria-hidden="true" />
                                        {{ SEBUTAN_LABEL[r.label_prediksi] }}
                                    </Badge>
                                </TableCell>
                                <!-- Confidence rendah diberi bilah mini, bukan
                                     hanya angka berwarna. Yang dicari saat
                                     menyisir riwayat adalah baris mana yang
                                     ragu, dan bentuk pendek terlihat lebih cepat
                                     daripada angka kecil. -->
                                <TableCell class="min-w-24 text-right">
                                    <span class="angka text-sm" :class="r.confidence < AMBANG_RAGU ? 'font-medium text-sentimen-review' : ''">
                                        {{ formatPersen(r.confidence * 100) }}
                                    </span>
                                    <div class="mt-1 h-1 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                                        <div
                                            class="h-full rounded-full"
                                            :class="r.confidence < AMBANG_RAGU ? 'bg-sentimen-review' : 'bg-aksen-toska'"
                                            :style="{ width: `${r.confidence * 100}%` }"
                                        />
                                    </div>
                                </TableCell>
                                <TableCell class="text-xs">
                                    {{ r.model ?? 'sudah dihapus' }}
                                    <p class="break-all text-muted-foreground">{{ r.base_model }}</p>
                                </TableCell>
                                <TableCell class="angka text-right text-xs">{{ formatAngka(r.inferensi_ms) }} ms</TableCell>
                                <TableCell class="text-xs whitespace-nowrap text-muted-foreground">
                                    {{ waktu(r.diuji_at) }}
                                    <p>{{ r.penguji ?? '-' }}</p>
                                </TableCell>

                                <!-- Tombol tersendiri, bukan seluruh barisnya
                                     yang bisa ditekan. Baris ini memuat teks
                                     yang sering perlu diseleksi dengan tetikus
                                     untuk disalin sebagian, dan baris yang
                                     membuka modal pada setiap klik membuat
                                     seleksi itu mustahil. -->
                                <TableCell class="w-10 p-1 text-right">
                                    <button
                                        type="button"
                                        class="tekan grid size-8 place-items-center rounded-md text-muted-foreground transition-colors duration-150 hover:bg-aksen-toska/10 hover:text-aksen-toska focus-visible:bg-aksen-toska/10 focus-visible:text-aksen-toska focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                        @click="bukaDetail(r)"
                                    >
                                        <Maximize2 class="size-4" aria-hidden="true" />
                                        <span class="sr-only">Lihat detail pengujian {{ waktu(r.diuji_at) }}</span>
                                    </button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>

        <!--
            Modal detail satu pengujian.

            Isinya menjawab tiga hal berurutan: apa jawabannya, seberapa dekat
            jawaban itu ke ambang, dan teks persis apa yang menghasilkannya.
            Sumbu keputusannya sengaja sama persis dengan yang dipakai kartu
            Hasil prediksi di atas, jadi bentuk yang sudah dipelajari di sana
            tidak perlu dipelajari ulang di sini.
        -->
        <Dialog :open="detail !== null" @update:open="(terbuka) => (terbuka ? null : tutupDetail())">
            <DialogContent class="max-w-2xl">
                <DialogHeader>
                    <DialogTitle class="flex flex-wrap items-center gap-2">
                        <span class="grid size-7 shrink-0 place-items-center rounded-md bg-aksen-toska/10 text-aksen-toska">
                            <FlaskConical class="size-4" aria-hidden="true" />
                        </span>
                        Detail pengujian
                    </DialogTitle>
                    <DialogDescription class="text-left">
                        Diuji {{ waktu(detail?.diuji_at ?? null) }} oleh {{ detail?.penguji ?? 'pengguna yang sudah dihapus' }}.
                    </DialogDescription>
                </DialogHeader>

                <div v-if="detail" class="space-y-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <span
                            class="inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-base font-semibold"
                            :class="WARNA_LABEL[detail.label_prediksi]"
                        >
                            <component :is="IKON_LABEL[detail.label_prediksi]" class="size-4 shrink-0" aria-hidden="true" />
                            {{ SEBUTAN_LABEL[detail.label_prediksi] }}
                        </span>
                        <span class="angka text-sm text-muted-foreground">Confidence {{ formatPersen(detail.confidence * 100) }}</span>
                    </div>

                    <!-- Nama kedua sisi ada di keterangan bawah, bukan melayang
                         di dalam bilahnya. Alasannya sama persis dengan sumbu di
                         kartu Hasil prediksi, dan tertulis lengkap di sana. -->
                    <div class="space-y-2">
                        <div
                            class="relative h-8 overflow-hidden rounded-lg bg-muted"
                            role="img"
                            :aria-label="`Peluang relevan ${formatPersen(detail.probabilitas_relevan * 100)}, ambang keputusan 50 persen`"
                        >
                            <div
                                class="tumbuh h-full bg-aksen-toska/85"
                                :style="{ width: `${detail.probabilitas_relevan * 100}%` }"
                                aria-hidden="true"
                            />

                            <div class="absolute inset-y-0 left-1/2 w-px -translate-x-1/2 bg-foreground/50" aria-hidden="true">
                                <div class="h-full w-full bg-linear-to-b from-foreground/60 via-transparent to-foreground/60"></div>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-xs">
                            <span class="inline-flex items-center gap-1.5 font-medium text-aksen-toska">
                                <span class="size-2 shrink-0 rounded-full bg-aksen-toska/85" aria-hidden="true"></span>
                                Relevan <span class="angka">{{ formatPersen(detail.probabilitas_relevan * 100) }}</span>
                            </span>

                            <span class="text-muted-foreground">Ambang keputusan 50%</span>

                            <span class="inline-flex items-center gap-1.5 text-muted-foreground">
                                <span class="size-2 shrink-0 rounded-full bg-muted ring-1 ring-border ring-inset" aria-hidden="true"></span>
                                Tidak Relevan <span class="angka">{{ formatPersen((1 - detail.probabilitas_relevan) * 100) }}</span>
                            </span>
                        </div>
                    </div>

                    <div
                        v-if="detail.confidence < AMBANG_RAGU"
                        class="flex items-start gap-2 rounded-lg bg-sentimen-review-lembut p-3 text-sm text-sentimen-review ring-1 ring-sentimen-review/25 ring-inset"
                    >
                        <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <p>
                            Confidence di bawah {{ formatPersen(AMBANG_RAGU * 100) }}. Kedua label hampir sama kuat, jadi hasil ini perlu ditinjau
                            manual sebelum dipakai sebagai dasar keputusan.
                        </p>
                    </div>

                    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border bg-border text-xs sm:grid-cols-3">
                        <div class="min-w-0 bg-card p-2.5">
                            <dt class="text-muted-foreground">Model</dt>
                            <dd class="font-medium wrap-break-word">{{ detail.model ?? 'sudah dihapus' }}</dd>
                        </div>
                        <div class="min-w-0 bg-card p-2.5">
                            <dt class="text-muted-foreground">Versi base model</dt>
                            <dd class="font-medium break-all">{{ detail.base_model ?? '-' }}</dd>
                        </div>
                        <div class="min-w-0 bg-card p-2.5">
                            <dt class="text-muted-foreground">Waktu inferensi</dt>
                            <dd class="angka font-medium">{{ formatAngka(detail.inferensi_ms) }} ms</dd>
                        </div>
                    </dl>

                    <!--
                        Teks yang diuji, beserta tombol salinnya.

                        Tombol duduk di kepala kotak teksnya, bukan di kaki
                        modal bersama tombol tutup. Yang disalin adalah isi kotak
                        ini dan bukan seluruh modal, dan tombol yang berdiri jauh
                        dari yang disalinnya membuat sasarannya harus ditebak.
                    -->
                    <div class="space-y-1.5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <LabelUi class="text-xs text-muted-foreground">Teks yang diuji</LabelUi>

                            <Button
                                variant="outline"
                                size="sm"
                                class="tekan gap-1.5"
                                :disabled="memuatTeks || !teksSalin"
                                :aria-live="tersalin ? 'polite' : undefined"
                                @click="salin"
                            >
                                <Loader2 v-if="memuatTeks" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                                <Check v-else-if="tersalin" class="size-4 text-aksen-toska" aria-hidden="true" />
                                <Copy v-else class="size-4" aria-hidden="true" />
                                {{ tersalin ? 'Tersalin' : 'Salin teks' }}
                            </Button>
                        </div>

                        <p v-if="galatTeks" class="flex items-start gap-1.5 text-xs text-sentimen-review">
                            <TriangleAlert class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                            {{ galatTeks }}
                        </p>

                        <div class="max-h-64 overflow-y-auto rounded-md border bg-muted/40 p-3">
                            <p v-if="memuatTeks" class="flex items-center gap-2 text-xs text-muted-foreground">
                                <Loader2 class="size-3.5 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                                Mengambil teks utuh
                            </p>
                            <p v-else class="text-xs leading-relaxed wrap-break-word whitespace-pre-wrap">{{ teksSalin }}</p>
                        </div>

                        <p v-if="galatSalin" class="flex items-start gap-1.5 text-xs text-destructive">
                            <TriangleAlert class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                            {{ galatSalin }}
                        </p>
                    </div>
                </div>

                <!--
                    Hapus baris duduk di kaki modal, terpisah dari tombol salin
                    yang menempel pada kotak teksnya. Yang satu menyalin isi
                    kotak di atasnya, yang satu membuang seluruh barisnya, dan
                    keduanya tidak boleh terbaca sebagai sepasang.
                -->
                <DialogFooter v-if="detail" class="border-t pt-4">
                    <Button
                        variant="outline"
                        size="sm"
                        class="tekan gap-1.5 border-destructive/35 text-destructive hover:bg-destructive/10 hover:text-destructive"
                        :disabled="formHapus.processing"
                        @click="hapusBaris(detail)"
                    >
                        <Loader2 v-if="formHapus.processing" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                        <Trash2 v-else class="size-4" aria-hidden="true" />
                        Hapus baris riwayat ini
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!--
            Konfirmasi pengosongan riwayat.

            Menyebut jumlah barisnya, bukan "semua data". Angka yang disebut
            membuat akibatnya bisa ditimbang, sedangkan kata "semua" sama saja
            bunyinya untuk tiga baris dan tiga puluh baris.
        -->
        <Dialog :open="konfirmasiKosongkan" @update:open="(terbuka) => (konfirmasiKosongkan = terbuka)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <span class="grid size-7 shrink-0 place-items-center rounded-md bg-destructive/10 text-destructive">
                            <Trash2 class="size-4" aria-hidden="true" />
                        </span>
                        Hapus seluruh riwayat pengujian
                    </DialogTitle>
                    <DialogDescription class="text-left">
                        <span class="angka font-medium text-foreground">{{ formatAngka(riwayat.length) }}</span> baris riwayat akan dihapus permanen.
                        Riwayat ini hanya catatan coba-coba, jadi menghapusnya tidak menyentuh model, dataset, maupun artikel mana pun. Model yang
                        sudah dilatih tetap utuh.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="gap-2 sm:gap-2">
                    <DialogClose as-child>
                        <Button variant="outline" size="sm" class="tekan" :disabled="formHapus.processing">Batal</Button>
                    </DialogClose>

                    <Button variant="destructive" size="sm" class="tekan gap-1.5" :disabled="formHapus.processing" @click="kosongkanRiwayat">
                        <Loader2 v-if="formHapus.processing" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                        <Trash2 v-else class="size-4" aria-hidden="true" />
                        {{ formHapus.processing ? 'Menghapus' : 'Hapus semua' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
