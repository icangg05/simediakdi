<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import PanelPelabelan from '@/pages/admin/model-relevansi/components/PanelPelabelan.vue';
import type { FilterDefinisi, KolomDefinisi, OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { router, usePage } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { CheckCircle2, Tag } from 'lucide-vue-next';
import { computed } from 'vue';

interface BarisSampel {
    id: number;
    judul: string;
    excerpt: string | null;
    url: string | null;
    tanggal_publikasi: string | null;
    label_manual: 'relevan' | 'tidak_relevan' | null;
    alasan_label: string | null;
    status_label: string;
    tingkat_kesulitan: string;
    sumber_dataset: string;
    priority_score: number;
    duplicate_group_id: number | null;
    last_reviewed_at: string | null;
    labeled_at: string | null;
    media: { id: number; nama: string } | null;
    pelabel: { id: number; name: string } | null;
}

const props = defineProps<{
    dataset: { data: BarisSampel[] } & PaginasiMeta;
    opsi: Record<string, OpsiFilter[]> & {
        alasan: { relevan: OpsiFilter[]; tidak_relevan: OpsiFilter[] };
    };
    sampel: Record<string, unknown> | null;
}>();

/**
 * `w-full max-w-0` pada kolom judul yang menahan tabel tetap muat di layar.
 *
 * Tanpa itu tabelnya melar dan halaman ikut bisa digeser ke samping. Sebabnya
 * `truncate` memasang `white-space: nowrap`, dan di tabel auto-layout itu
 * membuat lebar minimum sel ikut sepanjang judulnya, jadi pemotongan teks tidak
 * pernah terjadi. Sel dengan lebar nol memaksa browser membagi sisa ruang lebih
 * dulu, baru isinya dipotong.
 *
 * Empat kolom pelengkap disembunyikan di layar sempit. Yang tersisa di ponsel
 * adalah judul, label, dan prioritas, dan ketiganya yang benar-benar dipakai
 * memutuskan sampel mana yang dibuka.
 */
const kolom: KolomDefinisi[] = [
    { kunci: 'judul', judul: 'Artikel', bisaDiurutkan: true, kelas: 'w-full max-w-0' },
    { kunci: 'media', judul: 'Media', lebar: 'w-36', kelas: 'hidden md:table-cell' },
    { kunci: 'tanggal_publikasi', judul: 'Terbit', bisaDiurutkan: true, lebar: 'w-28', kelas: 'hidden lg:table-cell' },
    { kunci: 'label_manual', judul: 'Label', lebar: 'w-32' },
    { kunci: 'tingkat_kesulitan', judul: 'Kesulitan', lebar: 'w-28', kelas: 'hidden xl:table-cell' },
    { kunci: 'priority_score', judul: 'Prioritas', bisaDiurutkan: true, kelas: 'angka text-right', lebar: 'w-20' },
    { kunci: 'pelabel', judul: 'Pelabel', lebar: 'w-32', kelas: 'hidden lg:table-cell' },
];

const filter = computed<FilterDefinisi[]>(() => [
    { kunci: 'status', label: 'Status', opsi: props.opsi.status },
    { kunci: 'label', label: 'Label', opsi: props.opsi.label },
    { kunci: 'kesulitan', label: 'Kesulitan', opsi: props.opsi.kesulitan },
    { kunci: 'sumber', label: 'Sumber', opsi: props.opsi.sumber },
    { kunci: 'media', label: 'Media', opsi: props.opsi.media },
    { kunci: 'pelabel', label: 'Pelabel', opsi: props.opsi.pelabel },
]);

/** Filter cepat, dokumen 10 bagian 7.3. Semuanya berubah menjadi query string. */
const cepat = [
    { label: 'Belum dilabeli', param: 'status=belum_dilabeli' },
    { label: 'Belum ditinjau ulang', param: 'belum_direview=1' },
    { label: 'Hard negative', param: 'kesulitan=hard_negative' },
    { label: 'Hard positive', param: 'kesulitan=hard_positive' },
    { label: 'Kelompok duplikat', param: 'duplikat=1' },
    { label: 'Dikeluarkan', param: 'dikeluarkan=1' },
];

/**
 * Filter cepat yang sedang berlaku, dibaca dari URL dan bukan dari state lokal.
 *
 * Halaman ini bisa dibuka dari tautan mana pun, termasuk kartu di tab ringkasan
 * dan bookmark admin. Menyimpan status aktif di komponen berarti chip terlihat
 * mati padahal filternya jalan, dan itu membuat orang menekannya lagi lalu
 * justru mematikannya.
 */
const halaman = usePage();

const cepatAktif = computed(() => {
    const kini = new URLSearchParams(halaman.url.split('?')[1] ?? '');

    return new Set(
        cepat
            .filter((c) => {
                const [kunci, nilai] = c.param.split('=');

                return (kini.get(kunci) ?? '').split(',').includes(nilai);
            })
            .map((c) => c.param),
    );
});

/** Menekan chip yang sedang aktif mematikannya, bukan memasangnya dua kali. */
function alihkanCepat(param: string) {
    const [kunci, nilai] = param.split('=');
    const params = new URLSearchParams(halaman.url.split('?')[1] ?? '');

    if (cepatAktif.value.has(param)) params.delete(kunci);
    else params.set(kunci, nilai);

    params.delete('halaman');

    router.get('/admin/model-relevansi', Object.fromEntries(params), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

/** Panel diminta lewat URL tapi tidak ada sampel yang tersisa untuk diisi. */
const sedangMelabeli = computed(() => new URLSearchParams(halaman.url.split('?')[1] ?? '').get('labeli') === '1');

function buka(sampelId?: number) {
    const params = new URLSearchParams(window.location.search);
    params.set('labeli', '1');

    if (sampelId) params.set('sampel', String(sampelId));
    else params.delete('sampel');

    // Tombol mulai tanpa filter apa pun berarti antrean artikel yang belum
    // dilabeli. Ditulis ke URL, bukan diam-diam jadi bawaan server, supaya
    // chip antreannya ikut menyala dan pelabel melihat sedang mengerjakan apa.
    const adaAntrean = ['status', 'belum_direview', 'dikeluarkan', 'duplikat', 'cari'].some((k) => params.has(k));

    if (!sampelId && !adaAntrean) params.set('status', 'belum_dilabeli');

    router.get('/admin/model-relevansi', Object.fromEntries(params), { preserveScroll: true });
}

const tanggal = (w: string | null) => (w ? format(new Date(w), 'd MMM yyyy', { locale: id }) : '-');

const labelKesulitan: Record<string, string> = {
    normal: 'Normal',
    hard_positive: 'Hard positive',
    hard_negative: 'Hard negative',
};
</script>

<template>
    <div class="space-y-4">
        <PanelPelabelan v-if="sampel" :sampel="sampel as any" :alasan="opsi.alasan" />

        <Card v-else-if="sedangMelabeli" class="border-sentimen-positif/40">
            <CardContent class="flex items-start gap-3 p-4">
                <CheckCircle2 class="mt-0.5 h-5 w-5 shrink-0 text-sentimen-positif" aria-hidden="true" />
                <div class="space-y-1">
                    <p class="text-sm font-medium">Antrean ini sudah habis.</p>
                    <p class="text-xs text-muted-foreground">
                        Tidak ada lagi sampel yang cocok dengan filter yang sedang aktif. Matikan salah satu chip di atas untuk melanjutkan ke antrean
                        lain.
                    </p>
                </div>
            </CardContent>
        </Card>

        <div class="flex flex-wrap items-center gap-2">
            <Button size="sm" @click="buka()">Mulai melabeli antrean prioritas</Button>

            <button
                v-for="c in cepat"
                :key="c.label"
                type="button"
                class="rounded-full border px-3 py-1 text-xs transition-colors"
                :class="cepatAktif.has(c.param) ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                :aria-pressed="cepatAktif.has(c.param)"
                @click="alihkanCepat(c.param)"
            >
                {{ c.label }}
            </button>
        </div>

        <DataTable
            :kolom="kolom"
            :data="dataset.data"
            :meta="dataset"
            :filter="filter"
            pencarian
            url-basis="/admin/model-relevansi"
            judul-kosong="Belum ada kandidat dataset"
            keterangan-kosong="Jalankan relevance:import-crawled untuk memasukkan artikel yang sudah terkumpul."
            :aksi-baris="[{ label: 'Labeli sampel ini', onKlik: (b: BarisSampel) => buka(b.id) }]"
        >
            <template #sel-judul="{ baris }">
                <div class="min-w-0">
                    <button type="button" class="block truncate text-left hover:underline" @click="buka(baris.id)">
                        {{ baris.judul }}
                    </button>
                    <p v-if="baris.excerpt" class="truncate text-[11px] text-muted-foreground">
                        {{ baris.excerpt }}
                    </p>
                </div>
            </template>

            <template #sel-media="{ baris }">
                <span class="truncate text-xs">{{ baris.media?.nama ?? '-' }}</span>
            </template>

            <template #sel-tanggal_publikasi="{ baris }">
                <span class="text-xs">{{ tanggal(baris.tanggal_publikasi) }}</span>
            </template>

            <template #sel-label_manual="{ baris }">
                <div class="space-y-0.5">
                    <Badge v-if="baris.label_manual === 'relevan'" class="bg-sentimen-positif-lembut text-foreground"> Relevan </Badge>
                    <Badge v-else-if="baris.label_manual === 'tidak_relevan'" class="bg-sentimen-negatif-lembut text-foreground">
                        Tidak relevan
                    </Badge>
                    <Badge v-else variant="outline">Belum</Badge>

                    <p v-if="baris.alasan_label" class="flex items-center gap-1 text-[10px] text-muted-foreground">
                        <Tag class="h-2.5 w-2.5" aria-hidden="true" />
                        {{ baris.alasan_label }}
                    </p>
                </div>
            </template>

            <template #sel-tingkat_kesulitan="{ baris }">
                <span class="text-xs">{{ labelKesulitan[baris.tingkat_kesulitan] ?? baris.tingkat_kesulitan }}</span>
            </template>

            <template #sel-pelabel="{ baris }">
                <span class="truncate text-xs">{{ baris.pelabel?.name ?? '-' }}</span>
            </template>
        </DataTable>
    </div>
</template>
