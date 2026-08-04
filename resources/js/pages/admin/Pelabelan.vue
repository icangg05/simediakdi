<script setup lang="ts">
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { useFormatAngka } from '@/composables/useFormatAngka';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

type Label = 'negatif' | 'netral' | 'positif';

interface Tugas {
    artikel: {
        id: number;
        judul: string;
        kutipan: string;
        url: string;
        media: string | null;
        dipublikasikan_at: string | null;
    };
    tebakanModel: { relevan: boolean; label: Label | null; keyakinan: number | null } | null;
    labelTersimpan: { label: Label | null; relevan: boolean; catatan: string | null } | null;
}

const props = defineProps<{
    konteksTersedia: Array<{ id: number; nama: string; deskripsi: string | null; utama: boolean }>;
    konteksAktif: { id: number; nama: string; deskripsi: string | null } | null;
    ronde: number;
    mode: string;
    modeTersedia: Record<string, string>;
    sisaPerMode: Record<string, number> | null;
    tugas: Tugas | null;
    sedangMengulang: boolean;
    riwayat: {
        sebelumnya: number | null;
        berikutnya: number | null;
        terakhir: Array<{ artikel_id: number; judul: string; label: Label | null; relevan: boolean }>;
    } | null;
    progres: {
        selesai: number;
        target: number;
        targetRelevan: number;
        perKonteks: number;
        relevanPerKonteks: number;
    };
}>();

const { formatAngka } = useFormatAngka();

/**
 * Jawaban model disembunyikan sampai pelabel memutuskan.
 *
 * Ini bukan detail kecil. Kalau pelabel melihat jawaban model lebih dulu, ia
 * cenderung menyetujuinya, dan gold set berhenti mengukur apa pun, angka
 * akurasi yang keluar cuma memantulkan model kembali ke dirinya sendiri.
 */
const sudahMemutuskan = ref(false);
const catatan = ref(props.tugas?.labelTersimpan?.catatan ?? '');
const daftarTerbuka = ref(false);
const isiArtikel = ref<HTMLElement | null>(null);

const form = useForm({
    artikel_id: 0,
    konteks_pantauan_id: 0,
    relevan_gold: true,
    label_gold: null as Label | null,
    catatan: '',
    ronde: props.ronde,
    // Ikut dikirim di badan permintaan supaya pengalihan setelah menyimpan
    // kembali ke mode yang sama. Query string tidak terbawa pada POST.
    mode: props.mode,
});

function kirim(relevan: boolean, label: Label | null) {
    if (!props.tugas || !props.konteksAktif || form.processing) return;

    sudahMemutuskan.value = true;

    form.artikel_id = props.tugas.artikel.id;
    form.konteks_pantauan_id = props.konteksAktif.id;
    form.relevan_gold = relevan;
    form.label_gold = label;
    form.catatan = catatan.value;
    form.mode = props.mode;

    form.post('/admin/pelabelan', {
        ...opsiPindah,
        onSuccess: () => {
            catatan.value = '';
            sudahMemutuskan.value = false;
        },
    });
}

/**
 * Semua perpindahan memakai preserveScroll dan preserveState.
 *
 * Tanpa keduanya, halaman melompat ke atas tiap kali pindah artikel dan
 * komponen dipasang ulang. Pada pekerjaan 400 baris, lompatan itu terasa di
 * setiap keputusan.
 */
const opsiPindah = { preserveScroll: true, preserveState: true } as const;

function buka(artikelId: number) {
    if (!props.konteksAktif) return;

    router.get(
        '/admin/pelabelan',
        { konteks: props.konteksAktif.id, ronde: props.ronde, mode: props.mode, artikel: artikelId },
        opsiPindah,
    );
}

function gantiMode(mode: string) {
    if (!props.konteksAktif) return;

    router.get(
        '/admin/pelabelan',
        { konteks: props.konteksAktif.id, ronde: props.ronde, mode },
        opsiPindah,
    );
}

function sebelumnya() {
    if (props.riwayat?.sebelumnya) buka(props.riwayat.sebelumnya);
}

function berikutnya() {
    // Di ujung depan riwayat, maju berarti kembali ke antrean artikel baru.
    if (props.riwayat?.berikutnya) buka(props.riwayat.berikutnya);
    else if (props.sedangMengulang) kembaliKeAntrean();
}

/**
 * Pilihan yang tersimpan untuk artikel ini, dipakai menyalakan tombolnya.
 * Null berarti artikel ini belum pernah dinilai.
 */
const pilihanTersimpan = computed<Label | 'tidak_relevan' | null>(() => {
    const tersimpan = props.tugas?.labelTersimpan;

    if (!tersimpan) return null;

    return tersimpan.relevan ? tersimpan.label : 'tidak_relevan';
});

