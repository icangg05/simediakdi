<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { OpsiFilter } from '@/types/tabel';
import { router, useForm } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { Check, CheckCircle2, Copy, ExternalLink, SkipForward, XCircle } from 'lucide-vue-next';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

interface Sampel {
    id: number;
    artikel_id: number | null;
    judul: string;
    excerpt: string | null;
    isi: string;
    url: string | null;
    media: string | null;
    tanggal_publikasi: string | null;
    kategori_sumber: string[] | null;
    tag_sumber: string[] | null;
    label_manual: 'relevan' | 'tidak_relevan' | null;
    alasan_label: string | null;
    tingkat_kesulitan: string;
    status_label: string;
    priority_score: number;
    priority_reasons: Record<string, number> | null;
    pelabel: string | null;
    labeled_at: string | null;
    sisa_antrean: number;
    antrean: string;
}

const props = defineProps<{
    sampel: Sampel;
    alasan: { relevan: OpsiFilter[]; tidak_relevan: OpsiFilter[] };
}>();

const form = useForm({
    label: '' as '' | 'relevan' | 'tidak_relevan',
    alasan: null as string | null,
    kesulitan: 'normal',
});

const alasanTersedia = computed(() => (form.label === 'relevan' ? props.alasan.relevan : props.alasan.tidak_relevan));

/**
 * Alasan wajib pada keadaan yang sama dengan yang dijaga server.
 *
 * Digandakan di sini bukan karena server tidak dipercaya, melainkan supaya
 * pelabel tahu sebelum menekan tombol, bukan sesudah kehilangan satu putaran.
 */
const alasanWajib = computed(
    () =>
        (props.sampel.label_manual !== null && props.sampel.label_manual !== form.label) ||
        props.sampel.status_label === 'terkunci_test' ||
        form.kesulitan !== 'normal',
);

const bisaSimpan = computed(() => form.label !== '' && (!alasanWajib.value || form.alasan !== null));

// Alasan lama ikut terbawa kalau labelnya berganti, dan alasan yang tidak
// cocok dengan labelnya ditolak server. Dikosongkan supaya pelabel memilih
// ulang, bukan mendapat galat validasi yang tidak dia sebabkan.
watch(
    () => form.label,
    () => {
        form.alasan = null;
    },
);

function simpan() {
    if (!bisaSimpan.value) return;

    form.post(`/admin/model-relevansi/sampel/${props.sampel.id}/label`, {
        preserveScroll: true,
        onSuccess: () => berikutnya(),
    });
}

function lewati() {
    router.post(`/admin/model-relevansi/sampel/${props.sampel.id}/lewati`, {}, { preserveScroll: true, onSuccess: () => berikutnya() });
}

/**
 * Sampel berikutnya diambil server menurut skor prioritas, tanpa memuat ulang
 * seluruh halaman. `sampel` dibuang dari URL supaya yang datang antrean
 * berikutnya, bukan artikel yang sama.
 */
function berikutnya() {
    form.reset();

    const params = new URLSearchParams(window.location.search);
    params.delete('sampel');
    params.set('labeli', '1');

    router.get('/admin/model-relevansi', Object.fromEntries(params), {
        preserveState: false,
        preserveScroll: true,
    });
}

function tutup() {
    const params = new URLSearchParams(window.location.search);
    params.delete('labeli');
    params.delete('sampel');

    router.get('/admin/model-relevansi', Object.fromEntries(params), { preserveScroll: true });
}

function pintasan(e: KeyboardEvent) {
    if (e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement) return;

    const tombol: Record<string, () => void> = {
        r: () => (form.label = 'relevan'),
        t: () => (form.label = 'tidak_relevan'),
        s: lewati,
        Enter: simpan,
        Escape: tutup,
    };

    tombol[e.key]?.();
}

onMounted(() => window.addEventListener('keydown', pintasan));
onUnmounted(() => window.removeEventListener('keydown', pintasan));

const isiPanjang = ref(false);

const panel = ref<HTMLElement | null>(null);

