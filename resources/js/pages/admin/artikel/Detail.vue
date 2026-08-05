<script setup lang="ts">
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { ExternalLink } from 'lucide-vue-next';
import { ref } from 'vue';

type Label = 'negatif' | 'netral' | 'positif';

interface Analisis {
    id: number;
    relevan: boolean;
    relevan_manual: boolean | null;
    label_model: Label | null;
    label_manual: Label | null;
    label_efektif: Label | null;
    perlu_review: boolean;
    model_versi: string | null;
    provider: string | null;
    reason_code: string | null;
    reason_summary: string | null;
    evidence: string[] | null;
    catatan_koreksi: string | null;
    dikoreksi_at: string | null;
    pengoreksi: { id: number; name: string } | null;
}

const props = defineProps<{
    artikel: {
        id: number;
        judul: string;
        url: string;
        isi: string | null;
        penulis: string | null;
        jumlah_kata: number | null;
        dipublikasikan_at: string | null;
        diambil_at: string;
        status_dedup: string;
        status_proses: string;
        skor_kemiripan: number | null;
        media: { id: number; nama: string } | null;
        sumber_feed: { id: number; nama: string } | null;
        induk: { id: number; judul: string } | null;
        salinan: Array<{ id: number; judul: string; media: { nama: string } | null }>;
        analisis_sentimen: Analisis[];
    };
}>();

const { formatAngka, formatPersen } = useFormatAngka();

const sedangKoreksi = ref<number | null>(null);

const form = useForm({ label_manual: null as Label | null, catatan_koreksi: '' });

function mulaiKoreksi(analisis: Analisis) {
    sedangKoreksi.value = analisis.id;
    form.label_manual = analisis.label_manual;
    form.catatan_koreksi = analisis.catatan_koreksi ?? '';
}

function simpan(analisis: Analisis) {
    form.put(`/admin/analisis/${analisis.id}`, {
        preserveScroll: true,
        onSuccess: () => (sedangKoreksi.value = null),
    });
}

function cabut(analisis: Analisis) {
    form.label_manual = null;
    form.catatan_koreksi = '';
    simpan(analisis);
}

const waktu = (nilai: string | null) =>
    nilai ? format(new Date(nilai), 'd MMMM yyyy, HH:mm', { locale: id }) : '-';

</script>

