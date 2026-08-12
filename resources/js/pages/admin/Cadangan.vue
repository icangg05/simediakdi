<script setup lang="ts">
/**
 * Cadangan database.
 *
 * Halaman ini menjawab tiga pertanyaan, dan urutan blok di layar mengikuti
 * urutan pertanyaannya, bukan urutan kerumitan teknisnya.
 *
 * 1. "Apakah datanya aman sekarang?" dijawab kop: umur cadangan terakhir.
 * 2. "Bagaimana membuat yang baru?" dijawab kartu aksi tepat di bawahnya.
 * 3. "Mana berkas yang saya cari?" dijawab arsip.
 *
 * Kesiapan server dan cara memulihkan ditaruh paling bawah karena keduanya
 * dibaca sekali lalu tidak dibaca lagi, kecuali saat ada yang rusak.
 *
 * Rona kartu mengikuti arti yang sudah ditetapkan KartuSeksi, tidak ada arti
 * baru yang diperkenalkan halaman ini. `brand` untuk aksi resmi yang menulis
 * berkas atas nama sistem, `biru` untuk pemantauan, `toska` untuk pekerjaan di
 * server sendiri, `netral` untuk arsip yang sudah jadi.
 */
import KartuSeksi from '@/components/domain/KartuSeksi.vue';
import KopHalaman from '@/components/domain/KopHalaman.vue';
import PilKop from '@/components/domain/PilKop.vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, router } from '@inertiajs/vue3';
import { format, formatDistanceToNow } from 'date-fns';
import { id } from 'date-fns/locale';
import {
    Archive,
    Check,
    Clock,
    Copy,
    Database,
    DatabaseBackup,
    Download,
    HardDrive,
    Loader2,
    Server,
    ShieldCheck,
    Terminal,
    Trash2,
    TriangleAlert,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface BerkasCadangan {
    nama: string;
    ukuran: number;
    dibuat_at: string;
}

const props = defineProps<{
    berkas: BerkasCadangan[];
    total: { jumlah: number; ukuran: number };
    database: { nama: string; host: string; driver: string; didukung: boolean };
    versiPgDump: string | null;
    ruangSisa: number | null;
    simpanTerakhir: number;
}>();

const { formatAngka } = useFormatAngka();

/*
 * Ukuran berkas dibulatkan ke satuan yang muat dibaca sekilas.
 *
 * Bukan angka byte mentah. "18.874.368" menuntut pembacanya menghitung sendiri
 * ada berapa digit sebelum tahu itu belasan mega atau belasan giga, dan kolom
 * ini justru yang dipakai membandingkan satu baris dengan baris di atasnya.
 */
const SATUAN = ['B', 'KB', 'MB', 'GB', 'TB'];

function formatUkuran(bita: number | null): string {
    if (bita === null) {
        return '-';
    }

    if (bita < 1024) {
        return `${bita} B`;
    }

    let nilai = bita;
    let tingkat = 0;

    while (nilai >= 1024 && tingkat < SATUAN.length - 1) {
        nilai /= 1024;
        tingkat += 1;
    }

    // Satu desimal cukup. Dua desimal pada satuan mega adalah presisi yang
    // tidak pernah dipakai siapa pun untuk mengambil keputusan.
    return `${nilai.toFixed(1).replace('.', ',')} ${SATUAN[tingkat]}`;
}

const waktuPanjang = (nilai: string) => format(new Date(nilai), 'd MMMM yyyy, HH:mm', { locale: id });
const waktuPendek = (nilai: string) => format(new Date(nilai), 'd MMM yyyy, HH:mm', { locale: id });
const jarak = (nilai: string) => formatDistanceToNow(new Date(nilai), { locale: id, addSuffix: true });

const terbaru = computed(() => props.berkas[0] ?? null);

/**
 * Cadangan terakhir yang lebih tua dari sehari dihitung basi.
 *
 * Angkanya bukan aturan tertulis dari Diskominfo, melainkan konsekuensi dari
 * ritme datanya sendiri: crawl berjalan tiap 3 jam, jadi cadangan berumur
 * lebih dari 24 jam sudah kehilangan minimal delapan putaran crawl.
 */
const JAM_BASI = 24;

const umurJam = computed(() => {
    if (!terbaru.value) {
        return null;
    }

    return (Date.now() - new Date(terbaru.value.dibuat_at).getTime()) / 3_600_000;
});

const basi = computed(() => umurJam.value !== null && umurJam.value > JAM_BASI);

/** Server siap kalau driver-nya PostgreSQL dan pg_dump-nya benar-benar ada. */
const siap = computed(() => props.database.didukung && props.versiPgDump !== null);

const ukuranTerbesar = computed(() => Math.max(1, ...props.berkas.map((b) => b.ukuran)));

/**
 * Slot penyimpanan, satu titik per berkas yang boleh disimpan.
 *
 * Menggambar batasnya lebih jujur daripada menuliskannya sebagai kalimat.
 * Admin yang melihat sembilan dari sepuluh titik terisi tahu bahwa penekanan
 * berikutnya akan membuang cadangan terlama, sebelum ia menekannya.
 */
const slot = computed(() =>
    Array.from({ length: props.simpanTerakhir }, (_, i) => {
        if (i < props.berkas.length - 1) {
            return 'terisi';
        }

        if (i === props.berkas.length - 1) {
            return props.berkas.length === props.simpanTerakhir ? 'akanDibuang' : 'terisi';
        }

        return 'kosong';
    }),
);

const sedangMembuat = ref(false);

function buatCadangan() {
    sedangMembuat.value = true;

    router.post(
        '/admin/cadangan',
        {},
        {
            preserveScroll: true,
            showProgress: false,
            onFinish: () => (sedangMembuat.value = false),
        },
    );
}

const akanDihapus = ref<BerkasCadangan | null>(null);

function hapus() {
    const berkas = akanDihapus.value;

    if (!berkas) {
        return;
    }

    akanDihapus.value = null;

    router.delete(`/admin/cadangan/${berkas.nama}`, { preserveScroll: true });
}

/**
 * Perintah pemulihan disusun dari nilai koneksi yang sedang berlaku.
 *
 * Menuliskannya sebagai contoh dengan nama basis data karangan berarti admin
 * harus menyuntingnya sendiri saat panik, dan itu momen paling buruk untuk
 * meminta orang mengingat nama host.
 */
const perintahPulih = computed(
    () => `gunzip -c ${terbaru.value?.nama ?? 'simedia-tanggal-jam.sql.gz'} | psql -h ${props.database.host} -U simedia -d ${props.database.nama}`,
);

const tersalin = ref(false);

async function salinPerintah() {
    try {
        await navigator.clipboard.writeText(perintahPulih.value);
        tersalin.value = true;
        setTimeout(() => (tersalin.value = false), 2000);
    } catch {
        // Clipboard ditolak browser, misalnya karena halaman bukan HTTPS.
        // Perintahnya tetap terlihat utuh di layar dan bisa diblok manual,
        // jadi tidak ada yang perlu dilaporkan sebagai galat.
        tersalin.value = false;
    }
}
</script>

<template>
    <Head title="Cadangan database" />

    <LayoutAdmin :breadcrumbs="[{ title: 'Cadangan database', href: '/admin/cadangan' }]">
        <KopHalaman
            judul="Cadangan database"
            keterangan="Salinan seluruh basis data dalam satu berkas SQL terkompresi. Dibuat manual, disimpan di server ini, dan hanya bisa diunduh dari halaman ini."
        >
            <PilKop :ikon="Archive">
                <span class="angka">{{ formatAngka(total.jumlah) }}</span> dari <span class="angka">{{ simpanTerakhir }}</span> slot
            </PilKop>
            <PilKop :ikon="HardDrive">
                Terpakai <span class="angka">{{ formatUkuran(total.ukuran) }}</span>
            </PilKop>
            <!-- Nada pil terakhir adalah jawaban atas satu-satunya pertanyaan
                 yang membuat orang membuka halaman ini saat sedang tidak ada
                 masalah: apakah datanya aman sekarang. -->
            <PilKop :nada="terbaru ? (basi ? 'tunggu' : 'baik') : 'buruk'" :ikon="terbaru ? (basi ? Clock : ShieldCheck) : TriangleAlert">
                <template v-if="terbaru">Terakhir {{ jarak(terbaru.dibuat_at) }}</template>
                <template v-else>Belum pernah dicadangkan</template>
            </PilKop>

            <template #bawah>
                <p class="max-w-[80ch] text-xs text-white/60">
                    Berkas cadangan berisi seluruh isi tabel, termasuk kredensial media partner dan laporan yang belum diverifikasi. Perlakukan
                    seperti kata sandi: unduh, pindahkan ke penyimpanan terpisah, jangan kirim lewat kanal terbuka.
                </p>
            </template>
        </KopHalaman>

        <!--
            Peringatan kesiapan, hanya muncul saat memang ada yang tidak siap.

            Ditaruh di atas kartu aksi, bukan di dalamnya. Yang perlu dibaca
            lebih dulu adalah alasan tombolnya mati, bukan tombolnya.
        -->
        <div
            v-if="!siap"
            class="muncul relative overflow-hidden rounded-xl bg-sentimen-negatif-lembut p-4 ring-1 ring-inset ring-sentimen-negatif/25"
            style="animation-delay: 60ms"
        >
            <!-- Garis rona di tepi atas, sama bentuk dengan garis kepala
                 KartuSeksi supaya blok ini terbaca sekeluarga dengan kartunya. -->
            <div
                class="tumbuh pointer-events-none absolute inset-x-0 top-0 h-px"
                style="background: linear-gradient(90deg, var(--color-sentimen-negatif), transparent)"
                aria-hidden="true"
            ></div>

            <div class="flex items-start gap-3">
                <span
                    class="grid size-8 shrink-0 place-items-center rounded-lg bg-sentimen-negatif/10 text-sentimen-negatif ring-1 ring-inset ring-sentimen-negatif/25"
                >
                    <TriangleAlert class="size-4" aria-hidden="true" />
                </span>

                <div class="min-w-0 space-y-1 text-sm">
                    <p class="font-medium text-sentimen-negatif">Cadangan tidak bisa dijalankan di server ini.</p>
                    <p v-if="!database.didukung" class="text-pretty leading-relaxed text-muted-foreground">
                        Koneksi aktif memakai driver
                        <code class="rounded bg-muted px-1 py-0.5 text-xs">{{ database.driver || 'tidak dikenal' }}</code>
                        , sedangkan halaman ini hanya mendukung PostgreSQL.
                    </p>
                    <p v-else class="text-pretty leading-relaxed text-muted-foreground">
                        Perintah <code class="rounded bg-muted px-1 py-0.5 text-xs">pg_dump</code> tidak ada di container aplikasi. Pasang paket
                        <code class="rounded bg-muted px-1 py-0.5 text-xs">postgresql-client-16</code> di
                        <code class="rounded bg-muted px-1 py-0.5 text-xs">docker/php/Dockerfile</code>, lalu bangun ulang image-nya dengan
                        <code class="rounded bg-muted px-1 py-0.5 text-xs">docker compose build app worker</code>.
                    </p>
                </div>
            </div>
        </div>

        <KartuSeksi
            class="muncul"
            style="animation-delay: 100ms"
            judul="Buat cadangan baru"
            catatan="Menyalin seluruh basis data ke satu berkas, lalu memampatkannya. Berjalan langsung di permintaan ini, jadi tunggu sampai halaman menjawab."
            rona="brand"
            :ikon="DatabaseBackup"
            :bekerja="sedangMembuat"
        >
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,20rem)] lg:items-center">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                    <!--
                        Ornamen tumpukan cakram.

                        Digambar, bukan ikon dari pustaka, karena yang perlu
                        disampaikan adalah "banyak lapis data menjadi satu
                        berkas", dan tidak ada satu glif pun yang mengatakan
                        itu. Sapuan cahaya di dalamnya hanya berjalan saat
                        pekerjaannya berjalan, mengikuti aturan yang sama
                        dengan `.aliran`: gerakan pada mesin yang diam
                        berbohong lebih keras daripada teks apa pun.
                    -->
                    <div class="relative mx-auto size-28 shrink-0 sm:mx-0">
                        <svg viewBox="0 0 120 120" fill="none" class="size-full text-brand dark:text-white" aria-hidden="true">
                            <defs>
                                <linearGradient id="cadangan-sapuan" x1="0" y1="0" x2="1" y2="0">
                                    <stop offset="0%" stop-color="currentColor" stop-opacity="0" />
                                    <stop offset="50%" stop-color="currentColor" stop-opacity="0.85" />
                                    <stop offset="100%" stop-color="currentColor" stop-opacity="0" />
                                </linearGradient>
                            </defs>

                            <!-- Tiga cakram bertumpuk, makin ke bawah makin pudar. -->
                            <g stroke="currentColor" stroke-width="1.5">
                                <ellipse cx="60" cy="84" rx="34" ry="12" opacity="0.25" />
                                <path d="M26 84v-16" opacity="0.25" />
                                <path d="M94 84v-16" opacity="0.25" />

                                <ellipse cx="60" cy="68" rx="34" ry="12" opacity="0.45" />
                                <path d="M26 68v-16" opacity="0.45" />
                                <path d="M94 68v-16" opacity="0.45" />

                                <ellipse cx="60" cy="52" rx="34" ry="12" opacity="0.9" />
                            </g>

                            <!-- Anak panah turun: arah pekerjaannya, dari basis
                                 data ke berkas. Ikut berdenyut saat bekerja. -->
                            <g
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                :class="sedangMembuat ? 'denyut motion-reduce:animate-none' : ''"
                            >
                                <path d="M60 30v22" />
                                <path d="M52 44l8 8 8-8" />
                            </g>

                            <!-- Cahaya yang menyusuri cakram teratas. Hanya
                                 dirender saat bekerja, jadi ia tidak pernah
                                 jadi hiasan yang berputar tanpa arti. -->
                            <ellipse
                                v-if="sedangMembuat"
                                cx="60"
                                cy="52"
                                rx="34"
                                ry="12"
                                stroke="url(#cadangan-sapuan)"
                                stroke-width="2.5"
                                class="sapuan motion-reduce:animate-none"
                            />
                        </svg>
                    </div>

                    <div class="min-w-0 space-y-3">
                        <!--
                            Tombol aksi utama satu-satunya di halaman ini, dan
                            karena itu ia yang mendapat bidang navy penuh.
                            Kop sengaja tidak ikut memuat tombol yang sama:
                            satu maksud, satu tombol.
                        -->
                        <button
                            type="button"
                            class="tekan group inline-flex items-center gap-2.5 rounded-lg bg-brand py-2.5 pl-4 pr-2.5 text-sm font-medium text-white shadow-lg shadow-brand/25 transition-colors hover:bg-brand-terang focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:shadow-none"
                            :disabled="!siap || sedangMembuat"
                            @click="buatCadangan"
                        >
                            {{ sedangMembuat ? 'Sedang menyalin' : 'Buat cadangan' }}

                            <!-- Ikon punya bidangnya sendiri, tidak menempel
                                 telanjang di sebelah teks. Bidang itu yang
                                 bergerak saat tombol disorot, sehingga
                                 tombolnya terasa punya bagian dalam. -->
                            <span
                                class="[transition-timing-function:cubic-bezier(0.32,0.72,0,1)] grid size-7 place-items-center rounded-md bg-white/15 transition-transform duration-300 group-hover:translate-y-0.5 motion-reduce:transition-none motion-reduce:group-hover:translate-y-0"
                            >
                                <Loader2 v-if="sedangMembuat" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                                <DatabaseBackup v-else class="size-4" aria-hidden="true" />
                            </span>
                        </button>

                        <p class="max-w-[46ch] text-pretty text-xs leading-relaxed text-muted-foreground">
                            Basis data
                            <span class="angka font-medium text-foreground">{{ database.nama || 'tidak diketahui' }}</span>
                            di host
                            <span class="angka font-medium text-foreground">{{ database.host || 'tidak diketahui' }}</span>
                            <template v-if="sedangMembuat">. Jangan tutup atau muat ulang halaman sampai selesai.</template>
                        </p>
                    </div>
                </div>

                <!-- Meter slot. Batas simpan digambar, bukan dikalimatkan. -->
                <div class="space-y-3 rounded-lg bg-muted/40 p-4">
                    <div class="flex items-baseline justify-between gap-2">
                        <p class="text-xs font-medium">Slot penyimpanan</p>
                        <p class="angka text-xs text-muted-foreground">{{ total.jumlah }} / {{ simpanTerakhir }}</p>
                    </div>

                    <div class="flex flex-wrap gap-1.5" role="img" :aria-label="`${total.jumlah} dari ${simpanTerakhir} slot cadangan terisi`">
                        <span
                            v-for="(keadaan, i) in slot"
                            :key="i"
                            class="h-2 flex-1 rounded-full transition-colors duration-500"
                            :class="{
                                'bg-brand dark:bg-brand-terang': keadaan === 'terisi',
                                'bg-sentimen-review': keadaan === 'akanDibuang',
                                'bg-border': keadaan === 'kosong',
                            }"
                        ></span>
                    </div>

                    <p class="text-pretty text-[11px] leading-relaxed text-muted-foreground">
                        <template v-if="total.jumlah >= simpanTerakhir">
                            Slot penuh. Cadangan berikutnya akan membuang yang terlama, ditandai kuning di atas.
                        </template>
                        <template v-else>
                            Sistem menyimpan {{ simpanTerakhir }} cadangan terakhir. Yang terlama dibuang otomatis saat slot penuh.
                        </template>
                    </p>
                </div>
            </div>
        </KartuSeksi>

        <KartuSeksi
            class="muncul"
            style="animation-delay: 140ms"
            judul="Arsip cadangan"
            catatan="Terbaru di atas. Panjang batang di tiap baris menunjukkan ukurannya relatif terhadap cadangan terbesar yang ada."
            rona="netral"
            :ikon="Archive"
            padat
        >
            <template #aksi>
                <span
                    v-if="berkas.length > 0"
                    class="hidden rounded-full bg-muted px-2.5 py-1 text-[11px] font-medium text-muted-foreground sm:inline-flex"
                >
                    <span class="angka">{{ formatUkuran(total.ukuran) }}</span>
                    <span class="pl-1">total</span>
                </span>
            </template>

            <!--
                Keadaan kosong. Ornamennya digambar dengan bahasa bentuk yang
                sama dengan ornamen kartu aksi di atasnya, hanya tanpa isi:
                rak yang sama, cakramnya belum ada.
            -->
            <div v-if="berkas.length === 0" class="flex flex-col items-center gap-4 px-6 py-14 text-center">
                <svg viewBox="0 0 120 90" fill="none" class="h-20 w-28 text-muted-foreground/40" aria-hidden="true">
                    <g stroke="currentColor" stroke-width="1.5" stroke-dasharray="4 4">
                        <ellipse cx="60" cy="66" rx="34" ry="12" />
                        <path d="M26 66v-16" />
                        <path d="M94 66v-16" />
                        <ellipse cx="60" cy="50" rx="34" ry="12" />
                    </g>
                    <g stroke="currentColor" stroke-width="1.5" stroke-linecap="round" opacity="0.6">
                        <path d="M60 34V18" />
                        <path d="M52 26l8-8 8 8" />
                    </g>
                </svg>

                <div class="space-y-1">
                    <p class="text-sm font-medium">Belum ada cadangan</p>
                    <p class="mx-auto max-w-[52ch] text-pretty text-xs leading-relaxed text-muted-foreground">
                        Tekan "Buat cadangan" di atas untuk membuat yang pertama. Berkasnya muncul di sini begitu selesai, dan bisa langsung diunduh.
                    </p>
                </div>
            </div>

            <ul v-else class="divide-y">
                <li
                    v-for="(b, i) in berkas"
                    :key="b.nama"
                    class="muncul relative py-4 pl-11 pr-4 transition-colors hover:bg-muted/30"
                    :class="i < berkas.length - 1 ? 'rel-arsip' : ''"
                    :style="{ animationDelay: `${180 + i * 40}ms` }"
                >
                    <!-- Titik rel. Yang teratas diberi cincin karena ia satu-
                         satunya baris yang menjawab "apakah datanya aman
                         sekarang"; sisanya adalah riwayat. -->
                    <span
                        class="absolute left-4 top-[1.35rem] size-2.5 rounded-full ring-4"
                        :class="i === 0 ? 'bg-brand ring-brand/15 dark:bg-brand-terang dark:ring-brand-terang/20' : 'bg-border ring-transparent'"
                        aria-hidden="true"
                    ></span>

                    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <p class="angka truncate text-sm font-medium">{{ b.nama }}</p>

                            <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                                <span class="angka">{{ waktuPendek(b.dibuat_at) }}</span>
                                <span class="text-muted-foreground/40" aria-hidden="true">/</span>
                                <span class="angka">{{ formatUkuran(b.ukuran) }}</span>
                                <span
                                    v-if="i === 0"
                                    class="rounded-full bg-brand-lembut px-2 py-0.5 text-[10px] font-medium text-brand dark:text-white"
                                >
                                    Terbaru
                                </span>
                            </p>

                            <!-- Batang ukuran. Penyebutnya adalah cadangan
                                 terbesar yang benar-benar ada, bukan angka
                                 tebakan, jadi batang penuh selalu berarti
                                 sesuatu yang bisa ditunjuk di daftar ini. -->
                            <div class="h-1 max-w-[18rem] overflow-hidden rounded-full bg-muted">
                                <div
                                    class="tumbuh h-full rounded-full"
                                    :class="i === 0 ? 'bg-brand dark:bg-brand-terang' : 'bg-muted-foreground/30'"
                                    :style="{ width: `${(b.ukuran / ukuranTerbesar) * 100}%`, animationDelay: `${260 + i * 40}ms` }"
                                ></div>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <!-- Unduh memakai rona biru: aksi yang hanya
                                 membaca. Warna destruktif disimpan untuk satu
                                 tombol saja di baris ini, supaya merah tetap
                                 berarti "tidak bisa dibatalkan". -->
                            <a
                                :href="`/admin/cadangan/${b.nama}/unduh`"
                                class="tekan inline-flex items-center gap-1.5 rounded-lg bg-aksen-biru/10 px-3 py-1.5 text-xs font-medium text-aksen-biru ring-1 ring-inset ring-aksen-biru/20 transition-colors hover:bg-aksen-biru/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-aksen-biru"
                            >
                                <Download class="size-3.5" aria-hidden="true" />
                                Unduh
                            </a>

                            <button
                                type="button"
                                class="tekan inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-muted-foreground ring-1 ring-inset ring-border transition-colors hover:bg-sentimen-negatif-lembut hover:text-sentimen-negatif hover:ring-sentimen-negatif/25 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sentimen-negatif"
                                :aria-label="`Hapus cadangan ${b.nama}`"
                                @click="akanDihapus = b"
                            >
                                <Trash2 class="size-3.5" aria-hidden="true" />
                                Hapus
                            </button>
                        </div>
                    </div>
                </li>
            </ul>
        </KartuSeksi>

        <div class="grid gap-4 lg:grid-cols-2">
            <KartuSeksi
                class="muncul"
                style="animation-delay: 180ms"
                judul="Kesiapan server"
                catatan="Tiga syarat yang harus terpenuhi supaya tombol di atas bekerja. Yang merah menjelaskan sendiri apa yang harus dipasang."
                rona="biru"
                :ikon="Server"
            >
                <dl class="space-y-3 text-sm">
                    <div class="flex items-start gap-3">
                        <span
                            class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-md"
                            :class="
                                database.didukung
                                    ? 'bg-sentimen-positif-lembut text-sentimen-positif'
                                    : 'bg-sentimen-negatif-lembut text-sentimen-negatif'
                            "
                        >
                            <Database class="size-3.5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <dt class="font-medium">Driver database</dt>
                            <dd class="angka text-xs text-muted-foreground">{{ database.driver || 'tidak dikenal' }}</dd>
                        </div>
                    </div>

                    <div class="flex items-start gap-3 border-t pt-3">
                        <span
                            class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-md"
                            :class="
                                versiPgDump ? 'bg-sentimen-positif-lembut text-sentimen-positif' : 'bg-sentimen-negatif-lembut text-sentimen-negatif'
                            "
                        >
                            <Terminal class="size-3.5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <dt class="font-medium">Perintah pg_dump</dt>
                            <dd class="angka break-words text-xs text-muted-foreground">{{ versiPgDump ?? 'tidak terpasang' }}</dd>
                        </div>
                    </div>

                    <div class="flex items-start gap-3 border-t pt-3">
                        <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-md bg-muted text-muted-foreground">
                            <HardDrive class="size-3.5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <dt class="font-medium">Ruang disk tersisa</dt>
                            <dd class="angka text-xs text-muted-foreground">{{ formatUkuran(ruangSisa) }}</dd>
                        </div>
                    </div>
                </dl>
            </KartuSeksi>

            <KartuSeksi
                class="muncul"
                style="animation-delay: 220ms"
                judul="Cara memulihkan"
                catatan="Dijalankan dari terminal server, bukan dari halaman ini. Memulihkan berarti menimpa seluruh isi basis data yang sedang berjalan."
                rona="toska"
                :ikon="Terminal"
            >
                <div class="space-y-3">
                    <div class="relative overflow-hidden rounded-lg bg-brand p-3 pr-12 ring-1 ring-inset ring-brand-terang/40">
                        <!-- Kabut sudut, sama bentuk dengan ornamen kop, supaya
                             blok perintah terbaca sebagai permukaan sistem dan
                             bukan sebagai kartu kelima. -->
                        <div
                            class="pointer-events-none absolute inset-0"
                            aria-hidden="true"
                            style="background: radial-gradient(20rem 8rem at 96% -40%, rgb(255 255 255 / 0.14), transparent 70%)"
                        ></div>

                        <code class="angka relative block overflow-x-auto whitespace-pre text-xs leading-relaxed text-white/90">{{
                            perintahPulih
                        }}</code>

                        <button
                            type="button"
                            class="tekan absolute right-2 top-2 grid size-8 place-items-center rounded-md bg-white/10 text-white ring-1 ring-inset ring-white/20 transition-colors hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
                            :aria-label="tersalin ? 'Perintah tersalin' : 'Salin perintah'"
                            @click="salinPerintah"
                        >
                            <Check v-if="tersalin" class="size-4" aria-hidden="true" />
                            <Copy v-else class="size-4" aria-hidden="true" />
                        </button>
                    </div>

                    <ol class="space-y-2 text-xs leading-relaxed text-muted-foreground">
                        <li class="flex gap-2">
                            <span class="angka shrink-0 font-medium text-foreground">1.</span>
                            <span>Hentikan worker antrean dan penjadwal, supaya tidak ada yang menulis saat pemulihan berjalan.</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="angka shrink-0 font-medium text-foreground">2.</span>
                            <span>Salin berkas cadangan ke server, lalu jalankan perintah di atas dari folder berkas itu.</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="angka shrink-0 font-medium text-foreground">3.</span>
                            <span>
                                Jalankan <code class="rounded bg-muted px-1 py-0.5">php artisan migrate</code> untuk memastikan skemanya sesuai kode
                                yang sedang berjalan, lalu hidupkan kembali worker.
                            </span>
                        </li>
                    </ol>

                    <p
                        class="flex items-start gap-2 rounded-lg bg-sentimen-review-lembut p-2.5 text-xs leading-relaxed text-sentimen-review ring-1 ring-inset ring-sentimen-review/25"
                    >
                        <TriangleAlert class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                        <span>
                            Perintahnya memuat <code class="rounded bg-black/5 px-1 dark:bg-white/10">DROP</code> untuk tiap tabel. Data yang masuk
                            setelah cadangan dibuat akan hilang dan tidak bisa dikembalikan.
                        </span>
                    </p>
                </div>
            </KartuSeksi>
        </div>

        <Dialog :open="akanDihapus !== null" @update:open="(nilai) => !nilai && (akanDihapus = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Hapus cadangan ini?</DialogTitle>
                    <DialogDescription>
                        Berkas <span class="angka font-medium">{{ akanDihapus?.nama }}</span> berukuran {{ formatUkuran(akanDihapus?.ukuran ?? 0) }},
                        dibuat {{ akanDihapus ? waktuPanjang(akanDihapus.dibuat_at) : '' }}. Penghapusannya permanen. Kalau berkasnya belum pernah
                        diunduh, salinan itu tidak ada di tempat lain mana pun.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <button
                        type="button"
                        class="tekan inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-medium ring-1 ring-inset ring-border transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        @click="akanDihapus = null"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        class="tekan inline-flex items-center justify-center gap-2 rounded-lg bg-sentimen-negatif px-4 py-2 text-sm font-medium text-white transition-colors hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sentimen-negatif focus-visible:ring-offset-2 dark:text-background"
                        @click="hapus"
                    >
                        <Trash2 class="size-4" aria-hidden="true" />
                        Hapus permanen
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </LayoutAdmin>
</template>

