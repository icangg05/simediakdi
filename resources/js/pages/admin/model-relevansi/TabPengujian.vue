<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useFormatAngka } from '@/composables/useFormatAngka';
import type { SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { Loader2, TriangleAlert } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import type { Pelatihan, RiwayatUji } from './tipe';

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

function uji() {
    form.post('/admin/model-relevansi/uji', { preserveScroll: true });
}

function reset() {
    form.teks = '';
    form.clearErrors();
}

const waktu = (nilai: string | null) => (nilai ? format(new Date(nilai), 'd MMM yyyy HH:mm:ss', { locale: id }) : '-');

const WARNA_LABEL: Record<string, string> = {
    relevan: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
    tidak_relevan: 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200',
};

const SEBUTAN_LABEL: Record<string, string> = {
    relevan: 'Relevan',
    tidak_relevan: 'Tidak Relevan',
};
</script>

<template>
    <div class="space-y-4">
        <Card>
            <CardHeader class="pb-3">
                <CardTitle class="text-base">Uji model dengan teks sendiri</CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <div
                    v-if="siap.length === 0"
                    class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                >
                    <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    <p>Belum ada model yang selesai dilatih. Jalankan pelatihan di tab Pelatihan terlebih dahulu.</p>
                </div>

                <template v-else>
                    <div class="space-y-1">
                        <Label for="model-uji">Model yang diuji</Label>
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
                        <Label for="teks-uji">Judul, isi berita, atau gabungan keduanya</Label>
                        <!-- Textarea polos, bukan komponen ui. Proyek ini belum
                             punya pembungkus textarea, dan satu kotak teks tidak
                             cukup alasan untuk membuatnya. -->
                        <textarea
                            id="teks-uji"
                            v-model="form.teks"
                            rows="8"
                            class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            placeholder="Tempelkan judul di baris pertama, lalu isi beritanya di bawahnya."
                        />
                        <div class="flex items-center justify-between text-xs text-muted-foreground">
                            <span>Sekurangnya 20 karakter.</span>
                            <span class="angka">{{ formatAngka(form.teks.length) }} karakter</span>
                        </div>
                        <InputError :message="form.errors.teks" />
                    </div>

                    <div class="flex gap-2">
                        <Button :disabled="form.processing || form.teks.trim().length < 20" @click="uji">
                            <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" aria-hidden="true" />
                            Uji Model
                        </Button>
                        <Button variant="outline" :disabled="form.processing" @click="reset">Reset</Button>
                    </div>
                </template>
            </CardContent>
        </Card>

        <!-- Hasil prediksi -->
        <Card v-if="hasil">
            <CardHeader class="pb-3">
                <CardTitle class="text-base">Hasil prediksi</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <div class="flex flex-wrap items-center gap-3">
                    <Badge :class="[WARNA_LABEL[hasil.label], 'px-3 py-1 text-base']">
                        {{ SEBUTAN_LABEL[hasil.label] }}
                    </Badge>
                    <span class="angka text-sm text-muted-foreground"> Confidence {{ formatPersen(hasil.confidence * 100) }} </span>
                </div>

                <div class="grid gap-2 sm:grid-cols-2">
                    <div class="space-y-1 rounded-md border p-3">
                        <div class="flex items-center justify-between text-xs">
                            <span>Relevan</span>
                            <span class="angka font-medium">{{ formatPersen(hasil.probabilitas_relevan * 100) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-muted">
                            <div class="h-full bg-emerald-500" :style="{ width: `${hasil.probabilitas_relevan * 100}%` }" />
                        </div>
                    </div>
                    <div class="space-y-1 rounded-md border p-3">
                        <div class="flex items-center justify-between text-xs">
                            <span>Tidak Relevan</span>
                            <span class="angka font-medium">
                                {{ formatPersen(hasil.probabilitas_tidak_relevan * 100) }}
                            </span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-muted">
                            <div class="h-full bg-red-500" :style="{ width: `${hasil.probabilitas_tidak_relevan * 100}%` }" />
                        </div>
                    </div>
                </div>

                <div
                    v-if="ragu"
                    class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                >
                    <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    <p>
                        Confidence di bawah {{ formatPersen(AMBANG_RAGU * 100) }}. Kedua label hampir sama kuat, jadi hasil ini perlu ditinjau manual
                        sebelum dipakai sebagai dasar keputusan.
                    </p>
                </div>

                <dl class="grid gap-2 text-xs text-muted-foreground sm:grid-cols-4">
                    <div>
                        <dt>Model</dt>
                        <dd class="font-medium text-foreground">{{ hasil.model }}</dd>
                    </div>
                    <div>
                        <dt>Versi base model</dt>
                        <dd class="font-medium text-foreground">{{ hasil.base_model }}</dd>
                    </div>
                    <div>
                        <dt>Waktu inferensi</dt>
                        <dd class="angka font-medium text-foreground">{{ formatAngka(hasil.inferensi_ms) }} ms</dd>
                    </div>
                    <div>
                        <dt>Waktu pengujian</dt>
                        <dd class="font-medium text-foreground">{{ waktu(hasil.diuji_at) }}</dd>
                    </div>
                </dl>
            </CardContent>
        </Card>

        <!-- Riwayat pengujian -->
        <Card>
            <CardHeader class="pb-3">
                <CardTitle class="text-base">Riwayat pengujian</CardTitle>
            </CardHeader>
            <CardContent class="px-0 sm:px-6">
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
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="r in riwayat" :key="r.id">
                                <TableCell class="max-w-md">
                                    <p class="line-clamp-2 text-xs">{{ r.potongan }}</p>
                                </TableCell>
                                <TableCell>
                                    <Badge :class="WARNA_LABEL[r.label_prediksi]">
                                        {{ SEBUTAN_LABEL[r.label_prediksi] }}
                                    </Badge>
                                </TableCell>
                                <TableCell class="angka text-right" :class="r.confidence < AMBANG_RAGU ? 'text-amber-600 dark:text-amber-400' : ''">
                                    {{ formatPersen(r.confidence * 100) }}
                                </TableCell>
                                <TableCell class="text-xs">
                                    {{ r.model ?? 'sudah dihapus' }}
                                    <p class="text-muted-foreground">{{ r.base_model }}</p>
                                </TableCell>
                                <TableCell class="angka text-right text-xs">{{ formatAngka(r.inferensi_ms) }} ms</TableCell>
                                <TableCell class="whitespace-nowrap text-xs text-muted-foreground">
                                    {{ waktu(r.diuji_at) }}
                                    <p>{{ r.penguji ?? '-' }}</p>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
