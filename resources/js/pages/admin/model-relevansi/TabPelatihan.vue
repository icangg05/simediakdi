<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useFormatAngka } from '@/composables/useFormatAngka';
import { router, useForm } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { Ban, Download, History, Loader2, Play, SlidersHorizontal, Star, Trash2, TriangleAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import GrafikEpoch from './GrafikEpoch.vue';
import type { Layanan, Opsi, Pelatihan, Snapshot } from './tipe';
import { formatDurasi, IKON_STATUS, LABEL_STATUS, WARNA_STATUS } from './tipe';

const props = defineProps<{ snapshot: Snapshot[]; pelatihan: Pelatihan[]; opsi: Opsi; layanan: Layanan | null }>();

const { formatAngka, formatPersen } = useFormatAngka();

const siap = computed(() => props.snapshot.filter((s) => s.status !== 'arsip' && s.total_train > 0));

const batas = props.opsi.batas;

const form = useForm({
    nama: '',
    snapshot_dataset_relevansi_id: '' as string | number,
    base_model: Object.keys(props.opsi.base_model)[0],
    epoch: batas.epoch.bawaan,
    batch_size: batas.batch_size.bawaan,
    learning_rate: batas.learning_rate.bawaan,
    max_seq_length: batas.max_seq_length.bawaan,
    seed: 42 as number | null,
    early_stopping: batas.early_stopping.bawaan as number | null,
});

const terpilih = computed(() => siap.value.find((s) => String(s.id) === String(form.snapshot_dataset_relevansi_id)) ?? null);

/**
 * Perangkat dilaporkan layanan model, bukan ditebak browser.
 *
 * Layanan yang sama menjawab apakah ia berjalan di CPU atau GPU, dan itu satu
 * satunya sumber yang tahu. Menuliskannya sebagai kalimat tetap di sini akan
 * berbohong pada hari pertama seseorang memasang kartu grafis.
 */
const perangkat = computed(() => props.layanan?.perangkat ?? 'belum diketahui');

/**
 * Learning rate dalam notasi ilmiah, sebagai keterangan di bawah kotaknya.
 *
 * Kotak isian menampilkan 0,00002 dan angka itu benar, tetapi hampir seluruh
 * dokumentasi fine-tuning menyebutnya 2e-5. Menampilkan keduanya menghemat satu
 * langkah menghitung nol.
 */
const ilmiah = (nilai: number) => (Number.isFinite(nilai) ? Number(nilai).toExponential() : '-');

/**
 * Jumlah langkah gradien yang akan dijalankan.
 *
 * Ditampilkan di modal konfirmasi karena inilah satuan yang menentukan lama
 * pelatihan, dan satu-satunya yang bisa dihitung sebelum pelatihan mulai.
 * Lamanya dalam detik sengaja tidak ditebak di sini: durasi per langkah
 * bergantung base model, panjang potongan, dan beban mesin, dan angka yang
 * meleset dua kali lipat lebih buruk daripada tidak ada angka. Estimasi
 * sungguhannya muncul di daftar begitu langkah pertama lewat.
 */
const langkah = computed(() => {
    const train = terpilih.value?.total_train ?? 0;
    const batch = Math.max(1, Number(form.batch_size) || 1);

    return Math.ceil(train / batch) * Math.max(1, Number(form.epoch) || 1);
});

const akanDilatih = ref(false);

const siapDikirim = computed(() => !form.processing && form.nama.trim() !== '' && form.snapshot_dataset_relevansi_id !== '');

function mulai() {
    form.post('/admin/model-relevansi/pelatihan', {
        preserveScroll: true,
        onSuccess: () => form.reset('nama'),
        onFinish: () => (akanDilatih.value = false),
    });
}

const detail = ref<Pelatihan | null>(null);
const akanDibatalkan = ref<Pelatihan | null>(null);
const akanDihapus = ref<Pelatihan | null>(null);

// Detail dibaca ulang dari daftar tiap tarikan polling, bukan disalin sekali
// saat dibuka. Tanpa ini, dialog detail pelatihan yang sedang berjalan membeku
// pada angka epoch saat tombolnya ditekan.
const detailSegar = computed(() => (detail.value ? (props.pelatihan.find((p) => p.id === detail.value?.id) ?? null) : null));

function batalkan() {
    if (!akanDibatalkan.value) return;

    router.post(
        `/admin/model-relevansi/pelatihan/${akanDibatalkan.value.id}/batal`,
        {},
        {
            preserveScroll: true,
            onFinish: () => (akanDibatalkan.value = null),
        },
    );
}

function aktifkan(p: Pelatihan) {
    router.post(`/admin/model-relevansi/pelatihan/${p.id}/aktifkan`, {}, { preserveScroll: true });
}

function hapus() {
    if (!akanDihapus.value) return;

    router.delete(`/admin/model-relevansi/pelatihan/${akanDihapus.value.id}`, {
        preserveScroll: true,
        onFinish: () => (akanDihapus.value = null),
    });
}

const waktu = (nilai: string | null) => (nilai ? format(new Date(nilai), 'd MMM yyyy HH:mm:ss', { locale: id }) : '-');

const belumSelesai = (p: Pelatihan) => p.status === 'menunggu' || p.status === 'berjalan';

/**
 * Perkiraan sisa waktu, dihitung layanan dari kecepatan langkah yang benar
 * benar sudah terjadi, bukan dari tebakan di muka.
 *
 * Kosong selama tahap memuat, dan layar mengatakannya apa adanya. Tahap itu
 * bisa berarti mengunduh 1,3 GB atau membaca cache dalam lima detik, dan
 * menampilkan tebakan di antara keduanya lebih menyesatkan daripada mengaku
 * belum tahu.
 */
const sisa = (p: Pelatihan) => (p.estimasi_sisa_detik === null ? 'menghitung sisa waktu' : `sekitar ${formatDurasi(p.estimasi_sisa_detik)}`);

/**
 * Empat angka utama, urutannya sama di kartu ringkas maupun di dialog detail.
 *
 * F1 ditandai sebagai angka pokok. Pada dataset yang tidak seimbang, accuracy
 * bisa terlihat bagus hanya karena model menjawab kelas mayoritas terus
 * menerus, dan F1 adalah satu-satunya dari keempatnya yang tidak bisa ditipu
 * dengan cara itu. Menyorotnya membuat mata jatuh ke angka yang benar lebih
 * dulu, bukan ke angka yang paling menyenangkan.
 */
const METRIK = [
    { kunci: 'accuracy', judul: 'Accuracy', pokok: false },
    { kunci: 'precision', judul: 'Precision', pokok: false },
    { kunci: 'recall', judul: 'Recall', pokok: false },
    { kunci: 'f1', judul: 'F1-score', pokok: true },
] as const;

const BARIS_LAPORAN = [
    { kunci: 'relevan', judul: 'Relevan' },
    { kunci: 'tidak_relevan', judul: 'Tidak Relevan' },
    { kunci: 'macro', judul: 'Rata-rata makro' },
    { kunci: 'weighted', judul: 'Rata-rata berbobot' },
] as const;
</script>

<template>
    <div class="space-y-4">
        <Card class="muncul overflow-hidden" style="animation-delay: 120ms">
            <CardHeader class="flex-row items-center gap-2 space-y-0 border-b py-3">
                <div class="grid size-7 place-items-center rounded-md bg-aksen-ungu/10 text-aksen-ungu">
                    <Play class="size-4" aria-hidden="true" />
                </div>
                <CardTitle class="text-sm font-semibold">Mulai pelatihan baru</CardTitle>
            </CardHeader>

            <CardContent class="space-y-5 pt-4">
                <!-- Merah berarti gagal atau rusak, dan layanan yang tidak
                     menjawab memang membuat seluruh tab ini tidak berguna. -->
                <div
                    v-if="!layanan"
                    class="flex items-start gap-2 rounded-lg bg-sentimen-negatif-lembut p-3 text-sm text-sentimen-negatif ring-1 ring-inset ring-sentimen-negatif/25"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <p>
                        Layanan model relevansi tidak bisa dihubungi, jadi pelatihan tidak akan berjalan. Nyalakan container
                        <code class="rounded bg-sentimen-negatif/10 px-1 py-0.5 text-xs">relevansi</code> lalu muat ulang halaman ini.
                    </p>
                </div>

                <!-- Kuning berarti menunggu syarat, bukan rusak. Snapshot yang
                     belum dibuat adalah pekerjaan yang belum dilakukan. -->
                <div
                    v-else-if="siap.length === 0"
                    class="flex items-start gap-2 rounded-lg bg-sentimen-review-lembut p-3 text-sm text-sentimen-review ring-1 ring-inset ring-sentimen-review/25"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <p>Belum ada snapshot yang siap dipakai. Buat snapshot dataset di tab Snapshot terlebih dahulu.</p>
                </div>

                <template v-else>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label for="nama-model">Nama model atau eksperimen</Label>
                            <Input id="nama-model" v-model="form.nama" placeholder="Contoh: relevansi-v1" />
                            <InputError :message="form.errors.nama" />
                        </div>

                        <div class="space-y-1">
                            <Label for="snapshot">Snapshot dataset</Label>
                            <Select id="snapshot" v-model="form.snapshot_dataset_relevansi_id">
                                <SelectTrigger><SelectValue placeholder="Pilih snapshot" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="s in siap" :key="s.id" :value="String(s.id)">
                                        {{ s.nama }} ({{ formatAngka(s.total) }} artikel)
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.snapshot_dataset_relevansi_id" />
                        </div>
                    </div>

                    <!-- Pratinjau snapshot terpilih. Batang pembagiannya memakai
                         warna yang sama dengan pratinjau di tab Snapshot, jadi
                         satu bentuk berarti satu hal di kedua tab. -->
                    <div v-if="terpilih" class="space-y-2 rounded-lg bg-muted/40 p-3">
                        <p class="break-words text-sm font-medium">{{ terpilih.nama }}</p>

                        <div class="flex h-1.5 overflow-hidden rounded-full bg-background" aria-hidden="true">
                            <div class="bg-brand dark:bg-brand-terang" :style="{ width: `${terpilih.persen_train}%` }" />
                            <div class="bg-aksen-biru" :style="{ width: `${terpilih.persen_validation}%` }" />
                            <div class="bg-aksen-ungu" :style="{ width: `${terpilih.persen_test}%` }" />
                        </div>

                        <div class="grid gap-x-4 gap-y-1 text-xs sm:grid-cols-2 lg:grid-cols-4">
                            <p class="text-muted-foreground">
                                Total <span class="angka font-medium text-foreground">{{ formatAngka(terpilih.total) }}</span>
                            </p>
                            <p class="text-muted-foreground">
                                Relevan <span class="angka font-medium text-aksen-toska">{{ formatAngka(terpilih.total_relevan) }}</span> ({{
                                    terpilih.persen_relevan
                                }}%)
                            </p>
                            <p class="text-muted-foreground">
                                Tidak Relevan
                                <span class="angka font-medium text-foreground">{{ formatAngka(terpilih.total_tidak_relevan) }}</span> ({{
                                    terpilih.persen_tidak_relevan
                                }}%)
                            </p>
                            <p class="text-muted-foreground">
                                Seed <span class="angka font-medium text-foreground">{{ terpilih.random_seed }}</span>
                            </p>
                        </div>

                        <p class="angka text-xs text-muted-foreground">
                            Training {{ formatAngka(terpilih.total_train) }}, validation {{ formatAngka(terpilih.total_validation) }}, testing
                            {{ formatAngka(terpilih.total_test) }}.
                        </p>
                    </div>

                    <section class="space-y-3">
                        <div class="flex items-center gap-2">
                            <SlidersHorizontal class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <h3 class="shrink-0 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Hyperparameter</h3>
                            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="space-y-1 sm:col-span-3">
                                <Label for="base-model">Base model</Label>
                                <!-- Nama dan keterangan dipisah dua baris, bukan
                                     disambung satu kalimat. Disambung, isinya sekitar
                                     seratus tiga puluh karakter tanpa titik putus,
                                     dan panel dropdown melebar mengikuti item
                                     terpanjangnya sampai melampaui layar. -->
                                <Select id="base-model" v-model="form.base_model">
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent class="max-w-[min(32rem,calc(100vw-2rem))]">
                                        <SelectItem v-for="(ket, kunci) in opsi.base_model" :key="kunci" :value="kunci">
                                            <span class="block break-all font-medium">{{ kunci }}</span>
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p class="break-words text-xs text-muted-foreground">{{ opsi.base_model[form.base_model] }}</p>
                                <InputError :message="form.errors.base_model" />
                            </div>

                            <div class="space-y-1">
                                <Label for="epoch">Epoch</Label>
                                <Input id="epoch" v-model.number="form.epoch" type="number" :min="batas.epoch.min" :max="batas.epoch.maks" />
                                <InputError :message="form.errors.epoch" />
                            </div>
                            <div class="space-y-1">
                                <Label for="batch">Batch size</Label>
                                <Input
                                    id="batch"
                                    v-model.number="form.batch_size"
                                    type="number"
                                    :min="batas.batch_size.min"
                                    :max="batas.batch_size.maks"
                                />
                                <InputError :message="form.errors.batch_size" />
                            </div>
                            <div class="space-y-1">
                                <Label for="lr">Learning rate</Label>
                                <!-- Langkah sepersejuta, bukan seperseratus. Fine-tuning
                                     BERT bergerak di kisaran 0,00002, dan panah naik
                                     turun yang melompat 0,01 akan melewatinya seluruhnya
                                     dalam satu ketukan. -->
                                <Input
                                    id="lr"
                                    v-model.number="form.learning_rate"
                                    type="number"
                                    step="0.000001"
                                    :min="batas.learning_rate.min"
                                    :max="batas.learning_rate.maks"
                                />
                                <p class="angka text-xs text-muted-foreground">{{ ilmiah(form.learning_rate) }}</p>
                                <InputError :message="form.errors.learning_rate" />
                            </div>
                            <div class="space-y-1">
                                <Label for="seq">Maximum sequence length</Label>
                                <Input
                                    id="seq"
                                    v-model.number="form.max_seq_length"
                                    type="number"
                                    :min="batas.max_seq_length.min"
                                    :max="batas.max_seq_length.maks"
                                />
                                <p class="text-xs text-muted-foreground">Token yang dibaca dari tiap artikel. Batas IndoBERT 512.</p>
                                <InputError :message="form.errors.max_seq_length" />
                            </div>
                            <div class="space-y-1">
                                <Label for="seed-latih">Seed</Label>
                                <Input id="seed-latih" v-model.number="form.seed" type="number" min="0" />
                                <p class="text-xs text-muted-foreground">Kosongkan untuk acak.</p>
                                <InputError :message="form.errors.seed" />
                            </div>
                            <div class="space-y-1">
                                <Label for="early">Early stopping</Label>
                                <Input
                                    id="early"
                                    v-model.number="form.early_stopping"
                                    type="number"
                                    :min="batas.early_stopping.min"
                                    :max="batas.early_stopping.maks"
                                />
                                <p class="text-xs text-muted-foreground">
                                    Berhenti setelah sekian epoch tanpa perbaikan. Kosongkan untuk mematikannya.
                                </p>
                                <InputError :message="form.errors.early_stopping" />
                            </div>
                        </div>
                    </section>

                    <p class="text-xs text-muted-foreground">
                        Perangkat: {{ perangkat }}. Pelatihan berjalan di antrean, jadi halaman ini boleh ditutup.
                        <template v-if="layanan?.sedang_melatih">
                            Layanan sedang melatih model lain, dan pelatihan baru akan menunggu gilirannya.
                        </template>
                    </p>

                    <!-- Lewat konfirmasi, bukan langsung kirim. Ini tombol yang
                         menyalakan pekerjaan puluhan menit, memakai seluruh
                         jatah CPU yang disediakan untuk layanan model, dan
                         mengunci antreannya sampai selesai. Salah ketik angka
                         epoch baru ketahuan lima belas menit kemudian.

                         Ungu, bukan navy. Di seluruh panel admin ungu berarti
                         aksi yang menyalakan pekerjaan model dan memakan
                         sumber daya, sama dengan tombol nilai ulang di halaman
                         Berita dan detail artikel. -->
                    <Button
                        :disabled="!siapDikirim"
                        class="tekan bg-aksen-ungu text-white hover:bg-aksen-ungu/90 dark:text-background"
                        @click="akanDilatih = true"
                    >
                        <Loader2 v-if="form.processing" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                        <Play v-else class="size-4" aria-hidden="true" />
                        Mulai Pelatihan
                    </Button>
                </template>
            </CardContent>
        </Card>

        <Card class="muncul overflow-hidden" style="animation-delay: 200ms">
            <CardHeader class="flex-row items-center justify-between gap-2 space-y-0 border-b py-3">
                <div class="flex items-center gap-2">
                    <div class="grid size-7 place-items-center rounded-md bg-muted text-muted-foreground">
                        <History class="size-4" aria-hidden="true" />
                    </div>
                    <CardTitle class="text-sm font-semibold">Riwayat pelatihan</CardTitle>
                </div>
                <span v-if="pelatihan.length" class="angka rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">
                    {{ formatAngka(pelatihan.length) }}
                </span>
            </CardHeader>

            <CardContent class="p-0">
                <KeadaanKosong
                    v-if="pelatihan.length === 0"
                    judul="Belum ada pelatihan"
                    keterangan="Pilih snapshot dan konfigurasi di atas, lalu tekan Mulai Pelatihan."
                />

                <!--
                    Daftar bergaris, bukan kartu berbingkai di dalam kartu.
                    Sebelumnya tiap pelatihan dibungkus `rounded-lg border`, jadi
                    layar ini berisi kartu di dalam kartu dan tiap baris membawa
                    dua tepi yang harus dibaca mata.

                    Model aktif ditandai bidang hijau samar, bukan garis tepi
                    tebal. Bidang berwarna terbaca sebagai keadaan baris, garis
                    tepi tebal terbaca sebagai hiasan yang menempel di sisinya.
                -->
                <ul v-else class="divide-y">
                    <li v-for="p in pelatihan" :key="p.id" class="space-y-3 p-4" :class="p.aktif ? 'bg-sentimen-positif-lembut/40' : ''">
                        <!-- `min-w-0` pada anak flex, dan itu bukan hiasan. Anak flex
                             menolak menyusut di bawah lebar min-content isinya, dan
                             nama checkpoint seperti apriandito/indobert-relevancy-classifier
                             memaksakan lebar yang melampaui layar ponsel. Akibatnya
                             seluruh halaman ikut bisa digeser ke samping, bukan hanya
                             kartu ini. -->
                        <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
                            <div class="min-w-0 flex-1 basis-64">
                                <p class="flex flex-wrap items-center gap-x-2 gap-y-1 font-medium">
                                    <span class="break-words">{{ p.nama }}</span>
                                    <Badge
                                        v-if="p.aktif"
                                        variant="outline"
                                        class="gap-1 rounded-md border-transparent bg-sentimen-positif-lembut font-medium text-sentimen-positif"
                                    >
                                        <Star class="size-3 shrink-0" aria-hidden="true" />
                                        Model aktif
                                    </Badge>
                                </p>
                                <p class="break-words text-xs text-muted-foreground">
                                    {{ p.base_model }} pada snapshot {{ p.snapshot?.nama ?? 'yang sudah dihapus' }}
                                </p>
                                <p class="break-words text-xs text-muted-foreground">
                                    Dimulai {{ waktu(p.mulai_at) }} oleh {{ p.pembuat ?? 'pengguna yang sudah dihapus' }}
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <Badge variant="outline" class="gap-1 rounded-md border-transparent font-medium" :class="WARNA_STATUS[p.status]">
                                    <component
                                        :is="IKON_STATUS[p.status]"
                                        class="size-3 shrink-0"
                                        :class="p.status === 'berjalan' ? 'animate-spin motion-reduce:animate-none' : ''"
                                        aria-hidden="true"
                                    />
                                    {{ LABEL_STATUS[p.status] }}
                                </Badge>
                                <span class="angka whitespace-nowrap text-xs text-muted-foreground">{{ formatDurasi(p.durasi_detik) }}</span>
                            </div>
                        </div>

                        <!-- Kemajuan hanya selama masih berjalan. Batang penuh pada
                             pelatihan yang sudah selesai tidak memberi keterangan
                             apa pun dan hanya menambah baris untuk dibaca. -->
                        <div v-if="belumSelesai(p)" class="space-y-1">
                            <!-- Tanpa `whitespace-nowrap` pada pembungkusnya. Kalimat
                                 "menghitung sisa waktu lagi" ditambah tahap "Epoch 1
                                 dari 3, langkah 12 dari 40" tidak muat berdampingan
                                 di layar ponsel, dan memaksanya satu baris membuat
                                 halaman bisa digeser ke samping. Hanya angka
                                 persennya yang benar-benar tidak boleh terpotong. -->
                            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 text-xs">
                                <span class="min-w-0 break-words">{{ p.tahap ?? 'Menunggu' }}</span>
                                <span class="angka shrink-0">
                                    <span v-if="p.status === 'berjalan'" class="text-muted-foreground">{{ sisa(p) }} lagi &middot;</span>
                                    <span class="whitespace-nowrap font-medium text-aksen-ungu">{{ p.progres }}%</span>
                                </span>
                            </div>
                            <!-- Ungu, bukan biru langit. Batang ini menggambarkan
                                 model yang sedang bekerja, dan ungu berarti itu
                                 di seluruh panel admin. -->
                            <div class="h-2 overflow-hidden rounded-full bg-muted">
                                <div
                                    class="h-full rounded-full bg-aksen-ungu transition-[width] duration-500 ease-out"
                                    :class="p.status === 'menunggu' ? 'animate-pulse motion-reduce:animate-none' : ''"
                                    :style="{ width: `${p.progres}%` }"
                                />
                            </div>
                            <p v-if="p.batal_diminta" class="text-xs text-sentimen-review">
                                Pelatihan dihentikan, daftar ini menyusul dalam beberapa detik. Kalau baris ini tetap berjalan, tekan Hentikan paksa
                                untuk menutupnya.
                            </p>
                        </div>

                        <p
                            v-if="p.galat"
                            class="break-words rounded-md bg-sentimen-negatif-lembut p-2 text-xs text-sentimen-negatif ring-1 ring-inset ring-sentimen-negatif/25"
                        >
                            {{ p.galat }}
                        </p>

                        <!-- Empat angka berbagi satu bidang bergaris. F1 diberi
                             latar bertinta karena ia angka pokoknya. -->
                        <div v-if="p.metrik" class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border bg-border sm:grid-cols-4">
                            <div v-for="m in METRIK" :key="m.kunci" class="p-2.5" :class="m.pokok ? 'bg-brand-lembut' : 'bg-card'">
                                <p class="text-xs" :class="m.pokok ? 'font-medium text-brand dark:text-white' : 'text-muted-foreground'">
                                    {{ m.judul }}
                                </p>
                                <p class="angka text-lg font-semibold" :class="m.pokok ? 'text-brand dark:text-white' : ''">
                                    {{ formatPersen(p.metrik[m.kunci] * 100) }}
                                </p>
                            </div>
                        </div>

                        <!-- Loss per epoch ikut tampil selagi berjalan, bukan hanya
                             setelah selesai. Kurva yang naik pada epoch ketiga
                             adalah alasan paling sering untuk membatalkan sebelum
                             menunggu tujuh belas epoch sisanya. -->
                        <GrafikEpoch v-if="p.riwayat_epoch?.length" :riwayat="p.riwayat_epoch" :tinggi="180" />

                        <!-- Tombol diwarnai menurut apa yang dilakukannya. Biru
                             mengambil berkas, hijau memberlakukan model, kuning
                             menghentikan pekerjaan yang sedang jalan, merah
                             menghapus dan memaksa berhenti. -->
                        <div class="flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" class="tekan h-7 text-xs" @click="detail = p">Lihat detail</Button>

                            <Button
                                v-if="p.status === 'berhasil'"
                                variant="outline"
                                size="sm"
                                class="tekan h-7 border-aksen-biru/40 text-xs text-aksen-biru hover:bg-aksen-biru/10 hover:text-aksen-biru"
                                as="a"
                                :href="`/admin/model-relevansi/pelatihan/${p.id}/evaluasi`"
                            >
                                <Download class="size-3.5" aria-hidden="true" />
                                Unduh evaluasi
                            </Button>

                            <Button
                                v-if="p.status === 'berhasil' && !p.aktif"
                                variant="outline"
                                size="sm"
                                class="tekan h-7 border-sentimen-positif/40 text-xs text-sentimen-positif hover:bg-sentimen-positif-lembut hover:text-sentimen-positif"
                                @click="aktifkan(p)"
                            >
                                <Star class="size-3.5" aria-hidden="true" />
                                Jadikan model aktif
                            </Button>

                            <!-- Tetap muncul meski pembatalan sudah pernah diminta.
                                 Sebelumnya tombol ini menghilang begitu `batal_diminta`
                                 menyala, dan itu masuk akal selama pembatalan selalu
                                 tuntas dalam hitungan detik. Ia berhenti masuk akal
                                 pada baris yang tertinggal berstatus berjalan setelah
                                 layanan di-restart: penandanya menyala, tidak ada yang
                                 menutup barisnya, dan satu-satunya jalan keluar lenyap
                                 bersama tombolnya.

                                 Hentikan paksa diwarnai merah, Batalkan kuning.
                                 Keduanya bukan tindakan yang sama beratnya: yang
                                 pertama menutup baris tanpa menunggu jawaban
                                 layanan. -->
                            <Button
                                v-if="belumSelesai(p)"
                                variant="outline"
                                size="sm"
                                class="tekan h-7 text-xs"
                                :class="
                                    p.batal_diminta
                                        ? 'border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive'
                                        : 'border-sentimen-review/40 text-sentimen-review hover:bg-sentimen-review-lembut hover:text-sentimen-review'
                                "
                                @click="akanDibatalkan = p"
                            >
                                <Ban class="size-3.5" aria-hidden="true" />
                                {{ p.batal_diminta ? 'Hentikan paksa' : 'Batalkan' }}
                            </Button>

                            <Button
                                v-if="!belumSelesai(p) && !p.aktif"
                                variant="ghost"
                                size="sm"
                                class="tekan h-7 text-xs text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                @click="akanDihapus = p"
                            >
                                <Trash2 class="size-3.5" aria-hidden="true" />
                                Hapus
                            </Button>
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <!-- Konfirmasi sebelum pelatihan -->
        <Dialog :open="akanDilatih" @update:open="(nilai) => (akanDilatih = nilai)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Mulai pelatihan {{ form.nama }}?</DialogTitle>
                    <DialogDescription>
                        Periksa sekali lagi. Pelatihan berjalan puluhan menit dan mengunci antrean model sampai selesai, jadi angka yang keliru di
                        sini baru terlihat lama setelah tombolnya ditekan.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-3 text-sm">
                    <div class="rounded-md bg-muted/50 p-3">
                        <p class="text-xs text-muted-foreground">Dataset</p>
                        <p class="break-words font-medium">{{ terpilih?.nama ?? '-' }}</p>
                        <p class="angka mt-1 text-xs text-muted-foreground">
                            {{ formatAngka(terpilih?.total_train ?? 0) }} training, {{ formatAngka(terpilih?.total_validation ?? 0) }} validation,
                            {{ formatAngka(terpilih?.total_test ?? 0) }} testing.
                        </p>
                    </div>

                    <div class="rounded-md bg-muted/50 p-3">
                        <p class="text-xs text-muted-foreground">Base model</p>
                        <p class="break-all font-medium">{{ form.base_model }}</p>
                    </div>

                    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-md border bg-border text-xs sm:grid-cols-3">
                        <div class="min-w-0 bg-card p-2">
                            <dt class="text-muted-foreground">Epoch</dt>
                            <dd class="angka font-medium">{{ form.epoch }}</dd>
                        </div>
                        <div class="min-w-0 bg-card p-2">
                            <dt class="text-muted-foreground">Batch size</dt>
                            <dd class="angka font-medium">{{ form.batch_size }}</dd>
                        </div>
                        <div class="min-w-0 bg-card p-2">
                            <dt class="text-muted-foreground">Learning rate</dt>
                            <dd class="angka break-all font-medium">{{ ilmiah(form.learning_rate) }}</dd>
                        </div>
                        <div class="min-w-0 bg-card p-2">
                            <dt class="text-muted-foreground">Max seq length</dt>
                            <dd class="angka font-medium">{{ form.max_seq_length }}</dd>
                        </div>
                        <div class="min-w-0 bg-card p-2">
                            <dt class="text-muted-foreground">Seed</dt>
                            <dd class="angka font-medium">{{ form.seed ?? 'acak' }}</dd>
                        </div>
                        <div class="min-w-0 bg-card p-2">
                            <dt class="text-muted-foreground">Early stopping</dt>
                            <dd class="angka font-medium">{{ form.early_stopping ?? 'mati' }}</dd>
                        </div>
                    </dl>

                    <p class="angka text-xs text-muted-foreground">
                        {{ formatAngka(langkah) }} langkah gradien di {{ perangkat }}. Perkiraan sisa waktu muncul di daftar begitu langkah pertama
                        lewat.
                    </p>

                    <div
                        v-if="layanan?.sedang_melatih"
                        class="flex items-start gap-2 rounded-md bg-sentimen-review-lembut p-2 text-xs text-sentimen-review ring-1 ring-inset ring-sentimen-review/25"
                    >
                        <TriangleAlert class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                        <p>Layanan sedang melatih model lain. Pelatihan ini akan menunggu gilirannya di antrean.</p>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" :disabled="form.processing" @click="akanDilatih = false">Periksa lagi</Button>
                    <Button :disabled="form.processing" class="bg-aksen-ungu text-white hover:bg-aksen-ungu/90 dark:text-background" @click="mulai">
                        <Loader2 v-if="form.processing" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                        Mulai Pelatihan
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Detail pelatihan -->
        <Dialog :open="detail !== null" @update:open="(nilai) => !nilai && (detail = null)">
            <DialogScrollContent class="max-w-3xl">
                <DialogHeader>
                    <DialogTitle class="break-words">{{ detailSegar?.nama }}</DialogTitle>
                    <DialogDescription class="break-words">
                        {{ detailSegar ? LABEL_STATUS[detailSegar.status] : '' }}. Snapshot {{ detailSegar?.snapshot?.nama ?? 'sudah dihapus' }}.
                        Perangkat {{ detailSegar?.perangkat ?? 'belum tercatat' }}.
                    </DialogDescription>
                </DialogHeader>

                <div v-if="detailSegar" class="space-y-5 text-sm">
                    <section class="space-y-2">
                        <div class="flex items-center gap-2">
                            <h3 class="shrink-0 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Konfigurasi</h3>
                            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                        </div>
                        <!-- `min-w-0` dan `break-all` pada tiap sel. Nilai
                             `base_model` di sini adalah nama checkpoint utuh, dan
                             tanpa keduanya sel gridnya melebar melampaui dialog
                             lalu dialognya melebar melampaui layar. -->
                        <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-md border bg-border text-xs sm:grid-cols-3">
                            <div v-for="(nilai, kunci) in detailSegar.konfigurasi" :key="kunci" class="min-w-0 bg-card p-2">
                                <dt class="text-muted-foreground">{{ kunci }}</dt>
                                <dd class="angka break-all font-medium">{{ nilai ?? 'mati' }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section v-if="detailSegar.metrik" class="space-y-2">
                        <div class="flex items-center gap-2">
                            <h3 class="shrink-0 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Hasil pada data testing</h3>
                            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                        </div>
                        <div class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border bg-border sm:grid-cols-4">
                            <div v-for="m in METRIK" :key="m.kunci" class="p-2.5" :class="m.pokok ? 'bg-brand-lembut' : 'bg-card'">
                                <p class="text-xs" :class="m.pokok ? 'font-medium text-brand dark:text-white' : 'text-muted-foreground'">
                                    {{ m.judul }}
                                </p>
                                <p class="angka text-lg font-semibold" :class="m.pokok ? 'text-brand dark:text-white' : ''">
                                    {{ formatPersen(detailSegar.metrik[m.kunci] * 100) }}
                                </p>
                            </div>
                        </div>
                        <p class="angka text-xs text-muted-foreground">
                            {{ formatAngka(detailSegar.metrik.jumlah_test) }} artikel testing,
                            {{ formatAngka(detailSegar.metrik.jumlah_fitur) }} fitur, epoch terbaik ke-{{ detailSegar.metrik.epoch_terbaik }} dari
                            {{ detailSegar.metrik.epoch_dijalankan }} yang dijalankan.
                        </p>
                    </section>

                    <section v-if="detailSegar.confusion_matrix" class="space-y-2">
                        <div class="flex items-center gap-2">
                            <h3 class="shrink-0 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Confusion matrix</h3>
                            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                        </div>
                        <div class="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Sebenarnya \ Prediksi</TableHead>
                                        <TableHead class="text-right">Relevan</TableHead>
                                        <TableHead class="text-right">Tidak Relevan</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="baris in ['relevan', 'tidak_relevan'] as const" :key="baris">
                                        <TableCell class="font-medium">
                                            {{ baris === 'relevan' ? 'Relevan' : 'Tidak Relevan' }}
                                        </TableCell>
                                        <!-- Diagonal diberi bidang hijau, bukan
                                             sekadar teks hijau. Yang dicari mata di
                                             matriks ini adalah dua sel yang benar,
                                             dan bidang berwarna menemukannya lebih
                                             cepat daripada angka berwarna. -->
                                        <TableCell
                                            class="angka text-right"
                                            :class="baris === 'relevan' ? 'bg-sentimen-positif-lembut font-semibold text-sentimen-positif' : ''"
                                        >
                                            {{ formatAngka(detailSegar.confusion_matrix[baris].relevan) }}
                                        </TableCell>
                                        <TableCell
                                            class="angka text-right"
                                            :class="baris === 'tidak_relevan' ? 'bg-sentimen-positif-lembut font-semibold text-sentimen-positif' : ''"
                                        >
                                            {{ formatAngka(detailSegar.confusion_matrix[baris].tidak_relevan) }}
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Diagonal hijau adalah tebakan yang benar. Baris adalah label sebenarnya, kolom adalah tebakan model.
                        </p>
                    </section>

                    <section v-if="detailSegar.laporan_klasifikasi" class="space-y-2">
                        <div class="flex items-center gap-2">
                            <h3 class="shrink-0 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Classification report</h3>
                            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                        </div>
                        <div class="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Kelas</TableHead>
                                        <TableHead class="text-right">Precision</TableHead>
                                        <TableHead class="text-right">Recall</TableHead>
                                        <TableHead class="text-right">F1</TableHead>
                                        <TableHead class="text-right">Support</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="b in BARIS_LAPORAN" :key="b.kunci">
                                        <TableCell class="font-medium">{{ b.judul }}</TableCell>
                                        <TableCell class="angka text-right">
                                            {{ formatPersen(detailSegar.laporan_klasifikasi[b.kunci].precision * 100) }}
                                        </TableCell>
                                        <TableCell class="angka text-right">
                                            {{ formatPersen(detailSegar.laporan_klasifikasi[b.kunci].recall * 100) }}
                                        </TableCell>
                                        <TableCell class="angka text-right">
                                            {{ formatPersen(detailSegar.laporan_klasifikasi[b.kunci].f1 * 100) }}
                                        </TableCell>
                                        <TableCell class="angka text-right">
                                            {{ formatAngka(detailSegar.laporan_klasifikasi[b.kunci].support) }}
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>
                    </section>

                    <section v-if="detailSegar.riwayat_epoch?.length" class="space-y-2">
                        <div class="flex items-center gap-2">
                            <h3 class="shrink-0 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Metrik per epoch</h3>
                            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                        </div>
                        <GrafikEpoch :riwayat="detailSegar.riwayat_epoch" :tinggi="220" />
                    </section>

                    <section class="space-y-1 text-xs text-muted-foreground">
                        <div class="flex items-center gap-2">
                            <h3 class="shrink-0 text-xs font-semibold uppercase tracking-wide">Berkas dan waktu</h3>
                            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                        </div>
                        <p class="break-all pt-1">Berkas model: {{ detailSegar.artefak_path ?? 'belum tersimpan' }}</p>
                        <p>Mulai: {{ waktu(detailSegar.mulai_at) }}</p>
                        <p>Selesai: {{ waktu(detailSegar.selesai_at) }}</p>
                        <p>Durasi: {{ formatDurasi(detailSegar.durasi_detik) }}</p>
                    </section>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="detail = null">Tutup</Button>
                </DialogFooter>
            </DialogScrollContent>
        </Dialog>

        <Dialog :open="akanDibatalkan !== null" @update:open="(nilai) => !nilai && (akanDibatalkan = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {{ akanDibatalkan?.batal_diminta ? 'Hentikan paksa' : 'Batalkan' }} pelatihan {{ akanDibatalkan?.nama }}?
                    </DialogTitle>
                    <DialogDescription>
                        <template v-if="akanDibatalkan?.batal_diminta">
                            Pembatalan sudah pernah diminta untuk pelatihan ini. Kalau layanan model ternyata sudah tidak mengerjakannya, misalnya
                            karena container-nya sempat di-restart, barisnya akan langsung ditutup sekarang juga.
                        </template>
                        <template v-else>
                            Pelatihan dihentikan seketika, di tahap mana pun ia berada, dan tidak ada model yang tersimpan. Konfigurasinya tetap
                            tercatat sehingga bisa dijalankan ulang dengan nama lain.
                        </template>
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="akanDibatalkan = null">Tutup</Button>
                    <Button variant="destructive" @click="batalkan">
                        {{ akanDibatalkan?.batal_diminta ? 'Hentikan paksa' : 'Batalkan pelatihan' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="akanDihapus !== null" @update:open="(nilai) => !nilai && (akanDihapus = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Hapus pelatihan {{ akanDihapus?.nama }}?</DialogTitle>
                    <DialogDescription>
                        Berkas model, seluruh angka evaluasi, dan riwayat pengujian yang memakainya ikut terhapus. Snapshot datasetnya tidak ikut
                        terhapus, jadi pelatihan ini masih bisa diulang.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="akanDihapus = null">Batal</Button>
                    <Button variant="destructive" @click="hapus">Hapus</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
