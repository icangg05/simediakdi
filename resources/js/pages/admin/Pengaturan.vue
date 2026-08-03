<script setup lang="ts">
import IndikatorKesehatan from '@/components/domain/IndikatorKesehatan.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';

interface Nilai {
    label: string;
    nilai: string | number | null;
    env: string;
    diukur: string | null;
}

const props = defineProps<{
    kelompok: { judul: string; catatan: string | null; nilai: Nilai[] }[];
    layanan: { nama: string; sehat: boolean; url: string }[];
    evaluasi: { f1_macro: number; jumlah_sampel: number; dievaluasi_at: string } | null;
}>();
</script>

<template>
    <Head title="Pengaturan sistem" />

    <LayoutAdmin judul="Pengaturan sistem" :breadcrumbs="[{ title: 'Pengaturan', href: '/admin/pengaturan' }]">
        <div class="space-y-1 rounded-md border bg-muted/40 p-3 text-sm">
            <p class="font-medium">Halaman ini menampilkan nilai, tidak menyuntingnya.</p>
            <p class="text-muted-foreground">
                Ambang di bawah mengubah setiap angka dashboard secara surut, termasuk untuk periode yang sudah
                dilaporkan ke pimpinan. Perubahannya lewat <code class="text-xs">.env</code> dan deploy, sehingga
                tercatat di git bersama alasannya. Kolom "diukur dari" ada supaya angka-angka ini tidak terbaca
                sebagai selera.
            </p>
        </div>

        <Card>
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">Layanan</CardTitle>
            </CardHeader>
            <CardContent class="space-y-2">
                <IndikatorKesehatan
                    v-for="l in props.layanan"
                    :key="l.nama"
                    :label="l.nama"
                    :status="l.sehat ? 'hijau' : 'merah'"
                    :keterangan="l.sehat ? l.url : `Tidak menjawab di ${l.url}. Job yang membutuhkannya menumpuk di antrean dan jalan lagi setelah layanan hidup.`"
                />
            </CardContent>
        </Card>

        <Card v-if="props.evaluasi">
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">Evaluasi model terakhir</CardTitle>
            </CardHeader>
            <CardContent class="text-sm">
                F1 macro <span class="angka font-medium">{{ props.evaluasi.f1_macro.toFixed(4) }}</span>
                dari {{ props.evaluasi.jumlah_sampel }} sampel gold set,
                {{ format(new Date(props.evaluasi.dievaluasi_at), 'd MMMM yyyy', { locale: id }) }}.
            </CardContent>
        </Card>

        <Card v-for="k in props.kelompok" :key="k.judul">
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">{{ k.judul }}</CardTitle>
                <p v-if="k.catatan" class="text-xs text-muted-foreground">{{ k.catatan }}</p>
            </CardHeader>
            <CardContent class="p-0">
                <ul class="divide-y">
                    <li v-for="n in k.nilai" :key="n.env" class="space-y-1 px-4 py-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="text-sm">{{ n.label }}</p>
                            <span class="angka text-sm font-medium">{{ n.nilai }}</span>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            <code class="text-[11px]">{{ n.env }}</code>
                            <template v-if="n.diukur"> · {{ n.diukur }}</template>
                        </p>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </LayoutAdmin>
</template>
