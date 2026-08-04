<script setup lang="ts">
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router, useForm } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { AlertTriangle, CheckCircle2, Loader2, XCircle } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted } from 'vue';

interface Riwayat {
    id: number;
    nama: string;
    base_model: string;
    status: string;
    progress: number;
    current_epoch: number | null;
    current_step: number | null;
    total_steps: number | null;
    configuration: Record<string, any> | null;
    metrics_validation: Record<string, any> | null;
    metrics_test: Record<string, any> | null;
    error_summary: string | null;
    snapshot: string | null;
    pembuat: string | null;
    started_at: string | null;
    finished_at: string | null;
    selesai: boolean;
    perkiraan: {
        detik_per_langkah: number;
        sisa_detik: number;
        berlalu_detik: number;
        selesai_sekitar: string;
    } | null;
}

const props = defineProps<{
    pelatihan: {
        preset: Record<string, any>;
        snapshot: Array<{ id: number; label: string }>;
        riwayat: Riwayat[];
    };
}>();

const form = useForm({
    nama: '',
    snapshot_dataset_relevansi_id: props.pelatihan.snapshot[0]?.id ?? 0,
    epoch: props.pelatihan.preset.epoch,
    batch_size: props.pelatihan.preset.batch_size,
    gradient_accumulation: props.pelatihan.preset.gradient_accumulation,
    learning_rate: props.pelatihan.preset.learning_rate,
    max_length: props.pelatihan.preset.max_length,
    class_weighting: props.pelatihan.preset.class_weighting,
    random_seed: props.pelatihan.preset.random_seed,
});

const adaYangJalan = computed(() => props.pelatihan.riwayat.some((r) => !r.selesai));

/**
 * Menyegarkan sendiri hanya selama ada pelatihan yang jalan.
 *
 * Polling yang terus hidup pada halaman diam adalah beban yang tidak terlihat
 * dan tidak pernah dimatikan siapa pun. Jedanya sepuluh detik, sama dengan job
 * pemantau di sisi server, jadi menyegarkan lebih cepat tidak memberi angka
 * yang lebih baru.
 */
let timer: number | undefined;

function pantau() {
    if (!adaYangJalan.value) return;

    timer = window.setTimeout(() => {
        router.reload({
            only: ['pelatihan'],
            onFinish: () => pantau(),
        });
    }, 10000);
}

onMounted(pantau);
onUnmounted(() => window.clearTimeout(timer));

function mulai() {
    form.post('/admin/model-relevansi/pelatihan', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('nama');
            pantau();
        },
    });
}

/**
 * Konfirmasi menyebut yang benar-benar hilang, bukan sekadar bertanya ya atau
 * tidak. Membatalkan pada langkah ke-60 dari 75 membuang lebih dari satu jam
 * kerja CPU, dan tidak ada checkpoint yang tersimpan sebelum pelatihan selesai.
 */
function batalkan(r: Riwayat) {
    const berlalu = r.perkiraan ? ` Sudah berjalan ${durasi(r.perkiraan.berlalu_detik)}.` : '';
    const sisa = r.perkiraan ? ` Perkiraan sisa ${durasi(r.perkiraan.sisa_detik)}.` : '';

    if (
        !confirm(
            `Batalkan pelatihan "${r.nama}"?${berlalu}${sisa}\n\n` +
                'Seluruh kemajuannya hilang dan tidak bisa dilanjutkan. ' +
                'Model hanya tersimpan setelah pelatihan selesai.',
        )
    )
        return;

    router.post(`/admin/model-relevansi/pelatihan/${r.id}/batalkan`, {}, { preserveScroll: true });
}

/** Detik menjadi bentuk yang bisa dibaca sekilas. */
function durasi(detik: number): string {
    if (detik < 60) return `${Math.round(detik)} detik`;

    const menit = Math.round(detik / 60);

    if (menit < 60) return `${menit} menit`;

    const bulat = Math.floor(menit / 60);

    return `${bulat} jam ${menit % 60} menit`;
}

const waktu = (w: string | null) => (w ? format(new Date(w), 'd MMM yyyy, HH:mm', { locale: id }) : '-');

const jam = (w: string) => format(new Date(w), 'HH:mm', { locale: id });

const persen = (n: number | null | undefined) => (n === null || n === undefined ? '-' : `${(n * 100).toFixed(1)}%`);

