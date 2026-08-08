<script setup lang="ts">
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { ArrowLeft, Check, Copy, ExternalLink, RotateCcw } from 'lucide-vue-next';
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
        status_proses: string;
        media: { id: number; nama: string } | null;
        sumber_feed: { id: number; nama: string } | null;
        analisis_sentimen: Analisis[];
    };
}>();

const { formatAngka } = useFormatAngka();

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

/**
 * Koreksi manusia bisa dicabut, dan mencabutnya berarti menilai ulang.
 *
 * Sentimen menyimpan `label_model` dan `label_manual` terpisah, jadi mencabut
 * koreksi sentimen saja akan memunculkan kembali putusan AI dengan sendirinya.
 * Relevansi tidak: kolomnya cuma satu dan sudah ditimpa keputusan admin,
 * sehingga putusan AI yang lama memang tidak tersimpan di mana pun.
 */
const konfirmasiReset = ref(false);

const sedangReset = ref(false);

function reset() {
    konfirmasiReset.value = false;
    sedangReset.value = true;

    router.post(
        `/admin/artikel/${props.artikel.id}/reset`,
        {},
        // Sama seperti di halaman daftar: tombolnya sudah terkunci dengan ikon
        // berputar, jadi bilah progres bawaan Inertia hanya menambah penanda
        // kedua untuk satu pekerjaan yang sama.
        { preserveScroll: true, showProgress: false, onFinish: () => (sedangReset.value = false) },
    );
}

const adaKoreksi = (analisis: Analisis) => analisis.relevan_manual !== null || analisis.label_manual !== null;

/**
 * Status mentah tidak layak dibaca manusia.
 *
 * `tidak_relevan` dan `perlu_review` adalah nama kolom, bukan kalimat. Yang
 * tampil di badge harus sama dengan nama tahap di halaman daftar, supaya admin
 * tahu di tab mana artikel ini akan ditemukannya lagi.
 */
const labelProses: Record<string, string> = {
    mentah: 'Belum diklasifikasi',
    isi_diambil: 'Belum diklasifikasi',
    perlu_review: 'Menunggu review',
    dianalisis: 'Selesai',
    selesai: 'Selesai',
    tidak_relevan: 'Selesai',
    gagal: 'Gagal',
};

const warnaRelevansi = (relevan: boolean) =>
    relevan
        ? 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
        : 'border-transparent bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300';

const waktu = (nilai: string | null) => (nilai ? format(new Date(nilai), 'd MMMM yyyy, HH:mm', { locale: id }) : '-');

const tersalin = ref(false);

/**
 * Judul dan isi disalin sekaligus, dipisah baris kosong.
 *
 * Yang ditempel pengguna hampir selalu artikel utuh, bukan potongannya, jadi dua
 * tombol terpisah cuma memaksa dua kali klik dan dua kali pindah aplikasi.
 */
async function salin() {
    await navigator.clipboard.writeText([props.artikel.judul, props.artikel.isi].filter(Boolean).join('\n\n'));
    tersalin.value = true;
    setTimeout(() => (tersalin.value = false), 1500);
}
</script>

