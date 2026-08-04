<script setup lang="ts">
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormatAngka } from '@/composables/useFormatAngka';
import { router, useForm } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { AlertTriangle, Lock, ShieldCheck, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

interface Kebocoran {
    jenis: string;
    keterangan: string;
    jumlah: number;
    contoh: string[];
}

interface Snapshot {
    id: number;
    nama: string;
    versi: string;
    deskripsi: string | null;
    status: string;
    strategi_sampling: string;
    random_seed: number;
    versi_panduan_label: string;
    manifest_hash: string | null;
    total_relevan: number;
    total_tidak_relevan: number;
    total_train: number;
    total_validation: number;
    total_test: number;
    pembuat: string | null;
    locked_at: string | null;
    created_at: string;
    kebocoran: Kebocoran[];
}

const props = defineProps<{ snapshot: Snapshot[] }>();

const { formatAngka } = useFormatAngka();

const form = useForm({
    nama: '',
    deskripsi: '',
    strategi_sampling: 'natural_distribution',
    random_seed: 42,
    persen_train: 80,
    persen_validation: 10,
    persen_test: 10,
});

const totalPersen = computed(() => Number(form.persen_train) + Number(form.persen_validation) + Number(form.persen_test));

function buat() {
    form.post('/admin/model-relevansi/snapshot', {
        preserveScroll: true,
        onSuccess: () => form.reset('nama', 'deskripsi'),
    });
}

function kunci(s: Snapshot) {
    if (!confirm(`Kunci snapshot ${s.nama} ${s.versi}? Setelah dikunci susunannya tidak bisa diubah lagi, dan anggota test set ikut terkunci.`))
        return;

    router.post(`/admin/model-relevansi/snapshot/${s.id}/kunci`, {}, { preserveScroll: true });
}

/**
 * Draft yang dihapus bisa dibuat ulang persis sama dari nama, benih, dan porsi
 * yang sama, selama datasetnya belum berubah. Karena itu konfirmasinya cukup
 * satu, tidak perlu mengetik ulang nama snapshot.
 */
function hapus(s: Snapshot) {
    if (!confirm(`Hapus draft snapshot ${s.nama} ${s.versi}? Susunannya bisa dibuat ulang dengan benih ${s.random_seed}.`)) return;

    router.delete(`/admin/model-relevansi/snapshot/${s.id}`, { preserveScroll: true });
}

const waktu = (w: string | null) => (w ? format(new Date(w), 'd MMM yyyy, HH:mm', { locale: id }) : '-');

const namaStrategi: Record<string, string> = {
    natural_distribution: 'Sebaran apa adanya',
    balanced: 'Seimbang per kelas',
    balanced_with_hard_cases: 'Seimbang dengan hard case',
    custom: 'Kustom',
};
</script>

<template>
    <div class="space-y-4">
        <Card>
            <CardContent class="space-y-3 p-4">
                <div>
                    <p class="text-sm font-medium">Buat snapshot baru</p>
                    <p class="text-xs text-muted-foreground">
                        Snapshot membekukan susunan dataset untuk satu eksperimen. Label yang berubah setelahnya tidak akan mengubah eksperimen yang
                        sudah jalan.
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="nama" class="text-xs">Nama snapshot</Label>
                        <Input id="nama" v-model="form.nama" placeholder="relevansi-eksperimen-1" class="h-8" />
                        <p v-if="form.errors.nama" class="text-xs text-sentimen-negatif">{{ form.errors.nama }}</p>
                    </div>

                    <div class="space-y-1">
                        <Label for="strategi" class="text-xs">Strategi sampling</Label>
                        <select id="strategi" v-model="form.strategi_sampling" class="h-8 w-full rounded-md border bg-background px-2 text-sm">
                            <option v-for="(label, nilai) in namaStrategi" :key="nilai" :value="nilai">
                                {{ label }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-4">
                    <div class="space-y-1">
                        <Label for="train" class="text-xs">Train %</Label>
                        <Input id="train" v-model="form.persen_train" type="number" class="h-8" />
                    </div>
                    <div class="space-y-1">
                        <Label for="validation" class="text-xs">Validation %</Label>
                        <Input id="validation" v-model="form.persen_validation" type="number" class="h-8" />
                    </div>
                    <div class="space-y-1">
                        <Label for="test" class="text-xs">Test %</Label>
                        <Input id="test" v-model="form.persen_test" type="number" class="h-8" />
                    </div>
                    <div class="space-y-1">
                        <Label for="seed" class="text-xs">Random seed</Label>
                        <Input id="seed" v-model="form.random_seed" type="number" class="h-8" />
                    </div>
                </div>

                <p v-if="totalPersen !== 100" class="text-xs text-sentimen-negatif">Jumlah ketiganya harus 100, sekarang {{ totalPersen }}.</p>

                <p class="text-[11px] leading-relaxed text-muted-foreground">
                    Pembagian dilakukan per grup duplikat, bukan per baris. Seluruh salinan satu berita jatuh di split yang sama, kalau tidak, model
                    diuji dengan artikel yang salinannya sudah pernah ia pelajari dan angkanya naik secara bohong. Benih acak disimpan supaya snapshot
                    yang sama bisa dibuat ulang.
                </p>

                <Button size="sm" :disabled="form.processing || totalPersen !== 100 || !form.nama" @click="buat"> Buat snapshot </Button>
            </CardContent>
        </Card>

        <KeadaanKosong
            v-if="!snapshot.length"
            judul="Belum ada snapshot"
            keterangan="Buat snapshot pertama untuk membekukan susunan dataset sebelum pelatihan."
        />

        <Card v-for="s in snapshot" :key="s.id">
            <CardContent class="space-y-3 p-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-medium">
                            {{ s.nama }} <span class="text-muted-foreground">{{ s.versi }}</span>
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ namaStrategi[s.strategi_sampling] ?? s.strategi_sampling }}, benih <span class="angka">{{ s.random_seed }}</span
                            >, panduan {{ s.versi_panduan_label }}, oleh {{ s.pembuat ?? '-' }},
                            {{ waktu(s.created_at) }}
                        </p>
                    </div>

                    <Badge v-if="s.status === 'locked'" class="bg-sentimen-positif-lembut text-foreground">
                        <Lock class="mr-1 h-3 w-3" aria-hidden="true" /> Terkunci
                    </Badge>
                    <Badge v-else variant="outline">Draft</Badge>
                </div>

                <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                    <div
                        v-for="k in [
                            { label: 'Train', nilai: s.total_train },
                            { label: 'Validation', nilai: s.total_validation },
                            { label: 'Test', nilai: s.total_test },
                            { label: 'Relevan', nilai: s.total_relevan },
                            { label: 'Tidak relevan', nilai: s.total_tidak_relevan },
                        ]"
                        :key="k.label"
                        class="rounded border p-2"
                    >
                        <p class="text-[11px] text-muted-foreground">{{ k.label }}</p>
                        <p class="angka text-base font-semibold">{{ formatAngka(k.nilai) }}</p>
                    </div>
                </div>

                <!--
                    Kebocoran adalah satu-satunya kesalahan di laboratorium ini
                    yang membuat model terlihat lebih baik daripada sebenarnya,
                    jadi ia menghalangi penguncian, bukan sekadar memperingatkan.
                -->
                <div v-if="s.kebocoran.length" class="space-y-2 rounded-md border-l-4 border-sentimen-negatif bg-sentimen-negatif-lembut p-3">
                    <p class="flex items-center gap-2 text-sm font-medium">
                        <AlertTriangle class="h-4 w-4 text-sentimen-negatif" aria-hidden="true" />
                        {{ s.kebocoran.length }} temuan menghalangi penguncian
                    </p>

                    <div v-for="t in s.kebocoran" :key="t.jenis" class="text-xs">
                        <p class="font-medium">{{ t.keterangan }}</p>
                        <p class="text-muted-foreground">
                            <span class="angka">{{ t.jumlah }}</span> kasus. Contoh: {{ t.contoh.join(', ') }}
                        </p>
                    </div>
                </div>

                <div v-else-if="s.status !== 'locked'" class="flex items-center gap-2 text-xs text-sentimen-positif">
                    <ShieldCheck class="h-4 w-4" aria-hidden="true" />
                    Tidak ada kebocoran terdeteksi. Snapshot siap dikunci.
                </div>

                <p v-if="s.manifest_hash" class="break-all font-mono text-[10px] text-muted-foreground">manifest {{ s.manifest_hash }}</p>

                <!--
                    Menghapus hanya untuk draft. Snapshot terkunci adalah
                    catatan tentang data apa yang dipakai satu eksperimen, dan
                    tombolnya sengaja tidak ada di sana.
                -->
                <div v-if="s.status !== 'locked'" class="flex flex-wrap items-center gap-2">
                    <Button size="sm" variant="outline" :disabled="s.kebocoran.length > 0" @click="kunci(s)">
                        <Lock class="mr-1 h-3 w-3" aria-hidden="true" /> Kunci snapshot
                    </Button>

                    <Button size="sm" variant="ghost" class="text-destructive hover:text-destructive" @click="hapus(s)">
                        <Trash2 class="mr-1 h-3 w-3" aria-hidden="true" /> Hapus draft
                    </Button>
                </div>

                <p v-else class="text-xs text-muted-foreground">Dikunci {{ waktu(s.locked_at) }}.</p>
            </CardContent>
        </Card>
    </div>
</template>