const labelStatus: Record<string, string> = {
    validasi_data: 'Memvalidasi data',
    mengekspor_dataset: 'Mengekspor dataset',
    menunggu: 'Menunggu',
    mempersiapkan_model: 'Memuat model',
    melatih: 'Melatih',
    mengevaluasi_test: 'Mengevaluasi test',
    menyimpan_artefak: 'Menyimpan artefak',
    selesai: 'Selesai',
    gagal: 'Gagal',
    dibatalkan: 'Dibatalkan',
};
</script>

<template>
    <div class="space-y-4">
        <Card>
            <CardContent class="space-y-3 p-4">
                <div>
                    <p class="text-sm font-medium">Mulai pelatihan</p>
                    <p class="text-xs text-muted-foreground">
                        Hanya snapshot terkunci yang bisa dilatih. Pelatihan berjalan di latar, jadi halaman ini boleh ditutup.
                    </p>
                </div>

                <KeadaanKosong
                    v-if="!pelatihan.snapshot.length"
                    judul="Belum ada snapshot terkunci"
                    keterangan="Buat lalu kunci snapshot di tab Snapshot sebelum melatih."
                />

                <template v-else>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label for="nama-latih" class="text-xs">Nama eksperimen</Label>
                            <Input id="nama-latih" v-model="form.nama" placeholder="eksperimen-1" class="h-8" />
                            <p v-if="form.errors.nama" class="text-xs text-sentimen-negatif">{{ form.errors.nama }}</p>
                        </div>

                        <div class="space-y-1">
                            <Label for="snapshot-latih" class="text-xs">Snapshot</Label>
                            <select
                                id="snapshot-latih"
                                v-model="form.snapshot_dataset_relevansi_id"
                                class="h-8 w-full rounded-md border bg-background px-2 text-sm"
                            >
                                <option v-for="s in pelatihan.snapshot" :key="s.id" :value="s.id">{{ s.label }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        <div class="space-y-1">
                            <Label for="epoch" class="text-xs">Epoch</Label>
                            <Input id="epoch" v-model="form.epoch" type="number" step="0.5" class="h-8" />
                        </div>
                        <div class="space-y-1">
                            <Label for="batch" class="text-xs">Batch</Label>
                            <Input id="batch" v-model="form.batch_size" type="number" class="h-8" />
                        </div>
                        <div class="space-y-1">
                            <Label for="akumulasi" class="text-xs">Akumulasi</Label>
                            <Input id="akumulasi" v-model="form.gradient_accumulation" type="number" class="h-8" />
                        </div>
                        <div class="space-y-1">
                            <Label for="lr" class="text-xs">Learning rate</Label>
                            <Input id="lr" v-model="form.learning_rate" type="number" step="0.000001" class="h-8" />
                        </div>
                        <div class="space-y-1">
                            <Label for="panjang" class="text-xs">Maks token</Label>
                            <Input id="panjang" v-model="form.max_length" type="number" class="h-8" />
                        </div>
                        <div class="space-y-1">
                            <Label for="seed-latih" class="text-xs">Seed</Label>
                            <Input id="seed-latih" v-model="form.random_seed" type="number" class="h-8" />
                        </div>
                    </div>

                    <label class="flex items-center gap-2 text-xs">
                        <input v-model="form.class_weighting" type="checkbox" class="h-3.5 w-3.5" />
                        Bobot kelas, dipakai saat datasetnya timpang
                    </label>

                    <p class="text-[11px] leading-relaxed text-muted-foreground">
                        Nilai bawaan berbeda dari dokumen 10 bagian 10.2 dan itu disengaja. Checkpoint yang dipakai ternyata BERT large, 24 layer,
                        sehingga panjang 512 dengan batch 8 menuntut sekitar 10 GB saat melatih. Input kita sudah berupa jendela konteks terfokus,
                        jadi 256 token hampir tidak kehilangan apa pun.
                    </p>

                    <Button size="sm" :disabled="form.processing || adaYangJalan || !form.nama" @click="mulai"> Mulai pelatihan </Button>

                    <p v-if="adaYangJalan" class="text-xs text-muted-foreground">
                        Masih ada pelatihan yang berjalan. Satu pada satu waktu: dua pelatihan bersamaan di CPU yang sama tidak selesai lebih cepat,
                        keduanya justru berebut memori.
                    </p>
                </template>
            </CardContent>
        </Card>

        <KeadaanKosong
            v-if="!pelatihan.riwayat.length"
            judul="Belum ada pelatihan"
            keterangan="Riwayat pelatihan, termasuk yang gagal, akan muncul di sini."
        />

        <Card v-for="r in pelatihan.riwayat" :key="r.id">
            <CardContent class="space-y-3 p-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-medium">{{ r.nama }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ r.snapshot ?? '-' }}, {{ r.base_model }}, oleh {{ r.pembuat ?? '-' }},
                            {{ waktu(r.started_at) }}
                        </p>
                    </div>

                    <Badge
                        :class="
                            r.status === 'selesai'
                                ? 'bg-sentimen-positif-lembut text-foreground'
                                : r.status === 'gagal' || r.status === 'dibatalkan'
                                  ? 'bg-sentimen-negatif-lembut text-foreground'
                                  : ''
                        "
                        :variant="r.selesai ? undefined : 'outline'"
                    >
                        <Loader2 v-if="!r.selesai" class="mr-1 h-3 w-3 animate-spin" aria-hidden="true" />
                        <CheckCircle2 v-else-if="r.status === 'selesai'" class="mr-1 h-3 w-3" aria-hidden="true" />
                        <XCircle v-else class="mr-1 h-3 w-3" aria-hidden="true" />
                        {{ labelStatus[r.status] ?? r.status }}
                    </Badge>
                </div>

                <div v-if="!r.selesai" class="space-y-1">
                    <div class="h-2 overflow-hidden rounded-full bg-muted">
                        <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${r.progress}%` }" />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        <span class="angka">{{ r.progress }}%</span>
                        <template v-if="r.total_steps">
                            , langkah <span class="angka">{{ r.current_step }}/{{ r.total_steps }}</span>
                        </template>
                        <template v-if="r.current_epoch"
                            >, epoch <span class="angka">{{ r.current_epoch }}</span></template
                        >
                    </p>

                    <!--
                        Perkiraan dihitung dari laju yang sudah terjadi, bukan
                        dari angka bawaan. Laju pelatihan di CPU berbeda jauh
                        antar mesin, dan perkiraan yang dikarang lebih
                        menyesatkan daripada tidak ada perkiraan sama sekali.
                    -->
                    <p v-if="r.perkiraan" class="text-xs text-muted-foreground">
                        Berjalan {{ durasi(r.perkiraan.berlalu_detik) }}, perkiraan sisa <strong>{{ durasi(r.perkiraan.sisa_detik) }}</strong
                        >, selesai sekitar {{ jam(r.perkiraan.selesai_sekitar) }}.
                        <span class="text-[11px]"> Dari laju {{ r.perkiraan.detik_per_langkah }} detik per langkah, menajam seiring berjalan. </span>
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        Perkiraan waktu muncul setelah beberapa langkah pertama, saat lajunya sudah terukur.
                    </p>
                </div>

                <div v-if="r.error_summary" class="rounded-md border-l-4 border-sentimen-negatif bg-sentimen-negatif-lembut p-3">
                    <p class="flex items-center gap-2 text-xs font-medium">
                        <AlertTriangle class="h-4 w-4 text-sentimen-negatif" aria-hidden="true" />
                        {{ r.error_summary }}
                    </p>
                </div>

                <!--
                    Metrik test ditampilkan berdampingan dengan validation, dan
                    itu disengaja: perbedaan besar di antara keduanya adalah
                    tanda paling awal model menghafal, bukan belajar.
                -->
                <div v-if="r.metrics_test" class="grid gap-2 sm:grid-cols-2">
                    <div
                        v-for="[judul, m] in [
                            ['Validation', r.metrics_validation],
                            ['Test', r.metrics_test],
                        ]"
                        :key="judul as string"
                    >
                        <p class="mb-1 text-[11px] font-medium text-muted-foreground">{{ judul }}</p>
                        <div class="grid grid-cols-2 gap-1 text-xs">
                            <span>Presisi relevan</span>
                            <span class="angka text-right font-semibold">{{ persen((m as any)?.precision_relevan) }}</span>
                            <span>Recall relevan</span>
                            <span class="angka text-right font-semibold">{{ persen((m as any)?.recall_relevan) }}</span>
                            <span>F1 relevan</span>
                            <span class="angka text-right font-semibold">{{ persen((m as any)?.f1_relevan) }}</span>
                            <span>Macro F1</span>
                            <span class="angka text-right font-semibold">{{ persen((m as any)?.macro_f1) }}</span>
                        </div>
                    </div>
                </div>

                <p v-if="r.status === 'selesai'" class="text-[11px] text-muted-foreground">
                    Tersimpan sebagai model kandidat. Promosi ke produksi menuntut gerbang mutu lulus, dan itu tidak pernah terjadi otomatis.
                </p>

                <Button v-if="!r.selesai" size="sm" variant="ghost" class="text-destructive" @click="batalkan(r)"> Batalkan </Button>
            </CardContent>
        </Card>
    </div>
</template>
