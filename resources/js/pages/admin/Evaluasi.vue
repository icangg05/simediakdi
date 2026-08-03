<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, Link } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';

interface Evaluasi {
    id: number;
    model_versi: string;
    dievaluasi_at: string;
    jumlah_sampel: number;
    akurasi: number;
    f1_macro: number;
    f1_negatif: number;
    f1_netral: number;
    f1_positif: number;
    confusion_matrix: Record<string, Record<string, number>>;
    ambang_keyakinan: number;
    catatan: string | null;
}

const props = defineProps<{
    riwayat: Evaluasi[];
    goldSet: { ronde1: number; ronde2: number; relevan: number };
    konsistensiPelabel: number | null;
    relevansi: {
        jumlah_sampel: number;
        presisi: number;
        recall: number;
        f1: number;
        salah_dianggap_relevan: number;
        relevan_yang_terlewat: number;
    } | null;
    perKonteks: Array<{
        konteks: string;
        sampel_sentimen: number;
        sampel_per_kelas: Record<string, number>;
        kelas_tanpa_sampel: string[];
        akurasi: number;
        f1_macro: number;
        presisi_relevansi: number | null;
        recall_relevansi: number | null;
    }>;
    ambangGerbang: number;
}>();

const { formatAngka, formatPersen } = useFormatAngka();

const kelas = ['negatif', 'netral', 'positif'];
const terbaru = props.riwayat[0] ?? null;

const waktu = (nilai: string) => format(new Date(nilai), 'd MMM yyyy, HH:mm', { locale: id });
</script>

