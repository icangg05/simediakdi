<script setup lang="ts">
import KopHalaman from '@/components/domain/KopHalaman.vue';
import { Button } from '@/components/ui/button';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { CalendarDays, CircleCheck, CircleX, Clock3, FileText, LoaderCircle, Lock, RefreshCw, Sparkles } from 'lucide-vue-next';
import { computed, ref, type Component } from 'vue';

type Status = 'belum_dianalisis' | 'menunggu' | 'berjalan' | 'selesai' | 'gagal' | 'tanpa_data';

interface Bulan {
    bulan: string;
    status: Status;
    dikunci: boolean;
    bulan_berjalan: boolean;
    dijadwalkan_ulang: boolean;
    dapat_dianalisis_manual: boolean;
    jumlah_bahan: number;
    pemeriksaan: number;
    galat: string | null;
    mulai_at: string | null;
    selesai_at: string | null;
    gagal_at: string | null;
    judul: string | null;
    jumlah_artikel: number | null;
    model: string | null;
    hasil_dibuat_at: string | null;
}

const props = defineProps<{
    ringkasan: { total: number; berjalan: number; gagal: number; final: number };
    bulan: Bulan[];
    bolehAnalisisManual: boolean;
    diperbarui: string;
}>();

const bulanDiproses = ref<string | null>(null);

/**
 * Status proses dapat berubah ketika perintah penjadwal sedang berjalan.
 * Sepuluh detik cukup cepat untuk pemantauan proses yang biasanya memakan
 * puluhan detik, tanpa menarik seluruh halaman dua belas kali semenit seperti
 * antrean klasifikasi per artikel.
 */
usePoll(10000, { only: ['ringkasan', 'bulan', 'diperbarui'] }, { autoStart: true, keepAlive: true });

const STATUS: Record<Status, { label: string; keterangan: string; ikon: Component; kelas: string }> = {
    belum_dianalisis: {
        label: 'Belum dianalisis',
        keterangan: 'Belum ada hasil analisis untuk bulan ini.',
        ikon: Clock3,
        kelas: 'bg-muted text-muted-foreground ring-border',
    },
    menunggu: {
        label: 'Menunggu jadwal',
        keterangan: 'Analisis akan diperiksa pada jadwal berikutnya.',
        ikon: Clock3,
        kelas: 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/45 dark:text-amber-200 dark:ring-amber-800',
    },
    berjalan: {
        label: 'Sedang dianalisis',
        keterangan: 'Gemini sedang menulis ringkasan bulan ini.',
        ikon: RefreshCw,
        kelas: 'bg-violet-50 text-violet-800 ring-violet-200 dark:bg-violet-950/45 dark:text-violet-200 dark:ring-violet-800',
    },
    selesai: {
        label: 'Berhasil',
        keterangan: 'Hasil analisis terakhir tersedia.',
        ikon: CircleCheck,
        kelas: 'bg-brand-lembut text-brand ring-brand/20 dark:text-white dark:ring-white/20',
    },
    gagal: {
        label: 'Gagal',
        keterangan: 'Proses terakhir tidak berhasil diselesaikan.',
        ikon: CircleX,
        kelas: 'bg-red-50 text-red-800 ring-red-200 dark:bg-red-950/45 dark:text-red-200 dark:ring-red-800',
    },
    tanpa_data: {
        label: 'Belum ada data',
        keterangan: 'Tidak ada pemberitaan yang dapat dianalisis pada bulan ini.',
        ikon: FileText,
        kelas: 'bg-muted text-muted-foreground ring-border',
    },
};

const bulanBerjalan = computed(() => props.bulan.find((baris) => baris.bulan_berjalan) ?? null);
const pengumumanStatus = computed(() =>
    bulanBerjalan.value
        ? `Analisis ${namaBulan(bulanBerjalan.value.bulan)}: ${STATUS[bulanBerjalan.value.status].label}.`
        : 'Bulan berjalan belum tersedia untuk dipantau.',
);

function namaBulan(nilai: string): string {
    const [tahun, bulan] = nilai.split('-').map(Number);

    return new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric', timeZone: 'Asia/Makassar' }).format(
        new Date(Date.UTC(tahun, bulan - 1, 1)),
    );
}

function waktu(nilai: string | null): string {
    if (!nilai) return 'Belum tercatat';

    return new Intl.DateTimeFormat('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        timeZone: 'Asia/Makassar',
    })
        .format(new Date(nilai))
        .replace(' pukul ', ', ')
        .concat(' WITA');
}

function waktuProses(baris: Bulan): string | null {
    return baris.gagal_at ?? baris.selesai_at ?? baris.mulai_at;
}