<template>
    <Head :title="artikel.judul" />

    <LayoutAdmin
        :breadcrumbs="[
            { title: 'Artikel', href: '/admin/artikel' },
            { title: 'Detail', href: '#' },
        ]"
    >
        <!-- Tombol kembali, bukan hanya breadcrumb. Halaman ini dibuka dari
             tombol Lihat di dalam toast, jadi pengguna sering sampai di sini
             tanpa pernah melewati daftar artikel dan tidak punya jalan pulang
             yang jelas. -->
        <Link href="/admin/artikel" class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
            <ArrowLeft class="size-4" />
            Kembali ke daftar artikel
        </Link>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <Card>
                    <CardContent class="space-y-3 p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge :variant="artikel.status_proses === 'gagal' ? 'destructive' : 'outline'">
                                {{ labelProses[artikel.status_proses] ?? artikel.status_proses }}
                            </Badge>
                        </div>

                        <div class="flex items-start gap-2">
                            <h1 class="flex-1 text-xl font-semibold leading-snug">{{ artikel.judul }}</h1>

                            <Button size="sm" variant="outline" class="h-7 shrink-0 text-xs" @click="salin">
                                <component :is="tersalin ? Check : Copy" class="size-3" />
                                {{ tersalin ? 'Tersalin' : 'Salin artikel' }}
                            </Button>
                        </div>

                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-muted-foreground sm:grid-cols-3">
                            <div>
                                <dt class="inline">Media:</dt>
                                <dd class="inline">{{ artikel.media?.nama ?? 'Belum ditautkan' }}</dd>
                            </div>
                            <div>
                                <dt class="inline">Penulis:</dt>
                                <dd class="inline">{{ artikel.penulis ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt class="inline">Kata:</dt>
                                <dd class="angka inline">{{ formatAngka(artikel.jumlah_kata) }}</dd>
                            </div>
                            <div>
                                <dt class="inline">Terbit:</dt>
                                <dd class="inline">{{ waktu(artikel.dipublikasikan_at) }}</dd>
                            </div>
                            <div>
                                <dt class="inline">Diambil:</dt>
                                <dd class="inline">{{ waktu(artikel.diambil_at) }}</dd>
                            </div>
                            <div>
                                <dt class="inline">Sumber:</dt>
                                <dd class="inline">{{ artikel.sumber_feed?.nama ?? '-' }}</dd>
                            </div>
                        </dl>

                        <a :href="artikel.url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-xs underline">
                            Buka halaman aslinya <ExternalLink class="h-3 w-3" aria-hidden="true" />
                        </a>
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

                        <div v-for="analisis in artikel.analisis_sentimen" :key="analisis.id" class="space-y-2 rounded-md border p-3">
                            <!--
                                Relevansi ditampilkan lebih dulu dan selalu, bukan
                                hanya sentimennya. Ia keputusan pertama yang
                                menentukan segalanya: artikel yang tidak relevan
                                tidak pernah dinilai nadanya dan tidak pernah masuk
                                dashboard, dan tanpa badge ini layar detail tidak
                                menyebutkan alasan itu sama sekali.
                            -->
                            <div class="flex flex-wrap items-center gap-1.5">
                                <Badge variant="outline" :class="warnaRelevansi(analisis.relevan)">
                                    {{ analisis.relevan ? 'Relevan' : 'Tidak relevan' }}
                                </Badge>

                                <BadgeSentimen v-if="analisis.relevan" :label="analisis.label_efektif" :perlu-review="analisis.perlu_review" />

                                <!-- <Badge v-if="analisis.perlu_review" variant="outline">
                                    Perlu review
                                </Badge> -->

                                <!-- Membedakan keputusan manusia dari kebetulan
                                     model sependapat. -->
                                <Badge v-if="analisis.relevan_manual !== null || analisis.label_manual" variant="outline"> Dikoreksi manusia </Badge>
                            </div>

                            <!-- Reset hanya muncul kalau memang ada yang bisa
                                 dicabut. Tombol yang selalu tampil mengundang
                                 klik pada artikel yang tidak pernah dikoreksi,
                                 dan itu berarti satu panggilan Gemini terbuang
                                 untuk mengulang hasil yang sudah ada. -->
                            <button
                                v-if="adaKoreksi(analisis)"
                                type="button"
                                class="inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-50"
                                :disabled="sedangReset"
                                @click="konfirmasiReset = true"
                            >
                                <RotateCcw class="size-3" />
                                {{ sedangReset ? 'Menilai ulang...' : 'Reset koreksi' }}
                            </button>

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
                                        <li v-for="(kutipan, i) in analisis.evidence" :key="i">“{{ kutipan }}”</li>
                                    </ul>
                                </details>

                                <!-- Dua kalimat untuk dua keputusan, bukan satu
                                     yang menggabungkan keduanya. `provider`
                                     menjawab siapa yang menilai relevansi dan
                                     `model_versi` menjawab model apa yang
                                     menilai sentimen. Sejak relevansi bisa
                                     dikerjakan IndoBERT keduanya sering
                                     berbeda, dan menyambungnya jadi satu
                                     kalimat berbunyi "Dinilai indobert:
                                     gemini-3.5-flash". -->
                                <p v-if="analisis.provider" class="text-[11px] text-muted-foreground">
                                    Relevansi dinilai {{ analisis.provider }}.
                                    <template v-if="analisis.relevan && analisis.model_versi">
                                        Sentimen dinilai {{ analisis.model_versi }}.
                                    </template>
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
                                    Dikoreksi menjadi <strong>{{ analisis.label_manual }}</strong> oleh {{ analisis.pengoreksi?.name ?? '-' }},
                                    {{ waktu(analisis.dikoreksi_at) }}.
                                    <span v-if="analisis.catatan_koreksi" class="text-muted-foreground"> “{{ analisis.catatan_koreksi }}” </span>
                                </p>

                                <div v-if="sedangKoreksi === analisis.id" class="space-y-2">
                                    <div class="flex gap-1">
                                        <Button
                                            v-for="pilihan in ['negatif', 'netral', 'positif'] as Label[]"
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
                                        <Button size="sm" class="h-7 text-xs" :disabled="form.processing" @click="simpan(analisis)"> Simpan </Button>
                                        <Button size="sm" variant="ghost" class="h-7 text-xs" @click="sedangKoreksi = null"> Batal </Button>
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

                                <Button v-else size="sm" variant="outline" class="h-7 w-full text-xs" @click="mulaiKoreksi(analisis)">
                                    {{ analisis.label_manual ? 'Ubah koreksi' : 'Koreksi label' }}
                                </Button>
                            </template>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>

        <Dialog :open="konfirmasiReset" @update:open="(buka) => (konfirmasiReset = buka)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Cabut koreksi dan nilai ulang?</DialogTitle>
                    <DialogDescription>
                        Koreksi manusia pada artikel ini dihapus, lalu Gemini menilainya kembali dari nol. Satu permintaan akan terkirim dan memakai
                        kuota.
                    </DialogDescription>
                </DialogHeader>

                <p class="rounded-md bg-muted/50 p-3 text-sm font-medium">{{ artikel.judul }}</p>

                <p class="text-xs text-muted-foreground">
                    Hasilnya bisa berbeda dari penilaian AI sebelumnya, karena yang dijalankan penilaian baru, bukan pemulihan yang lama.
                </p>

                <DialogFooter>
                    <Button variant="outline" @click="konfirmasiReset = false">Batal</Button>
                    <Button class="bg-violet-600 text-white hover:bg-violet-700" @click="reset"> Cabut dan nilai ulang </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </LayoutAdmin>
</template>