/**
 * Warna, teks tebal, dan aria-pressed sekaligus. Warna saja tidak cukup,
 * aturan yang sama dengan BadgeSentimen (dokumen 04 bagian A.3).
 */
const gayaAktif: Record<string, string> = {
    negatif: 'bg-sentimen-negatif-lembut text-sentimen-negatif font-semibold ring-2 ring-sentimen-negatif',
    netral: 'bg-sentimen-netral-lembut text-sentimen-netral font-semibold ring-2 ring-sentimen-netral',
    positif: 'bg-sentimen-positif-lembut text-sentimen-positif font-semibold ring-2 ring-sentimen-positif',
    tidak_relevan: 'bg-muted font-semibold ring-2 ring-muted-foreground/40',
};

const pilihan: Array<{ nilai: Label | 'tidak_relevan'; teks: string; tombol: string }> = [
    { nilai: 'negatif', teks: 'Negatif', tombol: '1' },
    { nilai: 'netral', teks: 'Netral', tombol: '2' },
    { nilai: 'positif', teks: 'Positif', tombol: '3' },
    // Dokumen 04 bagian C.3 menetapkan `r`. Diganti `4` agar keempat pilihan
    // berderet di satu baris angka, satu tangan menjangkau semuanya tanpa
    // berpindah, dan tidak ada huruf yang bertabrakan dengan pintasan browser.
    { nilai: 'tidak_relevan', teks: 'Tidak relevan', tombol: '4' },
];

function pilih(nilai: Label | 'tidak_relevan') {
    if (nilai === 'tidak_relevan') kirim(false, null);
    else kirim(true, nilai);
}

function kembaliKeAntrean() {
    if (!props.konteksAktif) return;

    router.get(
        '/admin/pelabelan',
        { konteks: props.konteksAktif.id, ronde: props.ronde, mode: props.mode },
        opsiPindah,
    );
}

function gantiKonteks(id: number) {
    router.get('/admin/pelabelan', { konteks: id, ronde: props.ronde, mode: props.mode });
}

function tombolKeyboard(e: KeyboardEvent) {
    // Pintasan browser tidak boleh dibajak. e.key untuk Ctrl+R tetap bernilai
    // "r", jadi tanpa penjagaan ini menekan Ctrl+R memblokir muat ulang halaman
    // sekaligus menyimpan label tanpa disadari pelabel. Berlaku juga untuk
    // Ctrl+1 sampai Ctrl+3 yang dipakai berpindah tab.
    if (e.ctrlKey || e.metaKey || e.altKey) return;

    // Jangan bajak tombol saat pelabel sedang mengetik catatan atau memilih
    // konteks, panah pada select harus mengganti pilihan, bukan pindah artikel.
    if (
        e.target instanceof HTMLInputElement ||
        e.target instanceof HTMLTextAreaElement ||
        e.target instanceof HTMLSelectElement
    ) {
        return;
    }

    const aksi: Record<string, () => void> = {
        '1': () => kirim(true, 'negatif'),
        '2': () => kirim(true, 'netral'),
        '3': () => kirim(true, 'positif'),
        '4': () => kirim(false, null),
        ArrowLeft: () => sebelumnya(),
        ArrowRight: () => berikutnya(),
    };

    if (aksi[e.key]) {
        e.preventDefault();
        aksi[e.key]();
    }
}

onMounted(() => window.addEventListener('keydown', tombolKeyboard));
onUnmounted(() => window.removeEventListener('keydown', tombolKeyboard));

watch(
    () => props.tugas?.artikel.id,
    () => {
        sudahMemutuskan.value = false;
        // Catatan lama ikut terbawa saat artikel dibuka ulang, supaya pelabel
        // bisa menyuntingnya, bukan mengetik ulang dari nol.
        catatan.value = props.tugas?.labelTersimpan?.catatan ?? '';
        // preserveState mempertahankan elemen isi artikel apa adanya, termasuk
        // posisi gulirnya. Untuk artikel baru itu salah, teksnya harus mulai
        // dari paragraf pertama.
        if (isiArtikel.value) isiArtikel.value.scrollTop = 0;
    },
);
</script>

