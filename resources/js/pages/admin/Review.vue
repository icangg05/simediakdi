<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { CheckCircle2, ExternalLink, XCircle } from 'lucide-vue-next';
import { onMounted, onUnmounted } from 'vue';

interface ArtikelReview {
    id: number;
    analisis_id: number;
    judul: string;
    url: string;
    ringkasan: string | null;
    isi: string;
    media: string | null;
    diambil_at: string;
    skor_relevansi: number | null;
    sebutan: { judul: number; isi: number };
}

const props = defineProps<{
    artikel: ArtikelReview | null;
    sisa: number;
    ambang: { atas: number | null; bawah: number | null };
    konteks: { id: number; nama: string } | null;
}>();

const form = useForm({ analisis_id: 0, relevan: false, alasan: '' });

function putuskan(relevan: boolean) {
    if (!props.artikel) return;

    form.analisis_id = props.artikel.analisis_id;
    form.relevan = relevan;
    form.post('/admin/review', {
        preserveScroll: false,
        onSuccess: () => form.reset('alasan'),
    });
}

// Satu tangan menjangkau keduanya, sama seperti halaman pelabelan. Antrean 746
// artikel tidak akan selesai kalau tiap keputusan menuntut memindahkan tangan
// ke tetikus.
function pintasan(e: KeyboardEvent) {
    if (e.target instanceof HTMLInputElement) return;
    if (e.key === '1') putuskan(true);
    if (e.key === '2') putuskan(false);
}

onMounted(() => window.addEventListener('keydown', pintasan));
onUnmounted(() => window.removeEventListener('keydown', pintasan));

const desimal = (n: number | null) => (n === null ? '-' : n.toFixed(3).replace('.', ','));

const waktu = (n: string) => format(new Date(n), 'd MMM yyyy', { locale: id });
</script>

<template>
    <Head title="Antrean perlu review" />

    <LayoutAdmin :breadcrumbs="[{ title: 'Antrean review', href: '/admin/review' }]">
        <div class="mx-auto w-full max-w-3xl space-y-4">
            <!--
                Header berbeda warna dari halaman pelabelan. Keduanya mirip
                bentuknya dan berbeda akibatnya, dan tertukar sekali saja sudah
                cukup untuk mengotori dashboard atau gold set.
            -->
            <div class="rounded-md border-l-4 border-sentimen-review bg-sentimen-review-lembut p-3">
                <p class="text-sm font-medium">Antrean perlu review</p>
                <p class="text-xs text-muted-foreground">
                    Keputusan di sini langsung mengubah dashboard. Sisa
                    <strong class="angka">{{ sisa }}</strong> artikel.
                    Skornya di antara ambang {{ ambang.bawah ?? '-' }} dan {{ ambang.atas ?? '-' }},
                    terlalu ragu untuk diputuskan sistem sendiri.
                </p>
            </div>

            <Card v-if="!artikel">
                <CardContent class="p-8 text-center">
                    <CheckCircle2 class="mx-auto mb-2 h-8 w-8 text-sentimen-positif" aria-hidden="true" />
                    <p class="text-sm font-medium">Antrean kosong.</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Tidak ada artikel yang menunggu keputusan.
                    </p>
                    <Button variant="outline" class="mt-4" @click="router.get('/admin/artikel')">
                        Lihat daftar artikel
                    </Button>
                </CardContent>
            </Card>

            <template v-else>
                <Card>
                    <CardContent class="space-y-3 p-4">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <Badge variant="outline">{{ artikel.media ?? 'Media belum ditautkan' }}</Badge>
                            <span class="text-muted-foreground">{{ waktu(artikel.diambil_at) }}</span>
                        </div>

                        <h1 class="text-lg font-semibold leading-snug">{{ artikel.judul }}</h1>

                        <p v-if="artikel.ringkasan" class="text-sm text-muted-foreground">
                            {{ artikel.ringkasan }}
                        </p>

                        <div class="grid gap-2 sm:grid-cols-3">
                            <div class="rounded border p-2">
                                <p class="text-[11px] text-muted-foreground">Kemiripan konteks</p>
                                <p class="angka text-base font-semibold">{{ desimal(artikel.skor_relevansi) }}</p>
                            </div>
                            <div class="rounded border p-2">
                                <p class="text-[11px] text-muted-foreground">Sebutan di judul</p>
                                <p class="angka text-base font-semibold">{{ artikel.sebutan.judul }}</p>
                            </div>
                            <div class="rounded border p-2">
                                <p class="text-[11px] text-muted-foreground">Sebutan di isi</p>
                                <p class="angka text-base font-semibold">{{ artikel.sebutan.isi }}</p>
                            </div>
                        </div>

                        <p class="text-[11px] leading-snug text-muted-foreground">
                            Kemiripan makna, bukan persentase keyakinan. Sebutan nol di judul dan
                            satu atau dua di isi biasanya berarti konteks hanya disinggung sepintas,
                            dan penyebutan bukan pembahasan.
                        </p>

                        <a
                            :href="artikel.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 text-xs underline"
                        >
                            Buka halaman aslinya <ExternalLink class="h-3 w-3" aria-hidden="true" />
                        </a>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="p-4">
                        <p class="mb-2 text-xs font-medium text-muted-foreground">Awal isi artikel</p>
                        <p class="max-h-64 overflow-y-auto whitespace-pre-line text-sm leading-relaxed">
                            {{ artikel.isi }}
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="space-y-3 p-4">
                        <p class="text-sm font-medium">
                            Apakah artikel ini secara substantif membahas
                            {{ konteks?.nama ?? 'konteks utama' }}?
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <Button :disabled="form.processing" @click="putuskan(true)">
                                <CheckCircle2 class="mr-1 h-4 w-4" aria-hidden="true" />
                                Relevan
                                <kbd class="ml-2 rounded bg-background/20 px-1 text-[10px]">1</kbd>
                            </Button>
                            <Button variant="outline" :disabled="form.processing" @click="putuskan(false)">
                                <XCircle class="mr-1 h-4 w-4" aria-hidden="true" />
                                Tidak relevan
                                <kbd class="ml-2 rounded bg-muted px-1 text-[10px]">2</kbd>
                            </Button>
                        </div>

                        <Input v-model="form.alasan" placeholder="Alasan, opsional" class="text-sm" />

                        <p class="text-[11px] text-muted-foreground">
                            Keputusan Anda tidak pernah ditimpa analisis ulang.
                        </p>
                    </CardContent>
                </Card>
            </template>
        </div>
    </LayoutAdmin>
</template>
