<script setup lang="ts">
import IndikatorKesehatan from '@/components/domain/IndikatorKesehatan.vue';
import KartuKpi from '@/components/domain/KartuKpi.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { formatDistanceToNow } from 'date-fns';
import { id } from 'date-fns/locale';
import { TriangleAlert } from 'lucide-vue-next';
import { onMounted, onUnmounted } from 'vue';

interface StatusKesehatan {
    status: 'hijau' | 'kuning' | 'merah';
    keterangan: string;
}

const props = defineProps<{
    ekstraksi: {
        mentah: number;
        masuk_hari_ini: number;
        diekstrak_hari_ini: number;
        persen: number;
        laju_per_menit: number;
        estimasi_selesai_at: string | null;
        belum_klasifikasi: number;
        antre_ai: number;
        gagal: number;
    };
    kpi: {
        artikel_hari_ini: number;
        artikel_pekan_ini: number;
        selisih_pekan_lalu: number;
        gagal_proses: number;
        sumber_aktif: number;
    };
    kesehatan: { crawler: StatusKesehatan; sumber: StatusKesehatan; gemini: StatusKesehatan };
    proporsiSumber: {
        otomatis: number;
        laporan_media: number;
        input_admin: number;
        total: number;
        persen_mandiri: number;
        melewati_ambang: boolean;
    };
    sumberBermasalah: Array<{
        id: number;
        nama: string;
        gagal_berturut: number;
        aktif: boolean;
        pesan_error_terakhir: string | null;
        media: { nama: string } | null;
    }>;
    artikelTerbaru: Array<{
        id: number;
        judul: string;
        url: string;
        diambil_at: string;
        media: { nama: string } | null;
    }>;
}>();

const { formatAngka, formatPersen } = useFormatAngka();

const sejak = (waktu: string) => formatDistanceToNow(new Date(waktu), { addSuffix: true, locale: id });

/** CSS memakai titik desimal; formatPersen() memakai koma dan tidak sah di sini. */
const lebar = (bagian: number, total: number) => (total === 0 ? '0%' : `${((bagian / total) * 100).toFixed(2)}%`);

/** Satu desimal, koma sebagai pemisah, mengikuti penulisan angka Indonesia. */
const formatLaju = (nilai: number) => nilai.toFixed(1).replace('.', ',');

// Menyegarkan hanya bagian ekstraksi, dan hanya selama masih ada artikel
// mentah. Polling yang jalan terus pada dashboard yang menganggur adalah kueri
// tiap lima detik selamanya, untuk angka yang tidak berubah.
//
// Lima detik, bukan sepuluh. Ekstraksi menghabiskan puluhan artikel per menit,
// jadi angka yang tertinggal sepuluh detik sudah terasa salah saat dipandangi.
// Antrean AI di kartu yang sama bergerak sekitar satu artikel per menit dan
// tidak butuh serapat itu; ia ikut tersegarkan karena kebetulan satu kartu.
let pewaktu: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    pewaktu = setInterval(() => {
        if (props.ekstraksi.mentah === 0) return;
        router.reload({ only: ['ekstraksi'] });
    }, 5_000);
});

onUnmounted(() => clearInterval(pewaktu));
</script>