function laporan(baris: Bulan): string {
    return `/eksekutif/laporan?bulan=${baris.bulan}`;
}

function analisisManual(baris: Bulan): void {
    if (!props.bolehAnalisisManual || !baris.dapat_dianalisis_manual || bulanDiproses.value !== null) return;

    bulanDiproses.value = baris.bulan;
    router.post(
        `/admin/analisis-bulanan/${baris.bulan}/jalankan`,
        {},
        {
            preserveScroll: true,
            showProgress: false,
            onFinish: () => {
                bulanDiproses.value = null;
            },
        },
    );
}

/** Bulan nonfinal tetap menampilkan kontrolnya saat menunggu agar tidak terasa hilang. */
function tampilkanAnalisisManual(baris: Bulan): boolean {
    return props.bolehAnalisisManual && baris.jumlah_bahan > 0 && !baris.dikunci;
}

function analisisSedangDiproses(baris: Bulan): boolean {
    return bulanDiproses.value === baris.bulan || baris.status === 'menunggu' || baris.status === 'berjalan';
}

function labelAnalisis(baris: Bulan): string {
    if (bulanDiproses.value === baris.bulan) return 'Menjadwalkan…';
    if (baris.status === 'menunggu') return 'Dalam antrean';
    if (baris.status === 'berjalan') return 'Sedang dianalisis';

    return baris.judul ? 'Analisis ulang' : 'Analisis sekarang';
}

function sifatHasil(baris: Bulan): string {
    if (baris.dikunci) return 'Final';

    return baris.bulan_berjalan ? 'Sementara' : 'Belum final';
}

function rincianHasil(baris: Bulan): string {
    const rincian: string[] = [];

    if (baris.jumlah_artikel !== null) {
        rincian.push(`${new Intl.NumberFormat('id-ID').format(baris.jumlah_artikel)} berita`);
    }

    if (baris.model) rincian.push(`Model AI ${baris.model}`);

    return rincian.join(' · ');
}

function keteranganPercobaanUlang(baris: Bulan): string {
    if (props.bolehAnalisisManual && baris.dapat_dianalisis_manual) {
        return 'Setelah worker antrean dipastikan aktif, pilih Analisis sekarang untuk mencoba kembali.';
    }

    return baris.dijadwalkan_ulang
        ? 'Sistem akan mencoba lagi otomatis pada jadwal harian pukul 04.10 WITA.'
        : 'Bulan ini tidak lagi masuk jadwal otomatis. Hubungi pengelola sistem untuk menjalankan ulang analisisnya.';
}
</script>

