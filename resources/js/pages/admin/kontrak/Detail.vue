<script setup lang="ts">
import ProgresKontrak from '@/components/domain/ProgresKontrak.vue';
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { ExternalLink, RefreshCw } from 'lucide-vue-next';

defineProps<{
    kontrak: Record<string, never> & {
        id: number;
        judul: string;
        nomor: string | null;
        jenis: string;
        status: string;
        nilai: string | null;
        tanggal_mulai: string;
        tanggal_akhir: string;
        catatan: string | null;
        media: { id: number; nama: string } | null;
    };
    progres: {
        terverifikasi: number;
        menunggu: number;
        target: number | null;
        persen: number | null;
        sisa_hari: number;
        tertinggal: boolean;
    };
    pemuatan: Array<{
        id: number;
        url: string;
        judul: string | null;
        tanggal_muat: string;
        sumber_catatan: string;
        status_verifikasi: string;
    }>;
}>();

const { formatRupiah } = useFormatAngka();

const tanggal = (n: string) => format(new Date(n), 'd MMM yyyy', { locale: id });

const labelSumber: Record<string, string> = {
    otomatis: 'Ditemukan sistem',
    laporan_media: 'Dilaporkan media',
    input_admin: 'Dimasukkan admin',
};
</script>

<template>
    <Head :title="kontrak.judul" />

    <LayoutAdmin
        :breadcrumbs="[
            { title: 'Kontrak', href: '/admin/kontrak' },
            { title: 'Detail', href: '#' },
        ]"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ kontrak.judul }}</h1>
                <p class="text-sm text-muted-foreground">
                    {{ kontrak.media?.nama }} · {{ kontrak.nomor ?? 'tanpa nomor' }} · {{ tanggal(kontrak.tanggal_mulai) }} -
                    {{ tanggal(kontrak.tanggal_akhir) }}
                </p>
            </div>
            <div class="flex gap-2">
                <Button variant="outline" size="sm" @click="router.post(`/admin/kontrak/${kontrak.id}/cocokkan`, {}, { preserveScroll: true })">
                    <RefreshCw class="mr-1.5 h-3.5 w-3.5" />
                    Cocokkan ulang
                </Button>
                <Button size="sm" as-child>
                    <Link :href="`/admin/kontrak/${kontrak.id}/edit`">Ubah</Link>
                </Button>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader class="pb-2"><CardTitle class="text-base">Realisasi</CardTitle></CardHeader>
                <CardContent>
                    <ProgresKontrak
                        besar
                        :terverifikasi="progres.terverifikasi"
                        :menunggu="progres.menunggu"
                        :target="progres.target"
                        :persen="progres.persen"
                        :sisa-hari="progres.sisa_hari"
                        :tertinggal="progres.tertinggal"
                    />
                    <p class="mt-3 text-xs text-muted-foreground">
                        Pemuatan yang ditemukan sistem langsung terverifikasi, tidak ada klaim pihak berkepentingan yang perlu diperiksa. Yang butuh
                        verifikasi manusia hanya laporan dari media.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardContent class="space-y-2 p-4 text-sm">
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Status</span>
                        <Badge class="capitalize">{{ kontrak.status }}</Badge>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Jenis</span>
                        <span class="capitalize">{{ kontrak.jenis }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Nilai</span>
                        <span class="angka">{{ formatRupiah(kontrak.nilai) }}</span>
                    </div>
                    <p v-if="kontrak.catatan" class="border-t pt-2 text-xs text-muted-foreground">
                        {{ kontrak.catatan }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader class="pb-2"><CardTitle class="text-base">Pemuatan tercatat</CardTitle></CardHeader>
            <CardContent>
                <div v-if="pemuatan.length" class="max-h-96 overflow-auto rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Judul</TableHead>
                                <TableHead class="w-32">Tanggal muat</TableHead>
                                <TableHead class="w-40">Sumber catatan</TableHead>
                                <TableHead class="w-32">Verifikasi</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="p in pemuatan" :key="p.id">
                                <TableCell>
                                    <a :href="p.url" target="_blank" rel="noopener noreferrer" class="inline-flex items-start gap-1 hover:underline">
                                        <span class="line-clamp-1">{{ p.judul ?? p.url }}</span>
                                        <ExternalLink class="mt-0.5 h-3 w-3 shrink-0 text-muted-foreground" />
                                    </a>
                                </TableCell>
                                <TableCell class="text-muted-foreground">{{ tanggal(p.tanggal_muat) }}</TableCell>
                                <TableCell class="text-xs">{{ labelSumber[p.sumber_catatan] ?? p.sumber_catatan }}</TableCell>
                                <TableCell>
                                    <Badge :variant="p.status_verifikasi === 'terverifikasi' ? 'outline' : 'secondary'" class="capitalize">
                                        {{ p.status_verifikasi }}
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>

                <KeadaanKosong
                    v-else
                    judul="Belum ada pemuatan tercatat"
                    keterangan="Pastikan status kontrak aktif dan periodenya mencakup tanggal artikel, lalu tekan Cocokkan ulang."
                />
            </CardContent>
        </Card>
    </LayoutAdmin>
</template>
