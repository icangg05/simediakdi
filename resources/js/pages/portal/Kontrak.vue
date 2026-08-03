<script setup lang="ts">
import ProgresKontrak from '@/components/domain/ProgresKontrak.vue';
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import LayoutPortal from '@/layouts/LayoutPortal.vue';
import { Head, Link } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { AlertTriangle, Send } from 'lucide-vue-next';

interface Progres {
    terverifikasi: number;
    menunggu: number;
    target: number | null;
    persen: number | null;
    sisa_hari: number;
    tertinggal: boolean;
}

interface Pemuatan {
    id: number;
    url: string;
    judul: string | null;
    tanggal_muat: string | null;
    sumber_catatan: string;
    status_verifikasi: string;
    alasan_penolakan: string | null;
    kontrak: { id: number; judul: string } | null;
}

const props = defineProps<{
    kontrak: { id: number; judul: string; nomor: string | null; status: string; tanggal_mulai: string; tanggal_akhir: string; progres: Progres }[];
    pemuatan: Pemuatan[];
    ditolak: Pemuatan[];
}>();

const tanggal = (n: string | null) => (n ? format(new Date(n), 'd MMM yyyy', { locale: id }) : '-');

const labelSumber: Record<string, string> = {
    otomatis: 'Ditemukan sistem',
    laporan_media: 'Laporan Anda',
    input_admin: 'Input admin',
};
</script>

<template>
    <Head title="Kontrak saya" />

    <LayoutPortal judul="Kontrak saya" :breadcrumbs="[{ title: 'Kontrak saya', href: '/portal/kontrak' }]">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-muted-foreground">Realisasi kontrak kerja sama Anda dengan Pemerintah Kota Kendari.</p>
            <Button as-child size="sm">
                <Link href="/portal/lapor">
                    <Send class="mr-1.5 h-4 w-4" />
                    Lapor pemuatan baru
                </Link>
            </Button>
        </div>

        <KeadaanKosong
            v-if="!props.kontrak.length"
            judul="Belum ada kontrak tercatat"
            keterangan="Kontrak dimasukkan oleh admin Diskominfo. Kalau kontrak Anda sudah berjalan tapi belum muncul di sini, hubungi mereka."
        />

        <Card v-for="k in props.kontrak" :key="k.id">
            <CardHeader class="pb-2">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <CardTitle class="text-sm font-medium">{{ k.judul }}</CardTitle>
                    <Badge :variant="k.status === 'aktif' ? 'outline' : 'secondary'" class="capitalize">
                        {{ k.status }}
                    </Badge>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{ k.nomor ?? 'Tanpa nomor' }} · {{ tanggal(k.tanggal_mulai) }} - {{ tanggal(k.tanggal_akhir) }}
                </p>
            </CardHeader>
            <CardContent>
                <ProgresKontrak
                    :terverifikasi="k.progres.terverifikasi"
                    :menunggu="k.progres.menunggu"
                    :target="k.progres.target"
                    :persen="k.progres.persen"
                    :sisa-hari="k.progres.sisa_hari"
                    :tertinggal="k.progres.tertinggal"
                />
            </CardContent>
        </Card>

        <!-- Ditolak dipisah dan ditaruh di atas daftar utama: ini satu-satunya
             bagian halaman yang menuntut tindakan media. -->
        <Card v-if="props.ditolak.length" class="border-destructive/40">
            <CardHeader class="pb-2">
                <CardTitle class="flex items-center gap-2 text-sm font-medium">
                    <AlertTriangle class="h-4 w-4 text-destructive" aria-hidden="true" />
                    Laporan yang ditolak
                </CardTitle>
            </CardHeader>
            <CardContent class="p-0">
                <ul class="divide-y">
                    <li v-for="p in props.ditolak" :key="p.id" class="space-y-1 px-4 py-3">
                        <a :href="p.url" target="_blank" rel="noopener noreferrer" class="text-sm font-medium hover:underline">
                            {{ p.judul ?? p.url }}
                        </a>
                        <p class="text-xs text-muted-foreground">{{ tanggal(p.tanggal_muat) }}</p>
                        <p class="text-xs text-destructive">{{ p.alasan_penolakan }}</p>
                        <Button as-child size="sm" variant="outline" class="mt-1 h-7">
                            <Link href="/portal/lapor">Perbaiki dan laporkan ulang</Link>
                        </Button>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">Pemuatan yang tercatat</CardTitle>
            </CardHeader>
            <CardContent class="p-0">
                <KeadaanKosong
                    v-if="!props.pemuatan.length"
                    judul="Belum ada pemuatan tercatat"
                    keterangan="Berita yang ditemukan crawler tercatat sendiri. Sisanya bisa Anda laporkan lewat halaman lapor."
                />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2 text-left font-medium">Tanggal</th>
                                <th scope="col" class="px-4 py-2 text-left font-medium">Judul</th>
                                <th scope="col" class="px-4 py-2 text-left font-medium">Pencatatan</th>
                                <th scope="col" class="px-4 py-2 text-left font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="p in props.pemuatan" :key="p.id">
                                <td class="whitespace-nowrap px-4 py-2 text-xs text-muted-foreground">
                                    {{ tanggal(p.tanggal_muat) }}
                                </td>
                                <td class="px-4 py-2">
                                    <a :href="p.url" target="_blank" rel="noopener noreferrer" class="hover:underline">
                                        {{ p.judul ?? p.url }}
                                    </a>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-xs text-muted-foreground">
                                    {{ labelSumber[p.sumber_catatan] ?? p.sumber_catatan }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2">
                                    <Badge
                                        :variant="p.status_verifikasi === 'terverifikasi' ? 'outline' : 'secondary'"
                                        class="capitalize"
                                    >
                                        {{ p.status_verifikasi }}
                                    </Badge>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </LayoutPortal>
</template>