<template>
    <Head title="Evaluasi model" />

    <LayoutAdmin judul="Evaluasi model" :breadcrumbs="[{ title: 'Evaluasi', href: '/admin/evaluasi' }]">
        <div class="grid gap-4 lg:grid-cols-3">
            <Card>
                <CardHeader class="pb-2"><CardTitle class="text-base">Gold set</CardTitle></CardHeader>
                <CardContent class="space-y-1 text-sm">
                    <p class="angka text-3xl font-semibold">{{ formatAngka(goldSet.ronde1) }}</p>
                    <p class="text-xs text-muted-foreground">
                        label ronde 1, {{ formatAngka(goldSet.relevan) }} di antaranya dinilai relevan
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Ronde 2: {{ formatAngka(goldSet.ronde2) }} label
                    </p>
                    <Link href="/admin/pelabelan" class="inline-block pt-1 text-xs underline">Buka ruang kerja pelabelan</Link>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-2"><CardTitle class="text-base">Hasil terakhir</CardTitle></CardHeader>
                <CardContent v-if="terbaru" class="space-y-1 text-sm">
                    <p class="angka text-3xl font-semibold">{{ terbaru.f1_macro }}</p>
                    <p class="text-xs text-muted-foreground">
                        F1 macro dari {{ formatAngka(terbaru.jumlah_sampel) }} sampel,
                        akurasi {{ formatPersen(terbaru.akurasi * 100) }}
                    </p>
                    <Badge :variant="terbaru.f1_macro >= ambangGerbang ? 'outline' : 'destructive'">
                        {{ terbaru.f1_macro >= ambangGerbang ? 'Di atas gerbang' : 'Di bawah gerbang' }}
                        {{ ambangGerbang }}
                    </Badge>
                </CardContent>
                <CardContent v-else class="text-xs text-muted-foreground">
                    Belum pernah dievaluasi. Labeli gold set lebih dulu, lalu jalankan
                    <code>php artisan evaluasi:model</code>.
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-2"><CardTitle class="text-base">Konsistensi pelabel</CardTitle></CardHeader>
                <CardContent class="space-y-1 text-sm">
                    <template v-if="konsistensiPelabel !== null">
                        <p class="angka text-3xl font-semibold">{{ formatPersen(konsistensiPelabel * 100) }}</p>
                        <p class="text-xs text-muted-foreground">
                            Kesesuaian pelabel yang sama antara ronde 1 dan 2. Ini batas atas yang wajar diharapkan
                            dari model, menuntut model melebihi angka ini tidak masuk akal.
                        </p>
                    </template>
                    <p v-else class="text-xs text-muted-foreground">
                        Belum ada ronde 2. Labeli ulang 40 baris acak seminggu setelah ronde 1 untuk mengukur bias
                        pelabelan.
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card v-if="perKonteks.length > 1">
            <CardHeader class="pb-2"><CardTitle class="text-base">Rincian per konteks</CardTitle></CardHeader>
            <CardContent>
                <p class="mb-2 text-xs text-muted-foreground">
                    Angka gabungan menyembunyikan selisih antar konteks. Presisi relevansi paling banyak
                    dipengaruhi daftar kata kunci: frasa spesifik jarang muncul kebetulan, kata umum sering
                    muncul sambil lalu.
                </p>
                <div class="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Konteks</TableHead>
                                <TableHead class="text-right">Sampel</TableHead>
                                <TableHead class="text-right">neg / net / pos</TableHead>
                                <TableHead class="text-right">Akurasi</TableHead>
                                <TableHead class="text-right">F1 macro</TableHead>
                                <TableHead class="text-right">Presisi relevansi</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="baris in perKonteks" :key="baris.konteks">
                                <TableCell class="font-medium">{{ baris.konteks }}</TableCell>
                                <TableCell class="angka text-right">{{ formatAngka(baris.sampel_sentimen) }}</TableCell>
                                <TableCell class="angka text-right text-muted-foreground">
                                    {{ Object.values(baris.sampel_per_kelas).join(' / ') }}
                                </TableCell>
                                <TableCell class="angka text-right">{{ formatPersen(baris.akurasi * 100) }}</TableCell>
                                <TableCell class="angka text-right">
                                    {{ baris.f1_macro }}
                                    <span v-if="baris.kelas_tanpa_sampel.length" title="Ada kelas tanpa sampel">*</span>
                                </TableCell>
                                <TableCell
                                    class="angka text-right"
                                    :class="(baris.presisi_relevansi ?? 1) < 0.7 ? 'text-sentimen-negatif' : ''"
                                >
                                    {{ baris.presisi_relevansi === null ? '-' : formatPersen(baris.presisi_relevansi * 100) }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>

                <ul class="mt-2 space-y-1 text-xs text-sentimen-review">
                    <template v-for="baris in perKonteks" :key="`catatan-${baris.konteks}`">
                        <li v-if="baris.kelas_tanpa_sampel.length">
                            <strong>{{ baris.konteks }}</strong>: F1 macro tidak bisa dibaca apa adanya, gold
                            set-nya tidak punya sampel kelas
                            {{ baris.kelas_tanpa_sampel.join(' dan ') }}. Pakai akurasinya, atau tambah label
                            kelas itu.
                        </li>
                        <li v-if="(baris.presisi_relevansi ?? 1) < 0.7">
                            <strong>{{ baris.konteks }}</strong>: kata kuncinya terlalu umum, presisi
                            {{ formatPersen((baris.presisi_relevansi ?? 0) * 100) }}. Perketat di halaman konteks.
                        </li>
                    </template>
                </ul>
            </CardContent>
        </Card>

        <Card v-if="relevansi">
            <CardHeader class="pb-2">
                <CardTitle class="text-base">Penyaring relevansi</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <p class="text-xs text-muted-foreground">
                    Menentukan artikel mana yang masuk grafik. Presisi rendah berarti dashboard memuat artikel
                    yang sebenarnya tidak membahas konteksnya, dan angka volume ikut menggelembung. Recall rendah
                    lebih buruk lagi, artikel yang terlewat hilang selamanya dari analisis.
                </p>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <p class="text-[13px] font-medium text-muted-foreground">Presisi</p>
                        <p class="angka text-2xl font-semibold" :class="relevansi.presisi < 0.7 ? 'text-sentimen-negatif' : ''">
                            {{ formatPersen(relevansi.presisi * 100) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ formatAngka(relevansi.salah_dianggap_relevan) }} artikel salah dianggap relevan
                        </p>
                    </div>
                    <div>
                        <p class="text-[13px] font-medium text-muted-foreground">Recall</p>
                        <p class="angka text-2xl font-semibold">{{ formatPersen(relevansi.recall * 100) }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ formatAngka(relevansi.relevan_yang_terlewat) }} artikel relevan terlewat
                        </p>
                    </div>
                    <div>
                        <p class="text-[13px] font-medium text-muted-foreground">F1</p>
                        <p class="angka text-2xl font-semibold">{{ relevansi.f1 }}</p>
                        <p class="text-xs text-muted-foreground">
                            dari {{ formatAngka(relevansi.jumlah_sampel) }} sampel
                        </p>
                    </div>
                </div>

                <p
                    v-if="relevansi.presisi < 0.7"
                    class="rounded-md bg-sentimen-negatif-lembut p-2 text-xs text-sentimen-negatif"
                >
                    Presisi di bawah 70%. Menaikkan ambang keyakinan tidak menolong, model sama yakinnya
                    saat benar maupun salah. Sampaikan batasan ini saat menyajikan angka volume.
                </p>
            </CardContent>
        </Card>

        <Card v-if="terbaru">
            <CardHeader class="pb-2">
                <CardTitle class="text-base">Confusion matrix</CardTitle>
            </CardHeader>
            <CardContent>
                <p class="mb-2 text-xs text-muted-foreground">
                    Baris = label manusia, kolom = tebakan model. Diagonal berarti cocok. Angka besar di luar
                    diagonal menunjukkan kelas mana yang paling sering tertukar.
                </p>
                <div class="overflow-x-auto">
                    <Table class="w-auto">
                        <TableHeader>
                            <TableRow>
                                <TableHead class="w-32">gold \ prediksi</TableHead>
                                <TableHead v-for="k in kelas" :key="k" class="w-24 capitalize">{{ k }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="gold in kelas" :key="gold">
                                <TableCell class="font-medium capitalize">{{ gold }}</TableCell>
                                <TableCell
                                    v-for="prediksi in kelas"
                                    :key="prediksi"
                                    class="angka text-right"
                                    :class="gold === prediksi ? 'font-semibold text-sentimen-positif' : ''"
                                >
                                    {{ formatAngka(terbaru.confusion_matrix[gold]?.[prediksi] ?? 0) }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="pb-2"><CardTitle class="text-base">Riwayat evaluasi</CardTitle></CardHeader>
            <CardContent>
                <div v-if="riwayat.length" class="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Waktu</TableHead>
                                <TableHead>Versi model</TableHead>
                                <TableHead class="text-right">Sampel</TableHead>
                                <TableHead class="text-right">Akurasi</TableHead>
                                <TableHead class="text-right">F1 macro</TableHead>
                                <TableHead class="text-right">Negatif</TableHead>
                                <TableHead class="text-right">Netral</TableHead>
                                <TableHead class="text-right">Positif</TableHead>
                                <TableHead class="text-right">Ambang</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="baris in riwayat" :key="baris.id">
                                <TableCell class="text-muted-foreground">{{ waktu(baris.dievaluasi_at) }}</TableCell>
                                <TableCell class="font-mono text-xs">{{ baris.model_versi }}</TableCell>
                                <TableCell class="angka text-right">{{ formatAngka(baris.jumlah_sampel) }}</TableCell>
                                <TableCell class="angka text-right">{{ formatPersen(baris.akurasi * 100) }}</TableCell>
                                <TableCell class="angka text-right font-semibold">{{ baris.f1_macro }}</TableCell>
                                <TableCell class="angka text-right">{{ baris.f1_negatif }}</TableCell>
                                <TableCell class="angka text-right">{{ baris.f1_netral }}</TableCell>
                                <TableCell class="angka text-right">{{ baris.f1_positif }}</TableCell>
                                <TableCell class="angka text-right">{{ baris.ambang_keyakinan }}</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
                <p v-else class="text-xs text-muted-foreground">
                    Belum ada riwayat. Evaluasi kedua setelah tiga bulan data akan tampil di sini dan bisa
                    dibandingkan dengan yang pertama.
                </p>
            </CardContent>
        </Card>
    </LayoutAdmin>
</template>
