<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { useFormatAngka } from '@/composables/useFormatAngka';
import VChart from 'vue-echarts';
import { computed, ref } from 'vue';

/**
 * Pembungkus tunggal seluruh grafik.
 *
 * Setiap grafik lewat sini supaya keadaan kosong, keadaan memuat, unduh PNG,
 * dan tampilan tabel berperilaku sama di semua halaman. Yang paling penting
 * yang terakhir: tanpa "lihat sebagai tabel", grafik tidak bisa dibaca pembaca
 * layar maupun dipakai orang yang perlu angka persisnya.
 */
const props = defineProps<{
    opsi: Record<string, unknown>;
    judul: string;
    tinggi?: number;
    memuat?: boolean;
    /** Kolom tabel alternatif. Tanpa ini tombol tabel tidak ditampilkan. */
    kolomTabel?: string[];
    barisTabel?: Array<Array<string | number>>;
    keteranganKosong?: string;
}>();

const { formatAngka } = useFormatAngka();

const modeTabel = ref(false);
const grafik = ref<InstanceType<typeof VChart> | null>(null);

const kosong = computed(() => !props.barisTabel?.length);

function unduhPng() {
    const url = grafik.value?.getDataURL({ pixelRatio: 2, backgroundColor: '#fff' });

    if (!url) return;

    const tautan = document.createElement('a');
    tautan.href = url;
    tautan.download = `${props.judul.toLowerCase().replace(/\s+/g, '-')}.png`;
    tautan.click();
}
</script>

<template>
    <figure class="space-y-2">
        <figcaption class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-medium">{{ judul }}</h3>

            <div v-if="!kosong" class="flex gap-1">
                <Button
                    v-if="kolomTabel?.length"
                    variant="ghost"
                    size="sm"
                    class="h-7 text-xs"
                    :aria-pressed="modeTabel"
                    @click="modeTabel = !modeTabel"
                >
                    {{ modeTabel ? 'Lihat sebagai grafik' : 'Lihat sebagai tabel' }}
                </Button>
                <Button v-if="!modeTabel" variant="ghost" size="sm" class="h-7 text-xs" @click="unduhPng">
                    Unduh PNG
                </Button>
            </div>
        </figcaption>

        <Skeleton v-if="memuat" :style="{ height: `${tinggi ?? 240}px` }" class="w-full" />

        <KeadaanKosong
            v-else-if="kosong"
            judul="Belum ada data pada rentang ini"
            :keterangan="keteranganKosong ?? 'Coba perlebar rentang tanggal, atau tunggu crawler mengumpulkan berita.'"
        />

        <div v-else-if="modeTabel" class="max-h-80 overflow-auto rounded-md border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead v-for="(kolom, i) in kolomTabel" :key="kolom" :class="i > 0 ? 'text-right' : ''">
                            {{ kolom }}
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="(baris, i) in barisTabel" :key="i">
                        <TableCell
                            v-for="(sel, j) in baris"
                            :key="j"
                            :class="j > 0 ? 'angka text-right' : 'font-medium'"
                        >
                            {{ typeof sel === 'number' ? formatAngka(sel) : sel }}
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <VChart
            v-else
            ref="grafik"
            :option="opsi"
            :style="{ height: `${tinggi ?? 240}px` }"
            autoresize
            class="w-full"
        />
    </figure>
</template>