<template>
    <Head :title="artikel.judul" />

    <LayoutAdmin
        :breadcrumbs="[
            { title: 'Artikel', href: '/admin/artikel' },
            { title: 'Detail', href: '#' },
        ]"
    >
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <Card>
                    <CardContent class="space-y-3 p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge v-if="artikel.status_dedup === 'salinan'" variant="secondary">Salinan</Badge>
                            <Badge :variant="artikel.status_proses === 'gagal' ? 'destructive' : 'outline'">
                                {{ artikel.status_proses }}
                            </Badge>
                        </div>

                        <h1 class="text-xl font-semibold leading-snug">{{ artikel.judul }}</h1>

                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-muted-foreground sm:grid-cols-3">
                            <div><dt class="inline">Media:</dt> <dd class="inline">{{ artikel.media?.nama ?? 'Belum ditautkan' }}</dd></div>
                            <div><dt class="inline">Penulis:</dt> <dd class="inline">{{ artikel.penulis ?? '-' }}</dd></div>
                            <div><dt class="inline">Kata:</dt> <dd class="angka inline">{{ formatAngka(artikel.jumlah_kata) }}</dd></div>
                            <div><dt class="inline">Terbit:</dt> <dd class="inline">{{ waktu(artikel.dipublikasikan_at) }}</dd></div>
                            <div><dt class="inline">Diambil:</dt> <dd class="inline">{{ waktu(artikel.diambil_at) }}</dd></div>
                            <div><dt class="inline">Sumber:</dt> <dd class="inline">{{ artikel.sumber_feed?.nama ?? '-' }}</dd></div>
                        </dl>

                        <a
                            :href="artikel.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 text-xs underline"
                        >
                            Buka halaman aslinya <ExternalLink class="h-3 w-3" aria-hidden="true" />
                        </a>

                        <div v-if="artikel.induk" class="rounded-md bg-muted p-2 text-xs">
                            Artikel ini salinan dari
                            <Link :href="`/admin/artikel/${artikel.induk.id}`" class="underline">
                                {{ artikel.induk.judul }}
                            </Link>
                            <span v-if="artikel.skor_kemiripan">
                                (kemiripan {{ formatPersen(artikel.skor_kemiripan * 100) }})
                            </span>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="artikel.isi">
                    <CardHeader class="pb-2"><CardTitle class="text-base">Isi hasil ekstraksi</CardTitle></CardHeader>
                    <CardContent>
                        <p class="max-h-96 overflow-y-auto whitespace-pre-line text-sm leading-relaxed">
                            {{ artikel.isi }}
                        </p>
                    </CardContent>
                </Card>
            </div>

            <div class="space-y-4">
                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-base">Relevansi dan sentimen</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p v-if="!artikel.analisis_sentimen.length" class="text-xs text-muted-foreground">
                            Belum diklasifikasi. Jalankan lewat tombol di halaman Antrean Klasifikasi.
                        </p>

                        <div
                            v-for="analisis in artikel.analisis_sentimen"
                            :key="analisis.id"
                            class="space-y-2 rounded-md border p-3"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-sm font-medium">
                                    Hasil klasifikasi
                                </p>
                                <BadgeSentimen
                                    v-if="analisis.relevan"
                                    :label="analisis.label_efektif"
                                    :perlu-review="analisis.perlu_review"
                                />
                            </div>

                            <!--
                                Alasan dan kutipan bukti, bukan angka skor. Gemini tidak
                                mengeluarkan probabilitas, ia menunjuk kalimat di artikel.
                                Kutipan di bawah sudah diverifikasi ada di isi artikel;
                                yang tidak lolos verifikasi tidak pernah menjadi label.
                            -->
                            <div v-if="analisis.reason_summary" class="space-y-1 rounded bg-muted/50 p-2">
                                <p class="text-[11px] leading-snug text-muted-foreground">
                                    {{ analisis.reason_summary }}
                                </p>

                                <details v-if="analisis.evidence?.length" class="text-[11px]">
                                    <summary class="cursor-pointer text-muted-foreground hover:text-foreground">
                                        Bukti ({{ analisis.evidence.length }})
                                    </summary>
                                    <ul class="mt-1 space-y-1 border-l pl-2 text-muted-foreground">
                                        <li v-for="(kutipan, i) in analisis.evidence" :key="i">
                                            “{{ kutipan }}”
                                        </li>
                                    </ul>
                                </details>

                                <p v-if="analisis.provider" class="text-[11px] text-muted-foreground">
                                    Dinilai {{ analisis.provider }} {{ analisis.model_versi ?? '' }}.
                                </p>
                            </div>

                            <p v-if="!analisis.relevan" class="text-xs text-muted-foreground">
                                Tidak dinilai sentimennya. Artikel yang tidak membahas Pemkot tidak punya nada terhadap Pemkot.
                            </p>

                            <template v-else>
                                <p class="text-xs text-muted-foreground">
                                    Analisis otomatis: cenderung {{ analisis.label_model ?? 'belum diputuskan' }}
                                    <span v-if="analisis.perlu_review">, ditandai untuk diperiksa manusia</span>
                                </p>

                                <p v-if="analisis.label_manual" class="text-xs">
                                    Dikoreksi menjadi <strong>{{ analisis.label_manual }}</strong>
                                    oleh {{ analisis.pengoreksi?.name ?? '-' }}, {{ waktu(analisis.dikoreksi_at) }}.
                                    <span v-if="analisis.catatan_koreksi" class="text-muted-foreground">
                                        “{{ analisis.catatan_koreksi }}”
                                    </span>
                                </p>

                                <div v-if="sedangKoreksi === analisis.id" class="space-y-2">
                                    <div class="flex gap-1">
                                        <Button
                                            v-for="pilihan in (['negatif', 'netral', 'positif'] as Label[])"
                                            :key="pilihan"
                                            size="sm"
                                            :variant="form.label_manual === pilihan ? 'default' : 'outline'"
                                            class="h-7 flex-1 text-xs capitalize"
                                            @click="form.label_manual = pilihan"
                                        >
                                            {{ pilihan }}
                                        </Button>
                                    </div>
                                    <Input v-model="form.catatan_koreksi" placeholder="Alasan koreksi" class="h-8 text-xs" />
                                    <div class="flex gap-1">
                                        <Button size="sm" class="h-7 text-xs" :disabled="form.processing" @click="simpan(analisis)">
                                            Simpan
                                        </Button>
                                        <Button size="sm" variant="ghost" class="h-7 text-xs" @click="sedangKoreksi = null">
                                            Batal
                                        </Button>
                                        <Button
                                            v-if="analisis.label_manual"
                                            size="sm"
                                            variant="ghost"
                                            class="h-7 text-xs text-destructive"
                                            @click="cabut(analisis)"
                                        >
                                            Cabut koreksi
                                        </Button>
                                    </div>
                                </div>

                                <Button
                                    v-else
                                    size="sm"
                                    variant="outline"
                                    class="h-7 w-full text-xs"
                                    @click="mulaiKoreksi(analisis)"
                                >
                                    {{ analisis.label_manual ? 'Ubah koreksi' : 'Koreksi label' }}
                                </Button>
                            </template>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="artikel.salinan.length">
                    <CardHeader class="pb-2"><CardTitle class="text-base">Salinan artikel ini</CardTitle></CardHeader>
                    <CardContent>
                        <ul class="space-y-1 text-xs">
                            <li v-for="salinan in artikel.salinan" :key="salinan.id">
                                <Link :href="`/admin/artikel/${salinan.id}`" class="underline">{{ salinan.judul }}</Link>
                                <span class="text-muted-foreground">, {{ salinan.media?.nama ?? '-' }}</span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </div>
    </LayoutAdmin>
</template>
