<script setup lang="ts">
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import PemilihRentangTanggal from '@/components/domain/PemilihRentangTanggal.vue';
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useFormatAngka } from '@/composables/useFormatAngka';
import { useGerbangSentimen } from '@/composables/useGerbangSentimen';
import { usePeriodeEksekutif } from '@/composables/usePeriodeEksekutif';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

type Label = 'negatif' | 'netral' | 'positif';

const props = defineProps<{
    periode: { dari: string; sampai: string };
    istilah: Array<{
        istilah: string;
        frekuensi: number;
        jumlah_artikel: number;
        skor_lonjakan: number | null;
        sentimen_dominan: Label | null;
    }>;
}>();

const { formatAngka } = useFormatAngka();
const { sentimenTersedia } = useGerbangSentimen();
const { pindah, kueri } = usePeriodeEksekutif(props.periode, '/eksekutif/isu');

/**
 * Word cloud diganti daftar sepuluh teratas di layar sempit, word cloud tidak
 * terbaca di sana dan hanya menghabiskan bandwidth (dokumen 04 C.5). Di sini
 * daftar dipakai untuk semua ukuran; word cloud menunggu echarts-wordcloud.
 */
const naikTajam = computed(() => [...props.istilah].filter((i) => (i.skor_lonjakan ?? 0) >= 2).slice(0, 10));
</script>

<template>
    <Head title="Isu hangat" />

    <LayoutEksekutif>
        <header class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold">Isu hangat</h1>
            <div class="flex flex-wrap items-center gap-2">
                <PemilihRentangTanggal :dari="periode.dari" :sampai="periode.sampai" @ubah="(dari, sampai) => pindah({ dari, sampai })" />
            </div>
        </header>

        <Card v-if="naikTajam.length">
            <CardContent class="p-4">
                <h2 class="mb-2 text-sm font-medium">Naik tajam</h2>
                <p class="mb-3 text-xs text-muted-foreground">
                    Dibandingkan rata-rata empat periode sebelumnya. Lonjakan tanpa nada dominan tidak bermakna. Kolom sentimen yang menjelaskan
                    apakah kenaikan itu perlu ditindaklanjuti.
                </p>
                <ul class="divide-y">
                    <li v-for="i in naikTajam" :key="i.istilah" class="flex items-center gap-3 py-2">
                        <Link
                            :href="`/eksekutif/berita?${kueri({ istilah: i.istilah })}`"
                            class="min-w-0 flex-1 truncate text-sm font-medium capitalize hover:underline"
                        >
                            {{ i.istilah }}
                        </Link>
                        <span class="angka shrink-0 text-sm font-semibold">{{ i.skor_lonjakan }}×</span>
                        <span class="angka shrink-0 text-xs text-muted-foreground"> {{ formatAngka(i.jumlah_artikel) }} berita </span>
                        <BadgeSentimen v-if="sentimenTersedia" :label="i.sentimen_dominan" class="shrink-0" />
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Card>
            <CardContent class="p-4">
                <h2 class="mb-3 text-sm font-medium">Seluruh istilah</h2>

                <div v-if="istilah.length" class="max-h-[32rem] overflow-auto rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Istilah</TableHead>
                                <TableHead class="text-right">Berita</TableHead>
                                <TableHead class="text-right">Kemunculan</TableHead>
                                <TableHead class="text-right">Lonjakan</TableHead>
                                <TableHead class="text-right">Nada dominan</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="i in istilah" :key="i.istilah">
                                <TableCell>
                                    <Link :href="`/eksekutif/berita?${kueri({ istilah: i.istilah })}`" class="font-medium capitalize hover:underline">
                                        {{ i.istilah }}
                                    </Link>
                                </TableCell>
                                <TableCell class="angka text-right">{{ formatAngka(i.jumlah_artikel) }}</TableCell>
                                <TableCell class="angka text-right text-muted-foreground">
                                    {{ formatAngka(i.frekuensi) }}
                                </TableCell>
                                <TableCell class="angka text-right">
                                    {{ i.skor_lonjakan === null ? '-' : `${i.skor_lonjakan}×` }}
                                </TableCell>
                                <TableCell class="text-right">
                                    <BadgeSentimen v-if="sentimenTersedia" :label="i.sentimen_dominan" class="ml-auto" />
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>

                <KeadaanKosong
                    v-else
                    judul="Belum ada istilah terhitung"
                    keterangan="Jalankan hitung:kata-kunci, atau perlebar rentang tanggalnya."
                />
            </CardContent>
        </Card>
    </LayoutEksekutif>
</template>