<template>
    <Head title="Analisis bulanan" />

    <LayoutAdmin>
        <div class="space-y-6">
            <KopHalaman
                judul="Analisis laporan bulanan"
                keterangan="Pantau hasil Gemini, proses yang sedang berjalan, dan pesan kegagalan setiap bulan."
            >
                <span class="inline-flex items-center gap-1.5 rounded-full border border-white/25 bg-white/10 px-2.5 py-1 text-xs text-white/85">
                    <RefreshCw class="size-3.5" aria-hidden="true" />
                    Diperbarui otomatis · {{ waktu(diperbarui) }}
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-full border border-white/25 bg-white/10 px-2.5 py-1 text-xs text-white/85">
                    <Clock3 class="size-3.5" aria-hidden="true" />
                    Jadwal harian 04.10 WITA
                </span>
            </KopHalaman>

            <p class="sr-only" aria-live="polite" aria-atomic="true">{{ pengumumanStatus }}</p>

            <section class="grid gap-4 lg:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.65fr)]" aria-labelledby="bulan-berjalan">
                <div class="rounded-xl bg-card p-5 ring-1 ring-border sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0 space-y-1">
                            <h2 id="bulan-berjalan" class="text-lg font-semibold tracking-tight">Bulan berjalan</h2>
                            <p class="text-sm text-muted-foreground">Hasilnya sementara dan mengikuti perubahan pemberitaan selama bulan ini.</p>
                        </div>

                        <span
                            v-if="bulanBerjalan"
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset"
                            :class="STATUS[bulanBerjalan.status].kelas"
                        >
                            <component
                                :is="STATUS[bulanBerjalan.status].ikon"
                                class="size-3.5"
                                :class="{ 'animate-spin motion-reduce:animate-none': bulanBerjalan.status === 'berjalan' }"
                                aria-hidden="true"
                            />
                            {{ STATUS[bulanBerjalan.status].label }}
                        </span>
                    </div>

                    <div v-if="bulanBerjalan" class="mt-6 grid gap-5 sm:grid-cols-[minmax(10rem,0.55fr)_minmax(0,1.45fr)]">
                        <div>
                            <p class="text-2xl font-semibold tracking-tight capitalize">{{ namaBulan(bulanBerjalan.bulan) }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">Proses dijalankan {{ bulanBerjalan.pemeriksaan }} kali</p>
                        </div>
                        <div class="min-w-0 border-t border-border pt-4 sm:border-t-0 sm:border-l sm:pt-0 sm:pl-5">
                            <p class="text-sm font-medium">{{ STATUS[bulanBerjalan.status].keterangan }}</p>
                            <p v-if="bulanBerjalan.judul" class="mt-1 line-clamp-2 text-sm text-muted-foreground">
                                <span v-if="bulanBerjalan.status === 'gagal'" class="font-medium text-foreground"
                                    >Hasil terakhir yang berhasil:
                                </span>
                                {{ bulanBerjalan.judul }}
                            </p>
                            <p v-else class="mt-1 text-sm text-muted-foreground">
                                Gemini hanya dipanggil jika ada berita baru atau perubahan sentimen.
                            </p>
                            <p v-if="bulanBerjalan.judul && rincianHasil(bulanBerjalan)" class="mt-2 text-xs text-muted-foreground">
                                {{ rincianHasil(bulanBerjalan) }} · dibuat {{ waktu(bulanBerjalan.hasil_dibuat_at) }}
                            </p>
                            <p v-if="bulanBerjalan.status === 'gagal'" class="mt-2 text-xs leading-relaxed text-red-700 dark:text-red-300">
                                {{ keteranganPercobaanUlang(bulanBerjalan) }}
                            </p>
                            <div v-if="bulanBerjalan.judul || tampilkanAnalisisManual(bulanBerjalan)" class="mt-4 flex flex-wrap items-center gap-3">
                                <Link
                                    v-if="bulanBerjalan.judul"
                                    :href="laporan(bulanBerjalan)"
                                    class="inline-flex items-center gap-1.5 text-sm font-medium text-brand underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden dark:text-white"
                                >
                                    {{ bulanBerjalan.status === 'gagal' ? 'Lihat hasil terakhir yang berhasil' : 'Buka laporan bulan ini' }}
                                    <FileText class="size-4" aria-hidden="true" />
                                </Link>
                                <Button
                                    v-if="tampilkanAnalisisManual(bulanBerjalan)"
                                    size="sm"
                                    :disabled="bulanDiproses !== null || !bulanBerjalan.dapat_dianalisis_manual"
                                    @click="analisisManual(bulanBerjalan)"
                                >
                                    <LoaderCircle
                                        v-if="analisisSedangDiproses(bulanBerjalan)"
                                        class="size-4 animate-spin motion-reduce:animate-none"
                                        aria-hidden="true"
                                    />
                                    <Sparkles v-else class="size-4" aria-hidden="true" />
                                    {{ labelAnalisis(bulanBerjalan) }}
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div v-else class="mt-6 rounded-lg bg-muted/55 p-4 text-sm text-muted-foreground ring-1 ring-border">
                        Belum ada bulan berjalan yang dapat dipantau. Riwayat akan muncul setelah data pemberitaan pertama tersedia.
                    </div>
                </div>

                <aside class="rounded-xl bg-muted/45 p-5 ring-1 ring-border sm:p-6" aria-label="Aturan pembaruan analisis">
                    <div class="flex items-start gap-3">
                        <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-brand-lembut text-brand dark:text-white">
                            <Sparkles class="size-4.5" aria-hidden="true" />
                        </span>
                        <div class="space-y-3">
                            <div>
                                <h2 class="font-semibold">Kapan hasil berubah?</h2>
                                <p class="mt-1 text-sm leading-relaxed text-muted-foreground">
                                    Selama bulan berjalan, sistem memeriksa data setiap hari. Tanpa perubahan bahan, Gemini tidak dipanggil lagi.
                                </p>
                            </div>
                            <div class="flex items-start gap-2 border-t border-border pt-3 text-sm">
                                <Lock class="mt-0.5 size-4 shrink-0 text-brand dark:text-white" aria-hidden="true" />
                                <p>Setelah bulan berganti, satu hasil final disimpan dan dikunci agar tidak berubah.</p>
                            </div>
                        </div>
                    </div>
                </aside>
            </section>

            <section class="overflow-hidden rounded-xl bg-card ring-1 ring-border" aria-labelledby="riwayat-analisis">
                <div class="grid grid-cols-2 divide-x divide-y divide-border border-b border-border sm:grid-cols-4 sm:divide-y-0">
                    <div class="p-4 sm:p-5">
                        <p class="text-2xl font-semibold tabular-nums">{{ ringkasan.total }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">Bulan dipantau</p>
                    </div>
                    <div class="p-4 sm:p-5">
                        <p class="text-2xl font-semibold text-brand tabular-nums dark:text-white">{{ ringkasan.final }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">Hasil final</p>
                    </div>
                    <div class="p-4 sm:p-5">
                        <p class="text-2xl font-semibold tabular-nums">{{ ringkasan.berjalan }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">Sedang berjalan</p>
                    </div>
                    <div class="p-4 sm:p-5">
                        <p class="text-2xl font-semibold tabular-nums" :class="ringkasan.gagal > 0 ? 'text-red-700 dark:text-red-300' : ''">
                            {{ ringkasan.gagal }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">Perlu diperiksa</p>
                    </div>
                </div>

                <div class="flex flex-wrap items-end justify-between gap-3 px-4 py-5 sm:px-6">
                    <div>
                        <h2 id="riwayat-analisis" class="text-lg font-semibold tracking-tight">Riwayat per bulan</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Pesan kegagalan ditampilkan utuh agar penyebabnya dapat langsung ditelusuri.</p>
                    </div>
                    <p class="text-xs text-muted-foreground">Terbaru lebih dahulu</p>
                </div>

                <div v-if="bulan.length === 0" class="border-t border-border px-6 py-14 text-center">
                    <CalendarDays class="mx-auto size-8 text-muted-foreground" aria-hidden="true" />
                    <p class="mt-3 font-medium">Belum ada bulan yang dapat dipantau</p>
                    <p class="mt-1 text-sm text-muted-foreground">Riwayat muncul setelah data pemberitaan pertama tersedia.</p>
                </div>

                <div v-else class="hidden overflow-x-auto border-t border-border md:block">
                    <table class="w-full min-w-[860px] text-left text-sm">
                        <thead class="bg-muted/55 text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-6 py-3 font-medium">Periode dan hasil</th>
                                <th scope="col" class="px-4 py-3 font-medium">Keadaan</th>
                                <th scope="col" class="px-4 py-3 font-medium">Proses terakhir</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Proses dijalankan</th>
                                <th scope="col" class="px-6 py-3 text-right font-medium"><span class="sr-only">Buka laporan</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <template v-for="baris in bulan" :key="baris.bulan">
                                <tr class="align-top">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-2">
                                            <p class="font-medium capitalize">{{ namaBulan(baris.bulan) }}</p>
                                            <span
                                                class="rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset"
                                                :class="
                                                    baris.dikunci
                                                        ? 'bg-brand-lembut text-brand ring-brand/20 dark:text-white dark:ring-white/20'
                                                        : 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/45 dark:text-amber-200 dark:ring-amber-800'
                                                "
                                            >
                                                {{ sifatHasil(baris) }}
                                            </span>
                                        </div>
                                        <p v-if="baris.judul" class="mt-1 max-w-[42rem] text-xs leading-relaxed text-muted-foreground">
                                            <span v-if="baris.status === 'gagal'" class="font-medium text-foreground"
                                                >Hasil terakhir yang berhasil:
                                            </span>
                                            {{ baris.judul }}
                                        </p>
                                        <p v-else class="mt-1 text-xs text-muted-foreground">Belum ada hasil ringkasan.</p>
                                        <p v-if="baris.judul && rincianHasil(baris)" class="mt-1 text-[11px] text-muted-foreground">
                                            {{ rincianHasil(baris) }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset"
                                            :class="STATUS[baris.status].kelas"
                                        >
                                            <component
                                                :is="STATUS[baris.status].ikon"
                                                class="size-3.5"
                                                :class="{ 'animate-spin motion-reduce:animate-none': baris.status === 'berjalan' }"
                                                aria-hidden="true"
                                            />
                                            {{ STATUS[baris.status].label }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-4">
                                        <p>{{ waktu(waktuProses(baris)) }}</p>
                                        <p v-if="baris.hasil_dibuat_at" class="mt-1 text-xs text-muted-foreground">
                                            Hasil dibuat {{ waktu(baris.hasil_dibuat_at) }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-4 text-right font-medium tabular-nums">{{ baris.pemeriksaan }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex flex-wrap items-center justify-end gap-2">
                                            <Link
                                                v-if="baris.judul"
                                                :href="laporan(baris)"
                                                class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-brand hover:bg-brand-lembut focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden dark:text-white dark:hover:bg-white/10"
                                            >
                                                {{ baris.status === 'gagal' ? 'Hasil sebelumnya' : 'Lihat laporan' }}
                                                <FileText class="size-3.5" aria-hidden="true" />
                                            </Link>
                                            <Button
                                                v-if="tampilkanAnalisisManual(baris)"
                                                size="sm"
                                                :disabled="bulanDiproses !== null || !baris.dapat_dianalisis_manual"
                                                @click="analisisManual(baris)"
                                            >
                                                <LoaderCircle
                                                    v-if="analisisSedangDiproses(baris)"
                                                    class="size-3.5 animate-spin motion-reduce:animate-none"
                                                    aria-hidden="true"
                                                />
                                                <Sparkles v-else class="size-3.5" aria-hidden="true" />
                                                {{ labelAnalisis(baris) }}
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="baris.galat" class="bg-red-50/70 dark:bg-red-950/20">
                                    <td colspan="5" class="px-6 py-3">
                                        <div class="flex items-start gap-2.5 text-sm text-red-800 dark:text-red-200">
                                            <CircleX class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                            <div>
                                                <p class="font-medium">Analisis terakhir gagal</p>
                                                <p class="mt-0.5 leading-relaxed break-words">{{ baris.galat }}</p>
                                                <p class="mt-1 font-medium">{{ keteranganPercobaanUlang(baris) }}</p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div v-if="bulan.length > 0" class="divide-y divide-border border-t border-border md:hidden">
                    <article v-for="baris in bulan" :key="baris.bulan" class="space-y-4 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-medium capitalize">{{ namaBulan(baris.bulan) }}</h3>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{
                                        baris.dikunci
                                            ? 'Hasil final dan terkunci'
                                            : baris.bulan_berjalan
                                              ? 'Hasil sementara'
                                              : 'Belum memiliki hasil final'
                                    }}
                                </p>
                            </div>
                            <span
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset"
                                :class="STATUS[baris.status].kelas"
                            >
                                <component
                                    :is="STATUS[baris.status].ikon"
                                    class="size-3.5"
                                    :class="{ 'animate-spin motion-reduce:animate-none': baris.status === 'berjalan' }"
                                    aria-hidden="true"
                                />
                                {{ STATUS[baris.status].label }}
                            </span>
                        </div>

                        <div v-if="baris.judul" class="space-y-1">
                            <p class="text-sm leading-relaxed">
                                <span v-if="baris.status === 'gagal'" class="font-medium">Hasil terakhir yang berhasil: </span>
                                {{ baris.judul }}
                            </p>
                            <p v-if="rincianHasil(baris)" class="text-xs text-muted-foreground">
                                {{ rincianHasil(baris) }} · dibuat {{ waktu(baris.hasil_dibuat_at) }}
                            </p>
                        </div>
                        <p v-else class="text-sm text-muted-foreground">{{ STATUS[baris.status].keterangan }}</p>

                        <div
                            v-if="baris.galat"
                            class="rounded-lg bg-red-50 p-3 text-sm text-red-800 ring-1 ring-red-200 dark:bg-red-950/35 dark:text-red-200 dark:ring-red-800"
                        >
                            <p class="font-medium">Analisis terakhir gagal</p>
                            <p class="mt-1 leading-relaxed break-words">{{ baris.galat }}</p>
                            <p class="mt-2 font-medium">{{ keteranganPercobaanUlang(baris) }}</p>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3 text-xs text-muted-foreground">
                            <span>{{ waktu(waktuProses(baris)) }} · Proses dijalankan {{ baris.pemeriksaan }} kali</span>
                            <div class="flex flex-wrap items-center gap-2">
                                <Link
                                    v-if="baris.judul"
                                    :href="laporan(baris)"
                                    class="inline-flex items-center gap-1.5 font-medium text-brand focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden dark:text-white"
                                >
                                    {{ baris.status === 'gagal' ? 'Hasil sebelumnya' : 'Lihat laporan' }}
                                    <FileText class="size-3.5" aria-hidden="true" />
                                </Link>
                                <Button
                                    v-if="tampilkanAnalisisManual(baris)"
                                    size="sm"
                                    :disabled="bulanDiproses !== null || !baris.dapat_dianalisis_manual"
                                    @click="analisisManual(baris)"
                                >
                                    <LoaderCircle
                                        v-if="analisisSedangDiproses(baris)"
                                        class="size-3.5 animate-spin motion-reduce:animate-none"
                                        aria-hidden="true"
                                    />
                                    <Sparkles v-else class="size-3.5" aria-hidden="true" />
                                    {{ labelAnalisis(baris) }}
                                </Button>
                            </div>
                        </div>
                    </article>
                </div>
            </section>
        </div>
    </LayoutAdmin>
</template>
