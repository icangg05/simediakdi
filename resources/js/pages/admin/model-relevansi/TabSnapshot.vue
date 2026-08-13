<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useFormatAngka } from '@/composables/useFormatAngka';
import { router, useForm } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { Database, Loader2, Scissors, SlidersHorizontal, ThumbsDown, ThumbsUp, Trash2, TriangleAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type { Kandidat, Snapshot } from './tipe';
import { IKON_SNAPSHOT, WARNA_SNAPSHOT } from './tipe';

const props = defineProps<{ kandidat: Kandidat; snapshot: Snapshot[] }>();

const { formatAngka, formatPersen } = useFormatAngka();

/** Sama dengan MIN_TOTAL dan MIN_PER_LABEL di SimpanSnapshotRelevansiRequest. */
const MIN_TOTAL = 50;
const MIN_PER_LABEL = 10;

const form = useForm({
    nama: '',
    deskripsi: '',
    jumlah_total: Math.min(500, props.kandidat.total),
    persen_relevan: 50,
    persen_tidak_relevan: 50,
    persen_train: 80,
    persen_validation: 10,
    persen_test: 10,
    random_seed: 42 as number | null,
});

/**
 * Persentase label saling mengunci. Dua kotak yang bisa disetel bebas berarti
 * pengguna harus menghitung sendiri agar jumlahnya seratus, dan hampir setiap
 * pengiriman pertama akan ditolak server karena meleset satu.
 */
function setelRelevan(nilai: number) {
    const bersih = Math.max(10, Math.min(90, Math.round(nilai) || 0));
    form.persen_relevan = bersih;
    form.persen_tidak_relevan = 100 - bersih;
}

/** Estimasi jumlah baris per label, dihitung dengan rumus yang sama seperti server. */
const estimasi = computed(() => {
    const total = Number(form.jumlah_total) || 0;
    const relevan = Math.round((total * form.persen_relevan) / 100);

    return { relevan, tidak_relevan: total - relevan };
});

/**
 * Total terbesar yang masih bisa dipenuhi kedua label pada komposisi sekarang.
 *
 * Angka ini yang paling sering dicari saat validasi menolak, jadi ia
 * ditampilkan sebelum tombolnya ditekan, bukan sesudahnya.
 */
const maksimum = computed(() => {
    const r = form.persen_relevan;
    const t = form.persen_tidak_relevan;

    if (r === 0 || t === 0) return 0;

    return Math.floor(Math.min((props.kandidat.relevan * 100) / r, (props.kandidat.tidak_relevan * 100) / t));
});

const pembagian = computed(() => {
    // Stratified: tiap label dibagi terpisah dengan rasio yang sama, lalu
    // hasilnya dijumlahkan. Menghitungnya dari total saja akan meleset satu dua
    // baris dari yang benar-benar dibuat server.
    const per = (jumlah: number) => {
        const validation = Math.floor((jumlah * form.persen_validation) / 100);
        const test = Math.floor((jumlah * form.persen_test) / 100);

        return { train: jumlah - validation - test, validation, test };
    };

    const a = per(estimasi.value.relevan);
    const b = per(estimasi.value.tidak_relevan);

    return {
        train: a.train + b.train,
        validation: a.validation + b.validation,
        test: a.test + b.test,
    };
});

const totalKomposisi = computed(() => form.persen_relevan + form.persen_tidak_relevan);
const totalPembagian = computed(() => Number(form.persen_train) + Number(form.persen_validation) + Number(form.persen_test));

/**
 * Pratinjau pembagian sebagai satu batang bertiga warna.
 *
 * Training, validation, dan testing bukan tiga hal sejajar yang berdiri
 * sendiri, ia satu himpunan yang dipotong tiga. Tiga kotak angka menyembunyikan
 * itu, satu batang yang terbagi menunjukkannya. Batang ini juga yang paling
 * cepat memberitahu bahwa jumlahnya belum seratus persen, karena bagiannya
 * langsung terlihat tidak menutup lebar penuh.
 */
const potongan = computed(() => [
    {
        kunci: 'train',
        judul: 'Training',
        persen: Number(form.persen_train) || 0,
        jumlah: pembagian.value.train,
        kelas: 'bg-brand dark:bg-brand-terang',
    },
    {
        kunci: 'validation',
        judul: 'Validation',
        persen: Number(form.persen_validation) || 0,
        jumlah: pembagian.value.validation,
        kelas: 'bg-aksen-biru',
    },
    { kunci: 'test', judul: 'Testing', persen: Number(form.persen_test) || 0, jumlah: pembagian.value.test, kelas: 'bg-aksen-ungu' },
]);

/** Alasan tombol dikunci, dalam kalimat yang bisa dibaca pengguna. */
const halangan = computed<string | null>(() => {
    if (props.kandidat.total === 0) {
        return 'Belum ada kandidat dataset. Jalankan klasifikasi Gemini pada artikel terlebih dahulu.';
    }

    if (!form.nama.trim()) return 'Isi nama snapshot.';

    if (totalPembagian.value !== 100) {
        return `Pembagian training, validation, dan testing berjumlah ${totalPembagian.value} persen, harus tepat 100 persen.`;
    }

    if (Number(form.jumlah_total) < MIN_TOTAL) {
        return `Jumlah total sekurangnya ${MIN_TOTAL} artikel.`;
    }

    if (estimasi.value.relevan > props.kandidat.relevan) {
        return `Butuh ${formatAngka(estimasi.value.relevan)} artikel Relevan, tersedia ${formatAngka(props.kandidat.relevan)}. Turunkan jumlah total menjadi ${formatAngka(maksimum.value)} atau kurang.`;
    }

    if (estimasi.value.tidak_relevan > props.kandidat.tidak_relevan) {
        return `Butuh ${formatAngka(estimasi.value.tidak_relevan)} artikel Tidak Relevan, tersedia ${formatAngka(props.kandidat.tidak_relevan)}. Turunkan jumlah total menjadi ${formatAngka(maksimum.value)} atau kurang.`;
    }

    if (estimasi.value.relevan < MIN_PER_LABEL || estimasi.value.tidak_relevan < MIN_PER_LABEL) {
        return `Setiap label harus mendapat sekurangnya ${MIN_PER_LABEL} artikel.`;
    }

    return null;
});

function simpan() {
    form.post('/admin/model-relevansi/snapshot', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('nama', 'deskripsi');
        },
    });
}

