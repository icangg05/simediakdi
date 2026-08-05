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
    nomor_antrean: number | null;
    total_antrean: number;
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

/**
 * Hard case hanya punya satu bentuk untuk tiap label, jadi yang salah tidak
 * pernah ditawarkan.
 *
 * `hard_negative` berarti artikel yang tidak relevan tetapi terlihat relevan,
 * dan `hard_positive` kebalikannya. Server menolak pasangan yang tertukar,
 * tetapi menolak setelah orang menekan simpan berarti satu putaran hilang.
 * Keterangannya ikut ditulis di sini karena istilah "hard positive" tidak
 * memberi tahu siapa pun arah mana yang dimaksud.
 */
const opsiKesulitan = computed(() => [
    { nilai: 'normal', label: 'Normal' },
    form.label === 'relevan'
        ? { nilai: 'hard_positive', label: 'Hard positive, tidak terlihat relevan padahal iya' }
        : { nilai: 'hard_negative', label: 'Hard negative, terlihat relevan padahal bukan' },
]);

// Alasan lama ikut terbawa kalau labelnya berganti, dan alasan yang tidak
// cocok dengan labelnya ditolak server. Dikosongkan supaya pelabel memilih
// ulang, bukan mendapat galat validasi yang tidak dia sebabkan.
//
// Kesulitan disetel ulang karena alasan yang sama. Memilih hard positive lalu
// berganti ke tidak relevan meninggalkan pasangan yang mustahil, dan pilihannya
// tidak lagi ada di daftar sehingga tampak seperti Normal padahal bukan.
watch(
    () => form.label,
    () => {
        form.alasan = null;
        form.kesulitan = 'normal';
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
 * Sampel berikutnya adalah baris tepat di bawahnya pada tabel, dengan filter
 * dan kolom urut yang sedang dipakai.
 *
 * `setelah` yang menggantikan `sampel`, bukan sekadar membuangnya. Tanpa kursor
 * server hanya bisa mengambil baris teratas antrean, dan pada antrean yang
 * isinya tidak berubah setelah dilabeli, misalnya "Model ragu", baris teratas
 * itu artikel yang sama terus.
 */
function berikutnya() {
    const kursor = props.sampel.id;

    form.reset();

    const params = new URLSearchParams(window.location.search);
    params.delete('sampel');
    params.set('setelah', String(kursor));
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
    params.delete('setelah');

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
    dekat_ambang: 'Model sendiri ragu, peluangnya di sekitar 0,5',
    bertentangan_dengan_sinyal: 'Model yakin tapi berlawanan dengan sinyal kata kunci',
};
</script>

<template>
    <div ref="panel" class="scroll-mt-4">
        <!--
            Setinggi layar, tidak lebih. Yang menggulir di dalamnya hanya isi
            artikel, sementara pertanyaan dan tombol simpan tetap terlihat.
            Sebelumnya artikel panjang mendorong tombol simpan ke luar layar,
            dan pelabel menggulir turun lalu naik lagi untuk setiap satu sampel.
            Dikalikan seribu artikel, itu ongkos yang nyata.

            Pembatasnya mulai dari `sm`. Di ponsel tinggi layar sudah habis
            duluan oleh blok keputusan, dan memaksakan aturan yang sama di sana
            menyisakan jendela artikel setinggi dua baris.
        -->
        <Card class="flex flex-col border-primary/40 sm:max-h-[calc(100vh-6rem)]">
            <CardContent class="flex min-h-0 flex-1 flex-col gap-3 p-4">
                <div class="flex min-w-0 flex-wrap items-center justify-between gap-x-3 gap-y-1">
                    <div class="flex min-w-0 flex-wrap items-center gap-2 text-xs">
                        <Badge variant="outline">{{ sampel.media ?? 'Media belum ditautkan' }}</Badge>
                        <span class="text-muted-foreground">{{ tanggal(sampel.tanggal_publikasi) }}</span>
                        <Badge v-if="sampel.status_label === 'terkunci_test'" variant="destructive"> Test terkunci </Badge>
                        <Badge v-if="sampel.label_manual" variant="secondary">
                            Sudah berlabel: {{ sampel.label_manual === 'relevan' ? 'Relevan' : 'Tidak relevan' }}
                        </Badge>
                    </div>

                    <!--
                        Nomor urut yang sama dengan kolom No di tabel. Itu yang
                        membuat pelabel bisa memastikan panel dan tabel memang
                        menunjuk baris yang sama.
                    -->
                    <span class="min-w-0 text-xs text-muted-foreground">
                        Antrean <strong>{{ sampel.antrean }}</strong
                        >, nomor
                        <strong class="angka">{{ sampel.nomor_antrean ?? '-' }}</strong>
                        dari <strong class="angka">{{ sampel.total_antrean }}</strong
                        >, sisa <strong class="angka">{{ sampel.sisa_antrean }}</strong>
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

                <div v-if="sampel.tag_sumber?.length || sampel.kategori_sumber?.length" class="flex flex-wrap gap-1">
                    <Badge v-for="k in sampel.kategori_sumber ?? []" :key="`k-${k}`" variant="outline" class="text-[10px]">
                        {{ k }}
                    </Badge>
                    <Badge v-for="t in sampel.tag_sumber ?? []" :key="`t-${t}`" variant="secondary" class="text-[10px]">
                        {{ t }}
                    </Badge>
                </div>

                <!--
                    Alasan artikel ini muncul di urutan atas. Antrean prioritas
                    yang tidak bisa ditanya alasannya akan diabaikan pada hari
                    ketiga.

                    Satu baris yang membungkus, bukan daftar. Isinya keterangan
                    yang dibaca sekilas lalu diabaikan, dan sebagai daftar ia
                    memakan seperlima layar yang seharusnya jadi jatah isi
                    artikel.
                -->
                <p
                    v-if="sampel.priority_reasons && Object.keys(sampel.priority_reasons).length"
                    class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted-foreground"
                >
                    <span class="font-medium">Didahulukan (skor {{ sampel.priority_score }}):</span>
                    <span v-for="(bobot, kunci) in sampel.priority_reasons" :key="kunci">
                        {{ namaAlasanPrioritas[kunci] ?? kunci }}
                        <span class="angka">+{{ bobot }}</span>
                    </span>
                </p>

                <!--
                    Excerpt sengaja tidak ditampilkan. Isinya paragraf pembuka
                    dari `isi` yang sudah ada tepat di bawahnya, jadi yang
                    dihasilkan cuma paragraf yang sama dua kali dan satu layar
                    yang lebih pendek untuk membacanya.
                -->
                <!--
                    `min-w-0` dan `break-words` mencegah kartu melebar melewati
                    layar. Anak flex tidak boleh menyusut di bawah lebar kata
                    terpanjangnya, jadi satu tautan panjang di badan artikel
                    cukup untuk mendorong seluruh kartu keluar layar dan
                    memunculkan gulir horizontal di halaman.
                -->
                <p class="min-w-0 whitespace-pre-line break-words text-sm leading-relaxed sm:min-h-0 sm:flex-1 sm:overflow-y-auto">
                    {{ sampel.isi }}
                </p>

                <a
                    v-if="sampel.url"
                    :href="sampel.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex shrink-0 items-center gap-1 text-xs underline"
                >
                    Buka halaman aslinya <ExternalLink class="h-3 w-3" aria-hidden="true" />
                </a>

                <div class="shrink-0 space-y-3 border-t pt-3">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                        <p class="text-sm font-medium">Apakah artikel ini secara substantif membahas Pemerintah Kota Kendari?</p>
                        <Button size="sm" :variant="form.label === 'relevan' ? 'default' : 'outline'" @click="form.label = 'relevan'">
                            <CheckCircle2 class="mr-1 h-4 w-4" aria-hidden="true" />
                            Relevan
                            <kbd class="ml-2 rounded bg-muted px-1 text-[10px] text-foreground">R</kbd>
                        </Button>
                        <Button size="sm" :variant="form.label === 'tidak_relevan' ? 'default' : 'outline'" @click="form.label = 'tidak_relevan'">
                            <XCircle class="mr-1 h-4 w-4" aria-hidden="true" />
                            Tidak relevan
                            <kbd class="ml-2 rounded bg-muted px-1 text-[10px] text-foreground">T</kbd>
                        </Button>
                        <Button size="sm" variant="ghost" @click="lewati">
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
                            <select v-model="form.kesulitan" class="h-7 max-w-full rounded border bg-background px-2 text-xs">
                                <option v-for="k in opsiKesulitan" :key="k.nilai" :value="k.nilai">{{ k.label }}</option>
                            </select>
                        </div>

                        <p v-if="form.errors.alasan" class="text-xs text-sentimen-negatif">{{ form.errors.alasan }}</p>
                        <p v-if="form.errors.kesulitan" class="text-xs text-sentimen-negatif">{{ form.errors.kesulitan }}</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button :disabled="!bisaSimpan || form.processing" @click="simpan">
                            Simpan dan lanjut
                            <kbd class="ml-2 rounded bg-background/20 px-1 text-[10px]">Enter</kbd>
                        </Button>
                        <Button variant="ghost" size="sm" @click="tutup">Tutup</Button>

                        <p class="ml-auto hidden max-w-md text-[11px] text-muted-foreground lg:block">
                            Keputusan Anda tidak pernah ditimpa analisis ulang. Setiap perubahannya tercatat beserta nilai sebelum dan sesudahnya.
                        </p>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