<style scoped>
/**
 * Rel yang menyambungkan titik satu baris arsip ke baris berikutnya.
 *
 * Bentuknya sengaja sama persis dengan rel daftar kunci di halaman Pengaturan,
 * karena keduanya menjawab pertanyaan yang sama: apakah daftar ini satu
 * rangkaian berurutan, atau sekadar kotak yang kebetulan bertumpuk. Posisi
 * kirinya harus tepat di pusat titiknya. Titik berdiameter 0,625rem dipasang
 * pada left 1rem, jadi pusatnya 1,3125rem.
 *
 * Digambar sebagai pseudo elemen, bukan div, supaya daftar yang dibacakan
 * pembaca layar tidak berisi simpul kosong.
 */
.rel-arsip::before {
    content: '';
    position: absolute;
    left: 1.3125rem;
    top: 2.25rem;
    bottom: 0.25rem;
    width: 1px;
    background: linear-gradient(180deg, hsl(var(--border)) 0%, hsl(var(--border) / 0.2) 100%);
    transform-origin: top;
    animation: rel-arsip-turun 700ms cubic-bezier(0.32, 0.72, 0, 1) both;
    animation-delay: 340ms;
}

@keyframes rel-arsip-turun {
    from {
        transform: scaleY(0);
    }

    to {
        transform: scaleY(1);
    }
}

/*
 * Sapuan cahaya yang mengitari cakram teratas selama penyalinan berjalan.
 *
 * Digambar sebagai garis putus yang bergeser, bukan sebagai putaran, supaya
 * gerakannya mengikuti bentuk elipsnya sendiri. Durasinya panjang dan
 * kepekatannya rendah, mengikuti alasan yang sama dengan `.aliran`: ini
 * gerakan yang berjalan di layar yang sedang ditunggu orang, dan kilatan cepat
 * di sana terasa seperti galat.
 */
.sapuan {
    stroke-dasharray: 30 120;
    animation: sapuan-jalan 1.6s cubic-bezier(0.45, 0, 0.55, 1) infinite;
}

@keyframes sapuan-jalan {
    from {
        stroke-dashoffset: 150;
    }

    to {
        stroke-dashoffset: 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .rel-arsip::before,
    .sapuan {
        animation: none;
    }
}
</style>