/**
 * Bawa layar ke awal artikel yang sedang dilabeli.
 *
 * Panel ini berada di atas tabel, jadi tanpa ini menekan baris ke-40 membuka
 * artikel di tempat yang tidak terlihat, dan yang tampak di layar cuma tabel
 * yang seolah tidak berubah. Berlaku juga setelah menyimpan: sampel berikutnya
 * muncul di posisi yang sama, dan pelabel tidak perlu menggulir mencarinya
 * setiap kali.
 *
 * rAF setelah nextTick, bukan langsung. Inertia memulihkan posisi gulir sendiri
 * karena kunjungannya memakai preserveScroll, dan pemulihan itu terjadi setelah
 * DOM diperbarui. Menggulir lebih dulu berarti digulir balik.
 */
function fokuskanPanel() {
    nextTick(() =>
        requestAnimationFrame(() => {
            panel.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }),
    );
}

onMounted(fokuskanPanel);

// Sampel berganti tanpa komponen dibuat ulang, misalnya saat menekan baris lain
// selagi panel terbuka.
watch(() => props.sampel.id, fokuskanPanel);

/**
 * Judul dan isi ke papan klip, untuk dibaca atau dicocokkan di tempat lain.
 *
 * Isi dikirim utuh dari server, jadi yang tersalin juga utuh. Salinan yang
 * diam-diam terpotong di 4.000 karakter adalah jenis kesalahan yang baru
 * ketahuan setelah dipakai memutuskan sesuatu.
 */
const { copy, copied, isSupported: bisaSalin } = useClipboard({ copiedDuring: 1500, legacy: true });

const salinArtikel = () => copy(`${props.sampel.judul}\n\n${props.sampel.isi}`);

const tanggal = (w: string | null) => (w ? format(new Date(w), 'd MMM yyyy', { locale: id }) : 'Tanggal tidak diketahui');

const namaAlasanPrioritas: Record<string, string> = {
    kabur_judul_bersih: 'Dibahas di isi tapi tidak disebut di judul',
    sebutan_tipis: 'Disebut sekali lalu tidak lagi',
    pola_kontras: 'Memuat instansi lain yang sering tertukar',
    tag_bertentangan: 'Tag menyebut Pemkot padahal isinya tidak',
};
</script>