<template>
    <Head title="Dashboard admin" />

    <LayoutAdmin judul="Dashboard">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <KartuKpi label="Berita hari ini" :nilai="kpi.artikel_hari_ini" keterangan="Sejak pukul 00.00 WITA" />
            <KartuKpi
                label="Berita 7 hari terakhir"
                :nilai="kpi.artikel_pekan_ini"
                :selisih="kpi.selisih_pekan_lalu"
                satuan-pembanding="dari pekan sebelumnya"
            />
            <KartuKpi label="Sumber feed aktif" :nilai="kpi.sumber_aktif" :keterangan="`${kpi.gagal_proses} artikel gagal diproses`" />
        </div>

        <!--
            Hanya dirender saat ada pekerjaan tertunda, baik ekstraksi maupun
            penilaian. Kartu bertuliskan "tidak ada antrean" menghabiskan ruang
            untuk mengabarkan ketiadaan.
        -->
        <Card v-if="ekstraksi.mentah > 0 || ekstraksi.antre_ai > 0">
            <CardHeader class="pb-3">
                <CardTitle class="text-base">Ekstraksi artikel mentah</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="text-sm">
                        <span class="angka text-2xl font-semibold">{{ formatAngka(ekstraksi.mentah) }}</span>
                        <span class="text-muted-foreground"> artikel mentah menunggu diekstraksi</span>
                    </p>
                    <p class="angka text-sm text-muted-foreground">
                        {{ formatAngka(ekstraksi.diekstrak_hari_ini) }} dari
                        {{ formatAngka(ekstraksi.masuk_hari_ini) }} selesai hari ini
                        ({{ formatPersen(ekstraksi.persen) }})
                    </p>
                </div>

                <div class="h-2 overflow-hidden rounded-full bg-muted">
                    <div class="h-full bg-primary transition-all" :style="{ width: `${ekstraksi.persen}%` }" />
                </div>

                <dl class="grid gap-2 text-xs sm:grid-cols-3">
                    <div class="rounded border p-2">
                        <dt class="text-muted-foreground">Laju ekstraksi</dt>
                        <dd class="angka text-base font-semibold">
                            {{ formatLaju(ekstraksi.laju_per_menit) }}
                            <span class="text-xs font-normal text-muted-foreground">artikel/menit</span>
                        </dd>
                    </div>
                    <div class="rounded border p-2">
                        <dt class="text-muted-foreground">Perkiraan selesai</dt>
                        <dd class="text-base font-semibold">
                            <template v-if="ekstraksi.estimasi_selesai_at">{{ sejak(ekstraksi.estimasi_selesai_at) }}</template>
                            <template v-else-if="ekstraksi.mentah === 0">Sudah selesai</template>
                            <template v-else>Belum bisa dihitung</template>
                        </dd>
                    </div>
                    <div class="rounded border p-2">
                        <dt class="text-muted-foreground">Belum diklasifikasi</dt>
                        <dd class="angka text-base font-semibold">
                            {{ formatAngka(ekstraksi.belum_klasifikasi) }}
                            <span class="text-xs font-normal text-muted-foreground">
                                ({{ formatAngka(ekstraksi.antre_ai) }} antre AI)
                            </span>
                        </dd>
                    </div>
                </dl>

                <p class="text-xs text-muted-foreground">
                    Angka menyegarkan sendiri tiap lima detik selama masih ada artikel mentah. Perkiraan
                    dihitung dari laju sepuluh menit terakhir, jadi ia bergerak mengikuti kecepatan
                    server media yang sedang ditarik. Artikel yang selesai diekstraksi langsung mengantre
                    penilaian relevansi.
                    <span v-if="ekstraksi.gagal > 0" class="text-sentimen-negatif">
                        {{ formatAngka(ekstraksi.gagal) }} artikel gagal diproses.
                    </span>
                </p>
            </CardContent>
        </Card>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card>
                <CardHeader class="pb-3"><CardTitle class="text-base">Kesehatan sistem</CardTitle></CardHeader>
                <CardContent class="space-y-3">
                    <IndikatorKesehatan label="Crawler" v-bind="kesehatan.crawler" />
                    <IndikatorKesehatan label="Sumber feed" v-bind="kesehatan.sumber" />
                    <IndikatorKesehatan label="Gemini" v-bind="kesehatan.gemini" />
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-3"><CardTitle class="text-base">Asal catatan pemuatan</CardTitle></CardHeader>
                <CardContent class="space-y-2">
                    <template v-if="proporsiSumber.total > 0">
                        <div class="flex h-2 overflow-hidden rounded-full bg-muted">
                            <div
                                class="bg-sentimen-positif"
                                :style="{ width: lebar(proporsiSumber.otomatis, proporsiSumber.total) }"
                            />
                            <div
                                class="bg-sentimen-review"
                                :style="{ width: lebar(proporsiSumber.laporan_media, proporsiSumber.total) }"
                            />
                        </div>
                        <dl class="space-y-1 text-xs">
                            <div class="flex justify-between">
                                <dt>Ditemukan crawler</dt>
                                <dd class="angka">{{ formatAngka(proporsiSumber.otomatis) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt>Dilaporkan media</dt>
                                <dd class="angka">
                                    {{ formatAngka(proporsiSumber.laporan_media) }}
                                    ({{ formatPersen(proporsiSumber.persen_mandiri) }})
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt>Dimasukkan admin</dt>
                                <dd class="angka">{{ formatAngka(proporsiSumber.input_admin) }}</dd>
                            </div>
                        </dl>
                        <p
                            v-if="proporsiSumber.melewati_ambang"
                            class="flex gap-1.5 rounded-md bg-sentimen-review-lembut p-2 text-xs text-sentimen-review"
                        >
                            <TriangleAlert class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            Lebih dari 40% catatan berasal dari laporan mandiri media. Angka sentimen berisiko bias
                            karena media jarang melaporkan berita kritis. Periksa cakupan sumber feed.
                        </p>
                    </template>
                    <p v-else class="text-xs text-muted-foreground">
                        Belum ada catatan pemuatan. Kartu ini terisi setelah kontrak dan portal media aktif.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-3"><CardTitle class="text-base">Sumber bermasalah</CardTitle></CardHeader>
                <CardContent>
                    <ul v-if="sumberBermasalah.length" class="space-y-2 text-xs">
                        <li v-for="sumber in sumberBermasalah" :key="sumber.id" class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate font-medium">{{ sumber.nama }}</p>
                                <p class="truncate text-muted-foreground">{{ sumber.pesan_error_terakhir }}</p>
                            </div>
                            <Badge :variant="sumber.aktif ? 'outline' : 'destructive'" class="shrink-0">
                                {{ sumber.gagal_berturut }}× gagal
                            </Badge>
                        </li>
                    </ul>
                    <p v-else class="text-xs text-muted-foreground">Semua sumber feed berjalan tanpa kegagalan.</p>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader class="flex-row items-center justify-between pb-3">
                <CardTitle class="text-base">Berita terbaru</CardTitle>
                <Link href="/admin/artikel" class="text-xs underline">Lihat semua</Link>
            </CardHeader>
            <CardContent>
                <ul v-if="artikelTerbaru.length" class="divide-y text-xs">
                    <li v-for="artikel in artikelTerbaru" :key="artikel.id" class="flex items-center gap-3 py-2">
                        <span class="min-w-0 flex-1 truncate">{{ artikel.judul }}</span>
                        <span class="shrink-0 text-muted-foreground">{{ artikel.media?.nama ?? 'Belum ditautkan' }}</span>
                        <span class="w-28 shrink-0 text-right text-muted-foreground">{{ sejak(artikel.diambil_at) }}</span>
                    </li>
                </ul>
                <p v-else class="text-xs text-muted-foreground">
                    Belum ada berita masuk. Daftarkan sumber feed, lalu jalankan crawler.
                </p>
            </CardContent>
        </Card>
    </LayoutAdmin>
</template>