const akanDihapus = ref<Snapshot | null>(null);

function hapus() {
    if (!akanDihapus.value) return;

    router.delete(`/admin/model-relevansi/snapshot/${akanDihapus.value.id}`, {
        preserveScroll: true,
        onFinish: () => (akanDihapus.value = null),
    });
}

const waktu = (nilai: string | null) => (nilai ? format(new Date(nilai), 'd MMM yyyy HH:mm', { locale: id }) : '-');
</script>

<template>
    <div class="space-y-4">
        <!-- Ringkasan kandidat -->
        <Card class="muncul overflow-hidden" style="animation-delay: 120ms">
            <CardHeader class="flex-row items-center gap-2 space-y-0 border-b py-3">
                <div class="grid size-7 place-items-center rounded-md bg-aksen-biru/10 text-aksen-biru">
                    <Database class="size-4" aria-hidden="true" />
                </div>
                <CardTitle class="text-sm font-semibold">Kandidat dataset</CardTitle>
            </CardHeader>

            <CardContent class="space-y-4 pt-4">
                <p class="max-w-[75ch] text-sm text-muted-foreground">
                    Artikel yang sudah dinilai Gemini, keputusannya sudah pasti, dan isinya sekurangnya
                    {{ formatAngka(kandidat.min_panjang_isi) }} karakter. Artikel yang masih menunggu penilaian atau berstatus perlu review tidak
                    ikut, karena keduanya belum punya label yang bisa dilatihkan.
                </p>

                <!-- Tiga angka berbagi satu bidang bergaris, bukan tiga kotak
                     berbingkai di dalam kartu. Titik warna di sebelah label
                     adalah keterangan batang di bawahnya. -->
                <div class="grid grid-cols-1 gap-px overflow-hidden rounded-lg border bg-border sm:grid-cols-3">
                    <div class="space-y-0.5 bg-card p-3">
                        <p class="text-xs text-muted-foreground">Total kandidat</p>
                        <p class="angka text-2xl font-semibold">{{ formatAngka(kandidat.total) }}</p>
                    </div>
                    <div class="space-y-0.5 bg-card p-3">
                        <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <ThumbsUp class="size-3 shrink-0 text-aksen-toska" aria-hidden="true" />
                            Relevan
                        </p>
                        <p class="angka text-2xl font-semibold text-aksen-toska">{{ formatAngka(kandidat.relevan) }}</p>
                        <p class="angka text-xs text-muted-foreground">{{ formatPersen(kandidat.persen_relevan) }}</p>
                    </div>
                    <div class="space-y-0.5 bg-card p-3">
                        <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <ThumbsDown class="size-3 shrink-0" aria-hidden="true" />
                            Tidak Relevan
                        </p>
                        <p class="angka text-2xl font-semibold text-muted-foreground">{{ formatAngka(kandidat.tidak_relevan) }}</p>
                        <p class="angka text-xs text-muted-foreground">{{ formatPersen(kandidat.persen_tidak_relevan) }}</p>
                    </div>
                </div>

                <!--
                    Batang distribusi. Angka persen sudah ada di atas, batang ini
                    hanya membuat ketimpangannya terlihat tanpa dibaca.

                    Toska untuk Relevan dan abu untuk Tidak Relevan, bukan hijau
                    dan merah seperti sebelumnya. Merah di seluruh panel admin
                    berarti kabar buruk atau kegagalan, dan artikel yang tidak
                    membahas Pemkot bukan keduanya. Ia cuma di luar cakupan, dan
                    dataset yang seimbang justru membutuhkannya sebanyak yang
                    relevan.
                -->
                <div
                    v-if="kandidat.total > 0"
                    class="flex h-2 overflow-hidden rounded-full bg-muted"
                    role="img"
                    :aria-label="`Distribusi kandidat: relevan ${kandidat.persen_relevan} persen, tidak relevan ${kandidat.persen_tidak_relevan} persen`"
                >
                    <div class="tumbuh bg-aksen-toska" :style="{ width: `${kandidat.persen_relevan}%` }" />
                    <div class="tumbuh bg-muted-foreground/40" :style="{ width: `${kandidat.persen_tidak_relevan}%`, animationDelay: '120ms' }" />
                </div>
            </CardContent>
        </Card>

        <!-- Form pembuatan snapshot -->
        <Card class="muncul overflow-hidden" style="animation-delay: 180ms">
            <CardHeader class="flex-row items-center gap-2 space-y-0 border-b py-3">
                <div class="grid size-7 place-items-center rounded-md bg-brand-lembut text-brand dark:text-white">
                    <Scissors class="size-4" aria-hidden="true" />
                </div>
                <CardTitle class="text-sm font-semibold">Buat snapshot dataset</CardTitle>
            </CardHeader>

            <CardContent class="space-y-5 pt-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="nama">Nama snapshot</Label>
                        <Input id="nama" v-model="form.nama" placeholder="Contoh: seimbang-1000-v1" />
                        <InputError :message="form.errors.nama" />
                    </div>
                    <div class="space-y-1">
                        <Label for="deskripsi">Keterangan</Label>
                        <Input id="deskripsi" v-model="form.deskripsi" placeholder="Opsional" />
                        <InputError :message="form.errors.deskripsi" />
                    </div>
                </div>

                <!-- Dua kelompok setelan dipisahkan judul bergaris, bukan kotak
                     berbingkai di dalam kartu. Kartu di dalam kartu menambah
                     satu tepi yang harus dibaca mata tanpa menambah satu pun
                     keterangan. -->
                <section class="space-y-3">
                    <div class="flex items-center gap-2">
                        <SlidersHorizontal class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <h3 class="shrink-0 text-xs font-semibold tracking-wide text-muted-foreground uppercase">Komposisi label</h3>
                        <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="space-y-1">
                            <Label for="jumlah">Jumlah total artikel</Label>
                            <Input id="jumlah" v-model.number="form.jumlah_total" type="number" :min="MIN_TOTAL" :max="maksimum" />
                            <p class="angka text-xs text-muted-foreground">Maksimum {{ formatAngka(maksimum) }} pada komposisi ini.</p>
                            <InputError :message="form.errors.jumlah_total" />
                        </div>
                        <div class="space-y-1">
                            <Label for="persen-relevan">Relevan (%)</Label>
                            <Input
                                id="persen-relevan"
                                :model-value="form.persen_relevan"
                                type="number"
                                min="10"
                                max="90"
                                @update:model-value="setelRelevan(Number($event))"
                            />
                            <p class="angka text-xs text-aksen-toska">Perkiraan {{ formatAngka(estimasi.relevan) }} artikel</p>
                            <InputError :message="form.errors.persen_relevan" />
                        </div>
                        <div class="space-y-1">
                            <Label for="persen-tidak">Tidak Relevan (%)</Label>
                            <!-- Terkunci, mengikuti kotak sebelahnya. Total wajib
                                 tepat 100 dan dua kotak bebas hanya membuat
                                 pengguna menghitung sendiri. -->
                            <Input id="persen-tidak" :model-value="form.persen_tidak_relevan" type="number" disabled />
                            <p class="angka text-xs text-muted-foreground">Perkiraan {{ formatAngka(estimasi.tidak_relevan) }} artikel</p>
                            <InputError :message="form.errors.persen_tidak_relevan" />
                        </div>
                    </div>

                    <div class="flex h-1.5 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                        <div class="bg-aksen-toska transition-[width] duration-300 ease-out" :style="{ width: `${form.persen_relevan}%` }" />
                        <div
                            class="bg-muted-foreground/40 transition-[width] duration-300 ease-out"
                            :style="{ width: `${form.persen_tidak_relevan}%` }"
                        />
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Total komposisi <span class="angka">{{ totalKomposisi }}</span> persen. Data diambil acak dari kandidat, dan seed di bawah
                        membuat pengambilan yang sama bisa diulang.
                    </p>
                </section>

                <section class="space-y-3">
                    <div class="flex items-center gap-2">
                        <Scissors class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <h3 class="shrink-0 text-xs font-semibold tracking-wide text-muted-foreground uppercase">Pembagian dataset</h3>
                        <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-4">
                        <div class="space-y-1">
                            <Label for="train">Training (%)</Label>
                            <Input id="train" v-model.number="form.persen_train" type="number" min="50" max="90" />
                            <p class="angka text-xs text-muted-foreground">{{ formatAngka(pembagian.train) }} artikel</p>
                            <InputError :message="form.errors.persen_train" />
                        </div>
                        <div class="space-y-1">
                            <Label for="validation">Validation (%)</Label>
                            <Input id="validation" v-model.number="form.persen_validation" type="number" min="5" max="40" />
                            <p class="angka text-xs text-muted-foreground">{{ formatAngka(pembagian.validation) }} artikel</p>
                            <InputError :message="form.errors.persen_validation" />
                        </div>
                        <div class="space-y-1">
                            <Label for="test">Testing (%)</Label>
                            <Input id="test" v-model.number="form.persen_test" type="number" min="5" max="40" />
                            <p class="angka text-xs text-muted-foreground">{{ formatAngka(pembagian.test) }} artikel</p>
                            <InputError :message="form.errors.persen_test" />
                        </div>
                        <div class="space-y-1">
                            <Label for="seed">Seed</Label>
                            <Input id="seed" v-model.number="form.random_seed" type="number" min="0" />
                            <p class="text-xs text-muted-foreground">Kosongkan untuk acak.</p>
                            <InputError :message="form.errors.random_seed" />
                        </div>
                    </div>

                    <!-- Pratinjau potongan. Satu himpunan yang dibelah tiga,
                         digambar sebagai satu batang yang terbelah tiga. Kalau
                         jumlahnya belum seratus persen, batangnya tidak menutup
                         lebar penuh dan itu terlihat sebelum angkanya dibaca. -->
                    <div class="space-y-1.5">
                        <div class="flex h-2 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                            <div
                                v-for="p in potongan"
                                :key="p.kunci"
                                class="transition-[width] duration-300 ease-out"
                                :class="p.kelas"
                                :style="{ width: `${p.persen}%` }"
                            />
                        </div>
                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            <span v-for="p in potongan" :key="p.kunci" class="inline-flex items-center gap-1.5">
                                <span class="size-2 shrink-0 rounded-full" :class="p.kelas" aria-hidden="true"></span>
                                {{ p.judul }} <span class="angka font-medium text-foreground">{{ formatAngka(p.jumlah) }}</span>
                            </span>
                        </div>
                    </div>

                    <p :class="['text-xs', totalPembagian === 100 ? 'text-muted-foreground' : 'font-medium text-sentimen-negatif']">
                        Total pembagian <span class="angka">{{ totalPembagian }}</span> persen. Pembagian dilakukan stratified, jadi proporsi Relevan
                        dan Tidak Relevan tetap sama di ketiga bagian.
                    </p>
                </section>

                <!-- Kuning berarti menunggu keputusan atau menunggu syarat
                     terpenuhi, sama dengan lencana Menunggu di daftar
                     pelatihan. Ini bukan galat, ini daftar yang belum lengkap. -->
                <div
                    v-if="halangan"
                    class="flex items-start gap-2 rounded-lg bg-sentimen-review-lembut p-3 text-sm text-sentimen-review ring-1 ring-sentimen-review/25 ring-inset"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <p>{{ halangan }}</p>
                </div>

                <Button :disabled="form.processing || halangan !== null" class="tekan" @click="simpan">
                    <Loader2 v-if="form.processing" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                    Buat Snapshot Dataset
                </Button>
            </CardContent>
        </Card>

        <!-- Daftar snapshot -->
        <Card class="muncul overflow-hidden" style="animation-delay: 240ms">
            <CardHeader class="flex-row items-center justify-between gap-2 space-y-0 border-b py-3">
                <CardTitle class="text-sm font-semibold">Snapshot tersimpan</CardTitle>
                <span v-if="snapshot.length" class="angka rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">
                    {{ formatAngka(snapshot.length) }}
                </span>
            </CardHeader>

            <CardContent class="p-0">
                <KeadaanKosong
                    v-if="snapshot.length === 0"
                    judul="Belum ada snapshot"
                    keterangan="Tentukan komposisi dan pembagian di atas, lalu tekan Buat Snapshot Dataset."
                />

                <div v-else class="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nama</TableHead>
                                <TableHead class="text-right">Total</TableHead>
                                <TableHead class="text-right">Relevan</TableHead>
                                <TableHead class="text-right">Tidak Relevan</TableHead>
                                <TableHead>Pembagian</TableHead>
                                <TableHead class="text-right">Seed</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Dibuat</TableHead>
                                <TableHead class="text-right">Aksi</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="s in snapshot" :key="s.id" class="transition-colors hover:bg-muted/40">
                                <TableCell class="font-medium">
                                    {{ s.nama }}
                                    <p v-if="s.deskripsi" class="text-xs font-normal text-muted-foreground">
                                        {{ s.deskripsi }}
                                    </p>
                                </TableCell>
                                <TableCell class="angka text-right">{{ formatAngka(s.total) }}</TableCell>
                                <TableCell class="angka text-right text-aksen-toska">
                                    {{ formatAngka(s.total_relevan) }}
                                    <span class="text-xs opacity-70">({{ s.persen_relevan }}%)</span>
                                </TableCell>
                                <TableCell class="angka text-right text-muted-foreground">
                                    {{ formatAngka(s.total_tidak_relevan) }}
                                    <span class="text-xs opacity-70">({{ s.persen_tidak_relevan }}%)</span>
                                </TableCell>
                                <!-- Batang mini menggantikan tiga angka yang
                                     dipisah garis miring. Angkanya tetap ada di
                                     bawahnya, yang bertambah cuma bentuk yang
                                     bisa dibandingkan antar baris tanpa dibaca. -->
                                <TableCell class="min-w-28">
                                    <div class="flex h-1.5 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                                        <div class="bg-brand dark:bg-brand-terang" :style="{ width: `${s.persen_train}%` }" />
                                        <div class="bg-aksen-biru" :style="{ width: `${s.persen_validation}%` }" />
                                        <div class="bg-aksen-ungu" :style="{ width: `${s.persen_test}%` }" />
                                    </div>
                                    <p class="angka mt-1 text-xs whitespace-nowrap text-muted-foreground">
                                        {{ formatAngka(s.total_train) }} / {{ formatAngka(s.total_validation) }} / {{ formatAngka(s.total_test) }}
                                    </p>
                                </TableCell>
                                <TableCell class="angka text-right">{{ s.random_seed }}</TableCell>
                                <TableCell>
                                    <Badge variant="outline" class="gap-1 rounded-md border-transparent capitalize" :class="WARNA_SNAPSHOT[s.status]">
                                        <component :is="IKON_SNAPSHOT[s.status]" class="size-3 shrink-0" aria-hidden="true" />
                                        {{ s.status }}
                                    </Badge>
                                    <p v-if="s.pelatihan_count > 0" class="angka mt-1 text-xs text-muted-foreground">
                                        {{ s.pelatihan_count }} pelatihan
                                    </p>
                                </TableCell>
                                <TableCell class="text-xs whitespace-nowrap text-muted-foreground">
                                    {{ waktu(s.dibuat_at) }}
                                    <p v-if="s.pembuat">{{ s.pembuat }}</p>
                                </TableCell>
                                <!--
                                    Tombol dimatikan, bukan dibiarkan hidup lalu ditolak server. Server
                                    tetap menolaknya, dan itu yang menjaga aturannya. Yang berubah di sini
                                    hanya urutan pemberitahuan: sebelumnya penolakan baru terbaca sesudah
                                    dialog konfirmasi ditutup dan halaman termuat ulang, seolah tindakannya
                                    sempat jalan. Alasannya tidak perlu tooltip, jumlah pelatihan sudah
                                    tertulis di kolom status pada baris yang sama.
                                -->
                                <TableCell class="text-right">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="h-7 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                        :disabled="s.pelatihan_count > 0"
                                        :aria-label="
                                            s.pelatihan_count > 0
                                                ? `Snapshot ${s.nama} tidak bisa dihapus, masih dipakai ${s.pelatihan_count} pelatihan`
                                                : `Hapus snapshot ${s.nama}`
                                        "
                                        @click="akanDihapus = s"
                                    >
                                        <Trash2 class="size-4" aria-hidden="true" />
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>

        <Dialog :open="akanDihapus !== null" @update:open="(nilai) => !nilai && (akanDihapus = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Hapus snapshot {{ akanDihapus?.nama }}?</DialogTitle>
                    <DialogDescription>
                        Seluruh isi snapshot ikut terhapus dan tidak bisa dikembalikan. Membuat ulang dengan seed
                        {{ akanDihapus?.random_seed }} akan menghasilkan susunan yang sama selama kandidatnya belum berubah.
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