<template>
    <div ref="panel" class="scroll-mt-4">
        <Card class="border-primary/40">
            <CardContent class="space-y-4 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <Badge variant="outline">{{ sampel.media ?? 'Media belum ditautkan' }}</Badge>
                        <span class="text-muted-foreground">{{ tanggal(sampel.tanggal_publikasi) }}</span>
                        <Badge v-if="sampel.status_label === 'terkunci_test'" variant="destructive"> Test terkunci </Badge>
                        <Badge v-if="sampel.label_manual" variant="secondary">
                            Sudah berlabel: {{ sampel.label_manual === 'relevan' ? 'Relevan' : 'Tidak relevan' }}
                        </Badge>
                    </div>

                    <span class="text-xs text-muted-foreground">
                        Antrean <strong>{{ sampel.antrean }}</strong
                        >, sisa
                        <strong class="angka">{{ sampel.sisa_antrean }}</strong>
                    </span>
                </div>

                <div class="flex items-start gap-2">
                    <h2 class="min-w-0 flex-1 text-lg font-semibold leading-snug">{{ sampel.judul }}</h2>

                    <Button
                        v-if="bisaSalin"
                        variant="ghost"
                        size="sm"
                        class="shrink-0"
                        :aria-label="copied ? 'Judul dan isi tersalin' : 'Salin judul dan isi artikel'"
                        @click="salinArtikel"
                    >
                        <component :is="copied ? Check : Copy" class="mr-1 h-4 w-4" aria-hidden="true" />
                        <span class="text-xs">{{ copied ? 'Tersalin' : 'Salin' }}</span>
                    </Button>
                </div>

                <p v-if="sampel.excerpt" class="text-sm text-muted-foreground">{{ sampel.excerpt }}</p>

                <div v-if="sampel.tag_sumber?.length || sampel.kategori_sumber?.length" class="flex flex-wrap gap-1">
                    <Badge v-for="k in sampel.kategori_sumber ?? []" :key="`k-${k}`" variant="outline" class="text-[10px]">
                        {{ k }}
                    </Badge>
                    <Badge v-for="t in sampel.tag_sumber ?? []" :key="`t-${t}`" variant="secondary" class="text-[10px]">
                        {{ t }}
                    </Badge>
                </div>

                <!--
                Alasan artikel ini muncul di urutan atas. Antrean prioritas yang
                tidak bisa ditanya alasannya akan diabaikan pada hari ketiga.
            -->
                <div v-if="sampel.priority_reasons && Object.keys(sampel.priority_reasons).length" class="rounded border p-2">
                    <p class="text-[11px] font-medium text-muted-foreground">Mengapa artikel ini didahulukan (skor {{ sampel.priority_score }})</p>
                    <ul class="mt-1 space-y-0.5">
                        <li v-for="(bobot, kunci) in sampel.priority_reasons" :key="kunci" class="text-[11px] text-muted-foreground">
                            {{ namaAlasanPrioritas[kunci] ?? kunci }}
                            <span class="angka">+{{ bobot }}</span>
                        </li>
                    </ul>
                </div>

                <div>
                    <p class="overflow-y-auto whitespace-pre-line text-sm leading-relaxed" :class="isiPanjang ? 'max-h-none' : 'max-h-56'">
                        {{ sampel.isi }}
                    </p>
                    <button type="button" class="mt-1 text-xs underline" @click="isiPanjang = !isiPanjang">
                        {{ isiPanjang ? 'Ringkas isi' : 'Tampilkan seluruh isi' }}
                    </button>
                </div>

                <a
                    v-if="sampel.url"
                    :href="sampel.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 text-xs underline"
                >
                    Buka halaman aslinya <ExternalLink class="h-3 w-3" aria-hidden="true" />
                </a>

                <div class="space-y-3 border-t pt-3">
                    <p class="text-sm font-medium">Apakah artikel ini secara substantif membahas Pemerintah Kota Kendari?</p>

                    <div class="flex flex-wrap gap-2">
                        <Button :variant="form.label === 'relevan' ? 'default' : 'outline'" @click="form.label = 'relevan'">
                            <CheckCircle2 class="mr-1 h-4 w-4" aria-hidden="true" />
                            Relevan
                            <kbd class="ml-2 rounded bg-muted px-1 text-[10px] text-foreground">R</kbd>
                        </Button>
                        <Button :variant="form.label === 'tidak_relevan' ? 'default' : 'outline'" @click="form.label = 'tidak_relevan'">
                            <XCircle class="mr-1 h-4 w-4" aria-hidden="true" />
                            Tidak relevan
                            <kbd class="ml-2 rounded bg-muted px-1 text-[10px] text-foreground">T</kbd>
                        </Button>
                        <Button variant="ghost" @click="lewati">
                            <SkipForward class="mr-1 h-4 w-4" aria-hidden="true" />
                            Lewati
                            <kbd class="ml-2 rounded bg-muted px-1 text-[10px]">S</kbd>
                        </Button>
                    </div>

                    <div v-if="form.label" class="space-y-2">
                        <label class="text-xs font-medium">
                            Alasan
                            <span v-if="alasanWajib" class="text-sentimen-negatif">wajib</span>
                            <span v-else class="text-muted-foreground">opsional</span>
                        </label>

                        <div class="flex flex-wrap gap-1">
                            <button
                                v-for="a in alasanTersedia"
                                :key="a.nilai"
                                type="button"
                                class="rounded border px-2 py-1 text-[11px] hover:bg-muted"
                                :class="form.alasan === a.nilai ? 'border-primary bg-muted font-medium' : ''"
                                @click="form.alasan = form.alasan === a.nilai ? null : a.nilai"
                            >
                                {{ a.label }}
                            </button>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            <label class="text-xs font-medium">Tingkat kesulitan</label>
                            <select v-model="form.kesulitan" class="h-7 rounded border bg-background px-2 text-xs">
                                <option value="normal">Normal</option>
                                <option value="hard_positive">Hard positive</option>
                                <option value="hard_negative">Hard negative</option>
                            </select>
                        </div>

                        <p v-if="form.errors.alasan" class="text-xs text-sentimen-negatif">{{ form.errors.alasan }}</p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button :disabled="!bisaSimpan || form.processing" @click="simpan">
                            Simpan dan lanjut
                            <kbd class="ml-2 rounded bg-background/20 px-1 text-[10px]">Enter</kbd>
                        </Button>
                        <Button variant="ghost" size="sm" @click="tutup">Tutup</Button>
                    </div>

                    <p class="text-[11px] text-muted-foreground">
                        Keputusan Anda tidak pernah ditimpa analisis ulang. Setiap perubahan label tercatat beserta nilai sebelum dan sesudahnya.
                    </p>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
