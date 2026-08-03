<script setup lang="ts">
import ChartPeringkatMedia from '@/components/chart/ChartPeringkatMedia.vue';
import PemilihKonteks from '@/components/domain/PemilihKonteks.vue';
import PemilihRentangTanggal from '@/components/domain/PemilihRentangTanggal.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { useFormatAngka } from '@/composables/useFormatAngka';
import { usePeriodeEksekutif } from '@/composables/usePeriodeEksekutif';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Baris {
    id: number;
    nama: string;
    tier: string;
    jumlah_artikel: number;
    jumlah_negatif: number;
    jumlah_netral: number;
    jumlah_positif: number;
}

const props = defineProps<{
    periode: { dari: string; sampai: string };
    konteksId: number | null;
    daftarKonteks: Array<{ id: number; nama: string; utama: boolean }>;
    peringkat: Baris[];
}>();

const { formatAngka, formatProporsi } = useFormatAngka();
const { pindah } = usePeriodeEksekutif(props.periode, props.konteksId, '/eksekutif/media');

// Grafik hanya menampilkan sepuluh teratas; sisanya tetap terbaca di tabel.
const sepuluhTeratas = computed(() => props.peringkat.slice(0, 10));

const warnaTier: Record<string, string> = {
    nasional: 'bg-tier-nasional/10 text-tier-nasional',
    regional: 'bg-tier-regional/10 text-tier-regional',
    lokal: 'bg-tier-lokal/10 text-tier-lokal',
};
</script>

<template>
    <Head title="Peringkat media" />

    <LayoutEksekutif>
        <header class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold">Peringkat media</h1>
            <div class="flex flex-wrap items-center gap-2">
                <PemilihKonteks :daftar="daftarKonteks" :terpilih="konteksId" @ubah="(id) => pindah({ konteks: id })" />
                <PemilihRentangTanggal
                    :dari="periode.dari"
                    :sampai="periode.sampai"
                    @ubah="(dari, sampai) => pindah({ dari, sampai })"
                />
            </div>
        </header>

        <Card>
            <CardContent class="p-4">
                <ChartPeringkatMedia :data="sepuluhTeratas" :tinggi="360" />
            </CardContent>
        </Card>

        <Card>
            <CardContent class="p-4">
                <div v-if="peringkat.length" class="overflow-x-auto rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead class="w-10 text-right">#</TableHead>
                                <TableHead>Media</TableHead>
                                <TableHead>Tier</TableHead>
                                <TableHead class="text-right">Berita</TableHead>
                                <TableHead class="text-right">Negatif</TableHead>
                                <TableHead class="text-right">Netral</TableHead>
                                <TableHead class="text-right">Positif</TableHead>
                                <TableHead class="text-right">Proporsi negatif</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="(m, i) in peringkat" :key="m.id">
                                <TableCell class="angka text-right text-muted-foreground">{{ i + 1 }}</TableCell>
                                <TableCell class="font-medium">{{ m.nama }}</TableCell>
                                <TableCell>
                                    <Badge variant="secondary" :class="warnaTier[m.tier]" class="capitalize">
                                        {{ m.tier }}
                                    </Badge>
                                </TableCell>
                                <TableCell class="angka text-right font-semibold">
                                    {{ formatAngka(m.jumlah_artikel) }}
                                </TableCell>
                                <TableCell class="angka text-right">{{ formatAngka(m.jumlah_negatif) }}</TableCell>
                                <TableCell class="angka text-right">{{ formatAngka(m.jumlah_netral) }}</TableCell>
                                <TableCell class="angka text-right">{{ formatAngka(m.jumlah_positif) }}</TableCell>
                                <TableCell class="angka text-right">
                                    {{
                                        formatProporsi(
                                            m.jumlah_negatif,
                                            m.jumlah_negatif + m.jumlah_netral + m.jumlah_positif,
                                        )
                                    }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>

                <KeadaanKosong
                    v-else
                    judul="Belum ada media yang memuat berita"
                    keterangan="Perlebar rentang tanggal, atau periksa apakah crawler berjalan."
                />
            </CardContent>
        </Card>
    </LayoutEksekutif>
</template>
