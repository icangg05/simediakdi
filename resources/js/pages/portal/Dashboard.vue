<script setup lang="ts">
import KartuArtikel from '@/components/domain/KartuArtikel.vue';
import KartuKpi from '@/components/domain/KartuKpi.vue';
import ProgresKontrak from '@/components/domain/ProgresKontrak.vue';
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import LayoutPortal from '@/layouts/LayoutPortal.vue';
import { Head, Link } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { Send } from 'lucide-vue-next';

interface Progres {
    terverifikasi: number;
    menunggu: number;
    target: number | null;
    persen: number | null;
    sisa_hari: number;
    tertinggal: boolean;
}

const props = defineProps<{
    kpi: { artikel_30_hari: number; pemuatan_tercatat: number; menunggu_verifikasi: number; ditolak: number };
    kontrak: { id: number; judul: string; tanggal_akhir: string; progres: Progres }[];
    beritaTerbaru: { id: number; judul: string; url: string; diambil_at: string }[];
}>();
</script>

<template>
    <Head title="Portal media" />

    <LayoutPortal judul="Portal media">
        <p class="text-sm text-muted-foreground">
            Berita Anda yang tertangkap sistem, realisasi kontrak, dan pelaporan pemuatan.
        </p>

        <div class="grid grid-cols-2 gap-3">
            <KartuKpi label="Berita terpantau (30 hari)" :nilai="props.kpi.artikel_30_hari" />
            <KartuKpi label="Pemuatan terverifikasi" :nilai="props.kpi.pemuatan_tercatat" />
            <KartuKpi
                label="Menunggu verifikasi"
                :nilai="props.kpi.menunggu_verifikasi"
                keterangan="Sedang diperiksa admin Diskominfo"
            />
            <KartuKpi
                label="Laporan ditolak"
                :nilai="props.kpi.ditolak"
                keterangan="Alasannya ada di halaman kontrak"
            />
        </div>

        <div class="flex flex-wrap gap-2">
            <Button as-child size="sm">
                <Link href="/portal/lapor">
                    <Send class="mr-1.5 h-4 w-4" />
                    Lapor pemuatan baru
                </Link>
            </Button>
            <Button as-child size="sm" variant="outline">
                <Link href="/portal/kontrak">Lihat kontrak saya</Link>
            </Button>
        </div>

        <Card v-if="props.kontrak.length">
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">Kontrak aktif</CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <div v-for="k in props.kontrak" :key="k.id" class="space-y-1.5">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="text-sm font-medium">{{ k.judul }}</p>
                        <span class="shrink-0 text-xs text-muted-foreground">
                            sampai {{ format(new Date(k.tanggal_akhir), 'd MMM yyyy', { locale: id }) }}
                        </span>
                    </div>
                    <ProgresKontrak
                        :terverifikasi="k.progres.terverifikasi"
                        :menunggu="k.progres.menunggu"
                        :target="k.progres.target"
                        :persen="k.progres.persen"
                        :sisa-hari="k.progres.sisa_hari"
                        :tertinggal="k.progres.tertinggal"
                    />
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">Berita terbaru dari media Anda</CardTitle>
            </CardHeader>
            <CardContent class="p-0">
                <KeadaanKosong
                    v-if="!props.beritaTerbaru.length"
                    judul="Belum ada berita yang tertangkap"
                    keterangan="Sistem membaca RSS media Anda tiap 15 menit. Kalau berita baru tidak muncul juga dalam sehari, kabari Diskominfo agar sumber feed-nya diperiksa."
                />
                <ul v-else class="divide-y px-4">
                    <li v-for="b in props.beritaTerbaru" :key="b.id">
                        <!-- tampilkanSentimen sengaja tidak dipasang. Portal
                             media tidak pernah menampilkan nada berita. -->
                        <KartuArtikel :judul="b.judul" :url="b.url" :media="null" :diambil-at="b.diambil_at" />
                    </li>
                </ul>
            </CardContent>
        </Card>
    </LayoutPortal>
</template>
