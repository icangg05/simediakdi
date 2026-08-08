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
import { Loader2, Trash2, TriangleAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type { Kandidat, Snapshot } from './tipe';

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

const WARNA_STATUS: Record<string, string> = {
    siap: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
    terpakai: 'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200',
    arsip: 'bg-neutral-200 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200',
};
</script>

<template>
    <div class="space-y-4">
        <!-- Ringkasan kandidat -->
        <Card>
            <CardHeader class="pb-3">
                <CardTitle class="text-base">Kandidat dataset</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <p class="text-muted-foreground">
                    Artikel yang sudah dinilai Gemini, keputusannya sudah pasti, dan isinya sekurangnya
                    {{ formatAngka(kandidat.min_panjang_isi) }} karakter. Artikel yang masih menunggu penilaian atau berstatus perlu review tidak
                    ikut, karena keduanya belum punya label yang bisa dilatihkan.
                </p>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border p-3">
                        <p class="text-xs text-muted-foreground">Total kandidat</p>
                        <p class="angka text-2xl font-semibold">{{ formatAngka(kandidat.total) }}</p>
                    </div>
                    <div class="rounded-lg border p-3">
                        <p class="text-xs text-muted-foreground">Relevan</p>
                        <p class="angka text-2xl font-semibold text-emerald-600 dark:text-emerald-400">
                            {{ formatAngka(kandidat.relevan) }}
                        </p>
                        <p class="text-xs text-muted-foreground">{{ formatPersen(kandidat.persen_relevan) }}</p>
                    </div>
                    <div class="rounded-lg border p-3">
                        <p class="text-xs text-muted-foreground">Tidak Relevan</p>
                        <p class="angka text-2xl font-semibold text-red-600 dark:text-red-400">
                            {{ formatAngka(kandidat.tidak_relevan) }}
                        </p>
                        <p class="text-xs text-muted-foreground">{{ formatPersen(kandidat.persen_tidak_relevan) }}</p>
                    </div>
                </div>

                <!-- Batang distribusi. Angka persen sudah ada di atas, batang ini
                     hanya membuat ketimpangannya terlihat tanpa dibaca. -->
                <div
                    v-if="kandidat.total > 0"
                    class="flex h-2 overflow-hidden rounded-full"
                    role="img"
                    :aria-label="`Distribusi kandidat: relevan ${kandidat.persen_relevan} persen, tidak relevan ${kandidat.persen_tidak_relevan} persen`"
                >
                    <div class="bg-emerald-500" :style="{ width: `${kandidat.persen_relevan}%` }" />
                    <div class="bg-red-500" :style="{ width: `${kandidat.persen_tidak_relevan}%` }" />
                </div>
            </CardContent>
        </Card>

        <!-- Form pembuatan snapshot -->
        <Card>
            <CardHeader class="pb-3">
                <CardTitle class="text-base">Buat snapshot dataset</CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
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

                <div class="space-y-2 rounded-lg border p-3">
                    <p class="font-medium">Komposisi label</p>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="space-y-1">
                            <Label for="jumlah">Jumlah total artikel</Label>
                            <Input id="jumlah" v-model.number="form.jumlah_total" type="number" :min="MIN_TOTAL" :max="maksimum" />
                            <p class="text-xs text-muted-foreground">Maksimum {{ formatAngka(maksimum) }} pada komposisi ini.</p>
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
                            <p class="angka text-xs text-muted-foreground">Perkiraan {{ formatAngka(estimasi.relevan) }} artikel</p>
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

                    <p class="text-xs text-muted-foreground">
                        Total komposisi {{ totalKomposisi }} persen. Data diambil acak dari kandidat, dan seed di bawah membuat pengambilan yang sama
                        bisa diulang.
                    </p>
                </div>

                <div class="space-y-2 rounded-lg border p-3">
                    <p class="font-medium">Pembagian dataset</p>

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

                    <p :class="['text-xs', totalPembagian === 100 ? 'text-muted-foreground' : 'font-medium text-red-600 dark:text-red-400']">
                        Total pembagian {{ totalPembagian }} persen. Pembagian dilakukan stratified, jadi proporsi Relevan dan Tidak Relevan tetap
                        sama di ketiga bagian.
                    </p>
                </div>

                <div
                    v-if="halangan"
                    class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                >
                    <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    <p>{{ halangan }}</p>
                </div>

                <Button :disabled="form.processing || halangan !== null" @click="simpan">
                    <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" aria-hidden="true" />
                    Buat Snapshot Dataset
                </Button>
            </CardContent>
        </Card>

        <!-- Daftar snapshot -->
        <Card>
            <CardHeader class="pb-3">
                <CardTitle class="text-base">Snapshot tersimpan</CardTitle>
            </CardHeader>
            <CardContent class="px-0 sm:px-6">
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
                            <TableRow v-for="s in snapshot" :key="s.id">
                                <TableCell class="font-medium">
                                    {{ s.nama }}
                                    <p v-if="s.deskripsi" class="text-xs font-normal text-muted-foreground">
                                        {{ s.deskripsi }}
                                    </p>
                                </TableCell>
                                <TableCell class="angka text-right">{{ formatAngka(s.total) }}</TableCell>
                                <TableCell class="angka text-right">
                                    {{ formatAngka(s.total_relevan) }}
                                    <span class="text-xs text-muted-foreground">({{ s.persen_relevan }}%)</span>
                                </TableCell>
                                <TableCell class="angka text-right">
                                    {{ formatAngka(s.total_tidak_relevan) }}
                                    <span class="text-xs text-muted-foreground">({{ s.persen_tidak_relevan }}%)</span>
                                </TableCell>
                                <TableCell class="angka whitespace-nowrap">
                                    {{ formatAngka(s.total_train) }} / {{ formatAngka(s.total_validation) }} /
                                    {{ formatAngka(s.total_test) }}
                                </TableCell>
                                <TableCell class="angka text-right">{{ s.random_seed }}</TableCell>
                                <TableCell>
                                    <Badge class="capitalize" :class="WARNA_STATUS[s.status]">{{ s.status }}</Badge>
                                    <p v-if="s.pelatihan_count > 0" class="mt-1 text-xs text-muted-foreground">{{ s.pelatihan_count }} pelatihan</p>
                                </TableCell>
                                <TableCell class="whitespace-nowrap text-xs text-muted-foreground">
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
                                        class="h-7"
                                        :disabled="s.pelatihan_count > 0"
                                        :aria-label="
                                            s.pelatihan_count > 0
                                                ? `Snapshot ${s.nama} tidak bisa dihapus, masih dipakai ${s.pelatihan_count} pelatihan`
                                                : `Hapus snapshot ${s.nama}`
                                        "
                                        @click="akanDihapus = s"
                                    >
                                        <Trash2 class="h-4 w-4" aria-hidden="true" />
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
