<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useFormatAngka } from '@/composables/useFormatAngka';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Ringkasan {
    kandidat: number;
    belum_dilabeli: number;
    sudah_dilabeli: number;
    relevan: number;
    tidak_relevan: number;
    perlu_review: number;
    dikeluarkan: number;
    hard_positive: number;
    hard_negative: number;
    kelompok_duplikat: number;
    belum_direview: number;
    keseimbangan: {
        status: string;
        persen_relevan: number | null;
        persen_tidak_relevan: number | null;
    };
    kesiapan: {
        tingkat: string;
        target_total: number;
        target_per_kelas: number;
        kurang_total: number;
        kurang_per_kelas: number;
    };
}

const props = defineProps<{ ringkasan: Ringkasan }>();

const { formatAngka } = useFormatAngka();

const kartu = computed(() => [
    { label: 'Kandidat dataset', nilai: props.ringkasan.kandidat },
    { label: 'Belum dilabeli', nilai: props.ringkasan.belum_dilabeli, filter: 'status=belum_dilabeli' },
    { label: 'Sudah dilabeli', nilai: props.ringkasan.sudah_dilabeli, filter: 'status=sudah_dilabeli' },
    { label: 'Relevan', nilai: props.ringkasan.relevan, filter: 'label=relevan' },
    { label: 'Tidak relevan', nilai: props.ringkasan.tidak_relevan, filter: 'label=tidak_relevan' },
    { label: 'Perlu review', nilai: props.ringkasan.perlu_review, filter: 'status=perlu_review' },
    { label: 'Hard positive', nilai: props.ringkasan.hard_positive, filter: 'kesulitan=hard_positive' },
    { label: 'Hard negative', nilai: props.ringkasan.hard_negative, filter: 'kesulitan=hard_negative' },
    { label: 'Dikeluarkan', nilai: props.ringkasan.dikeluarkan, filter: 'dikeluarkan=1' },
    { label: 'Kelompok duplikat', nilai: props.ringkasan.kelompok_duplikat, filter: 'duplikat=1' },
]);

const tingkatKesiapan: Record<string, string> = {
    belum_layak: 'Belum layak',
    eksperimen: 'Layak eksperimen',
    fine_tuning_awal: 'Layak fine-tuning awal',
    kandidat_produksi: 'Layak kandidat produksi',
};

const warnaKeseimbangan: Record<string, string> = {
    seimbang: 'text-sentimen-positif',
    perlu_perhatian: 'text-sentimen-review',
    timpang: 'text-sentimen-negatif',
    belum_ada_data: 'text-muted-foreground',
};

/** Daftar pekerjaan, masing-masing menjadi tautan ke filter yang sesuai. */
const antrean = computed(() =>
    [
        {
            jumlah: props.ringkasan.belum_dilabeli,
            teks: 'kandidat belum dilabeli',
            href: '/admin/model-relevansi?tab=dataset&status=belum_dilabeli',
        },
        {
            jumlah: props.ringkasan.belum_direview,
            teks: 'label lama belum ditinjau dengan kode alasan sekarang',
            href: '/admin/model-relevansi?tab=dataset&belum_direview=1',
        },
        {
            jumlah: props.ringkasan.perlu_review,
            teks: 'sampel dilewati dan menunggu keputusan',
            href: '/admin/model-relevansi?tab=dataset&status=perlu_review',
        },
    ].filter((a) => a.jumlah > 0),
);
</script>

<template>
    <div class="space-y-4">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <Card v-for="k in kartu" :key="k.label">
                <CardContent class="p-3">
                    <p class="text-xs text-muted-foreground">{{ k.label }}</p>
                    <component
                        :is="k.filter ? Link : 'p'"
                        :href="k.filter ? `/admin/model-relevansi?tab=dataset&${k.filter}` : undefined"
                        class="angka block text-xl font-semibold"
                        :class="k.filter ? 'hover:underline' : ''"
                    >
                        {{ formatAngka(k.nilai) }}
                    </component>
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-3 lg:grid-cols-2">
            <Card>
                <CardContent class="space-y-2 p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-medium">Kesiapan data</p>
                        <Badge variant="outline">{{ tingkatKesiapan[ringkasan.kesiapan.tingkat] }}</Badge>
                    </div>

                    <p v-if="ringkasan.kesiapan.kurang_total > 0" class="text-xs text-muted-foreground">
                        Kurang <strong class="angka">{{ formatAngka(ringkasan.kesiapan.kurang_total) }}</strong>
                        artikel unik lagi menuju target
                        <span class="angka">{{ formatAngka(ringkasan.kesiapan.target_total) }}</span
                        >.
                        <template v-if="ringkasan.kesiapan.kurang_per_kelas > 0">
                            Kelas terkecil kurang
                            <strong class="angka">{{ formatAngka(ringkasan.kesiapan.kurang_per_kelas) }}</strong
                            >.
                        </template>
                    </p>

                    <p class="text-[11px] leading-relaxed text-muted-foreground">
                        Jumlah bukan satu-satunya syarat. Snapshot tetap ditolak kalau datanya hanya berisi contoh mudah atau terlalu banyak salinan.
                        Dokumen 10 bagian 9.3.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardContent class="space-y-2 p-4">
                    <p class="text-sm font-medium">Keseimbangan kelas</p>

                    <template v-if="ringkasan.keseimbangan.persen_relevan !== null">
                        <div class="flex items-baseline justify-between text-sm">
                            <span>Relevan</span>
                            <span class="angka font-semibold">{{ ringkasan.keseimbangan.persen_relevan }}%</span>
                        </div>
                        <div class="flex items-baseline justify-between text-sm">
                            <span>Tidak relevan</span>
                            <span class="angka font-semibold"> {{ ringkasan.keseimbangan.persen_tidak_relevan }}% </span>
                        </div>
                        <p class="text-xs font-medium" :class="warnaKeseimbangan[ringkasan.keseimbangan.status]">
                            {{ ringkasan.keseimbangan.status.replace('_', ' ') }}
                        </p>
                        <p v-if="ringkasan.keseimbangan.status === 'timpang'" class="text-[11px] leading-relaxed text-muted-foreground">
                            Salah satu kelas di bawah 35%. Model yang dilatih dari data timpang akan terlihat akurat dengan cara yang salah, yaitu
                            dengan hampir selalu menebak kelas yang lebih banyak.
                        </p>
                    </template>

                    <p v-else class="text-xs text-muted-foreground">Belum ada satu pun sampel berlabel.</p>
                </CardContent>
            </Card>
        </div>

        <Card v-if="antrean.length">
            <CardContent class="space-y-2 p-4">
                <p class="text-sm font-medium">Antrean kerja</p>
                <ul class="space-y-1">
                    <li v-for="a in antrean" :key="a.teks" class="text-sm">
                        <Link :href="a.href" class="hover:underline">
                            <strong class="angka">{{ formatAngka(a.jumlah) }}</strong> {{ a.teks }}
                        </Link>
                    </li>
                </ul>

                <Button as-child size="sm" class="mt-2">
                    <Link href="/admin/model-relevansi?tab=dataset&labeli=1">Mulai melabeli</Link>
                </Button>
            </CardContent>
        </Card>
    </div>
</template>