<template>
    <Head title="Ruang kerja pelabelan" />

    <LayoutAdmin>
        <div class="mx-auto max-w-3xl space-y-4">
            <header class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <!-- Yang ditonjolkan label relevan, bukan total: hanya baris
                         relevan yang dipakai evaluasi model. -->
                    <p class="text-sm">
                        <span class="font-medium">
                            {{ formatAngka(progres.relevanPerKonteks) }} dari
                            {{ formatAngka(progres.targetRelevan) }} label relevan
                        </span>
                        <span class="text-muted-foreground"> untuk mengukur sentimen</span>
                    </p>
                    <div class="mt-1 h-1.5 w-56 overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full transition-all"
                            :class="progres.relevanPerKonteks >= progres.targetRelevan ? 'bg-sentimen-positif' : 'bg-primary'"
                            :style="{
                                width: `${Math.min(100, (progres.relevanPerKonteks / progres.targetRelevan) * 100).toFixed(2)}%`,
                            }"
                        />
                    </div>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Ronde {{ ronde }} · {{ formatAngka(progres.perKonteks) }} artikel sudah dilabeli.
                        Label <strong>tidak relevan</strong> tetap terpakai: itu yang mengukur presisi
                        penyaring. Label relevan yang mengukur sentimen.
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Button
                        v-if="riwayat?.terakhir.length"
                        variant="outline"
                        size="sm"
                        class="h-8"
                        @click="daftarTerbuka = !daftarTerbuka"
                    >
                        {{ daftarTerbuka ? 'Tutup' : 'Sudah dilabeli' }}
                    </Button>

                    <select
                        v-if="konteksAktif"
                        class="h-8 rounded-md border border-input bg-background px-2 text-sm"
                        :value="mode"
                        aria-label="Cara memilih artikel"
                        @change="gantiMode(($event.target as HTMLSelectElement).value)"
                    >
                        <option v-for="(teks, kunci) in modeTersedia" :key="kunci" :value="kunci">
                            {{ teks }}<template v-if="sisaPerMode"> ({{ sisaPerMode[kunci] }})</template>
                        </option>
                    </select>

                    <!-- Pemilih konteks hanya dirender kalau memang ada lebih
                         dari satu. Dropdown berisi satu opsi menyiratkan ada
                         pilihan lain, padahal tidak. -->
                    <select
                        v-if="konteksAktif && konteksTersedia.length > 1"
                        class="h-8 rounded-md border border-input bg-background px-2 text-sm"
                        :value="konteksAktif.id"
                        aria-label="Konteks yang dinilai"
                        @change="gantiKonteks(Number(($event.target as HTMLSelectElement).value))"
                    >
                        <option v-for="k in konteksTersedia" :key="k.id" :value="k.id">{{ k.nama }}</option>
                    </select>
                </div>
            </header>

            <!-- Mode selain acak memilih artikel berdasarkan tebakan model,
                 jadi sampelnya tidak mewakili keseluruhan. Peringatannya
                 ditampilkan, bukan hanya ditulis di kode, supaya angka dari dua
                 jenis sampel tidak tercampur tanpa sadar. -->
            <p
                v-if="mode !== 'acak'"
                class="rounded-md bg-sentimen-review-lembut p-2 text-xs text-sentimen-review"
            >
                Mode terarah. Label dari sini berguna mengukur F1 per kelas, tapi
                <strong>tidak boleh dipakai menghitung akurasi keseluruhan</strong>, artikelnya dipilih
                berdasarkan tebakan model, bukan acak. Kerjakan mode acak lebih dulu sampai cukup.
            </p>

            <!-- Daftar keputusan yang sudah dibuat, agar salah tekan beberapa
                 artikel lalu tetap bisa diperbaiki tanpa mundur satu per satu. -->
            <Card v-if="daftarTerbuka && riwayat?.terakhir.length">
                <CardContent class="p-3">
                    <p class="mb-2 text-xs text-muted-foreground">
                        20 label terakhir untuk konteks ini. Klik untuk membukanya kembali dan mengubah.
                    </p>
                    <ul class="max-h-60 divide-y overflow-y-auto text-xs">
                        <li v-for="baris in riwayat.terakhir" :key="baris.artikel_id">
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 py-1.5 text-left hover:bg-muted"
                                @click="buka(baris.artikel_id)"
                            >
                                <span class="w-24 shrink-0">
                                    <BadgeSentimen v-if="baris.relevan" :label="baris.label" />
                                    <span v-else class="text-muted-foreground">Tidak relevan</span>
                                </span>
                                <span class="min-w-0 flex-1 truncate">{{ baris.judul }}</span>
                            </button>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <div
                v-if="sedangMengulang"
                class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-sentimen-review-lembut p-2 text-xs text-sentimen-review"
            >
                <span>
                    Anda sedang membuka kembali label yang sudah dibuat.
                    <template v-if="tugas?.labelTersimpan">
                        Pilihan sebelumnya:
                        <strong>{{ tugas.labelTersimpan.relevan ? tugas.labelTersimpan.label : 'tidak relevan' }}</strong>.
                    </template>
                    Memilih ulang akan menimpanya.
                </span>
                <Button variant="ghost" size="sm" class="h-6 text-xs" @click="kembaliKeAntrean">
                    Kembali ke antrean
                </Button>
            </div>

            <template v-if="tugas && konteksAktif">
                <Card>
                    <CardContent class="space-y-4 p-6">
                        <div class="rounded-md bg-muted p-3">
                            <p class="text-xs uppercase tracking-wide text-muted-foreground">Pertanyaannya</p>
                            <p class="text-base font-semibold leading-snug">
                                Apakah artikel ini secara substantif membahas {{ konteksAktif.nama }}?
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Penyebutan bukan pembahasan. Kalau kalimat yang memuat konteks itu dihapus
                                dan beritanya masih utuh, jawabannya tidak relevan.
                            </p>
                        </div>

                        <div>
                            <h1 class="text-lg font-semibold leading-snug">{{ tugas.artikel.judul }}</h1>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ tugas.artikel.media ?? 'Media belum ditautkan' }} ·
                                <a :href="tugas.artikel.url" target="_blank" rel="noopener noreferrer" class="underline">
                                    buka aslinya
                                </a>
                            </p>
                        </div>

                        <p ref="isiArtikel" class="max-h-72 overflow-y-auto whitespace-pre-line text-sm leading-relaxed">
                            {{ tugas.artikel.kutipan }}
                        </p>

                        <div class="space-y-2 border-t pt-4">
                            <p class="text-sm font-medium">
                                Nada berita ini terhadap <span class="underline">{{ konteksAktif.nama }}</span>?
                            </p>

                            <div class="flex flex-wrap items-center gap-2">
                                <Button
                                    v-for="opsi in pilihan"
                                    :key="opsi.nilai"
                                    variant="outline"
                                    :class="pilihanTersimpan === opsi.nilai ? gayaAktif[opsi.nilai] : ''"
                                    :aria-pressed="pilihanTersimpan === opsi.nilai"
                                    :disabled="form.processing"
                                    @click="pilih(opsi.nilai)"
                                >
                                    <Check
                                        v-if="pilihanTersimpan === opsi.nilai"
                                        class="mr-1.5 h-3.5 w-3.5"
                                        aria-hidden="true"
                                    />
                                    {{ opsi.teks }}
                                    <kbd class="ml-2 rounded bg-muted px-1.5 text-xs">{{ opsi.tombol }}</kbd>
                                </Button>

                                <span class="ml-auto flex items-center gap-1">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-8 w-8"
                                        :disabled="!riwayat?.sebelumnya || form.processing"
                                        aria-label="Label sebelumnya"
                                        title="Label sebelumnya (←)"
                                        @click="sebelumnya"
                                    >
                                        <ChevronLeft class="h-4 w-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-8 w-8"
                                        :disabled="(!riwayat?.berikutnya && !sedangMengulang) || form.processing"
                                        aria-label="Label berikutnya"
                                        title="Label berikutnya (→)"
                                        @click="berikutnya"
                                    >
                                        <ChevronRight class="h-4 w-4" />
                                    </Button>
                                </span>
                            </div>

                            <Input v-model="catatan" placeholder="Catatan (opsional), alasan memilih label ini" class="h-8" />
                        </div>

                        <!-- Baru muncul setelah pelabel memutuskan, sebagai umpan balik. -->
                        <div v-if="sudahMemutuskan && tugas.tebakanModel" class="border-t pt-3 text-xs text-muted-foreground">
                            Tebakan model:
                            <template v-if="tugas.tebakanModel.relevan">
                                <BadgeSentimen :label="tugas.tebakanModel.label" class="ml-1 align-middle" />
                                keyakinan {{ tugas.tebakanModel.keyakinan }}
                            </template>
                            <template v-else>menilai artikel ini tidak relevan</template>
                        </div>
                    </CardContent>
                </Card>

                <p class="text-center text-xs text-muted-foreground">
                    Pintasan: <kbd>1</kbd> negatif · <kbd>2</kbd> netral · <kbd>3</kbd> positif ·
                    <kbd>4</kbd> tidak relevan · <kbd>←</kbd> sebelumnya · <kbd>→</kbd> berikutnya
                </p>
            </template>

            <Card v-else>
                <CardContent class="p-10 text-center text-sm text-muted-foreground">
                    <template v-if="!konteksAktif">
                        Belum ada konteks pantauan aktif. Tambahkan dulu di halaman konteks.
                    </template>
                    <template v-else-if="mode !== 'acak'">
                        Tidak ada lagi artikel yang cocok dengan mode
                        <strong>{{ modeTersedia[mode] }}</strong> untuk konteks ini.
                        Kembali ke mode acak, ganti konteks, atau tunggu crawler mengumpulkan artikel baru.
                    </template>
                    <template v-else>
                        Semua artikel yang tersedia sudah dilabeli untuk konteks ini pada ronde {{ ronde }}.
                        Ganti konteks di atas, atau tunggu crawler mengumpulkan artikel baru.
                    </template>
                </CardContent>
            </Card>
        </div>
    </LayoutAdmin>
</template>
