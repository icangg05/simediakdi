<script setup lang="ts">
import KopEksekutif from '@/components/domain/KopEksekutif.vue';
import PermukaanKendaliKop from '@/components/domain/PermukaanKendaliKop.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { CalendarDays, Download, Handshake, Newspaper, Radio, ShieldCheck } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface KpiLaporan {
    berlabel: number;
    negatif: number;
    netral: number;
    positif: number;
    media_aktif: number;
    media_total_aktif: number;
    media_bekerja_sama: number;
    media_tidak_bekerja_sama: number;
}

interface BarisTren {
    tanggal: string;
    rentang_dari: string;
    rentang_sampai: string;
    jumlah_negatif: number;
    jumlah_netral: number;
    jumlah_positif: number;
}

interface MediaLaporan {
    id: number;
    nama: string;
    tier: string | null;
    partner: boolean;
    jumlah_artikel: number;
    jumlah_negatif: number;
    jumlah_netral: number;
    jumlah_positif: number;
}

interface NarasiLaporan {
    judul: string | null;
    ringkasan: string | null;
    penjelasan_tren: string | null;
    poin: Array<{ teks: string; artikel_ids: number[] }>;
    dari: string;
    sampai: string;
    dibuat_at: string;
}

const props = defineProps<{
    bulan: string;
    opsiBulan: string[];
    periode: { dari: string; sampai: string };
    dibuatPada: string;
    kpi: KpiLaporan;
    narasi: NarasiLaporan | null;
    deret: { satuan: string; baris: BarisTren[] };
    peringkatMedia: MediaLaporan[];
}>();

const { formatAngka, formatProporsi } = useFormatAngka();
const page = usePage<SharedData>();
const bulanDipilih = ref(props.bulan);
const formatWita = new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'long',
    timeStyle: 'short',
    timeZone: 'Asia/Makassar',
});

watch(
    () => props.bulan,
    (bulan) => (bulanDipilih.value = bulan),
);

watch(bulanDipilih, (bulan) => {
    if (!bulan || bulan === props.bulan) return;

    router.get('/eksekutif/laporan', { bulan }, { preserveScroll: true, replace: true });
});

const namaBulan = computed(() => format(new Date(`${props.bulan}-01T00:00:00`), 'MMMM yyyy', { locale: id }));
const memakaiLayoutAdmin = computed(() => page.props.auth.user?.peran === 'superadmin');
const layoutLaporan = computed(() => (memakaiLayoutAdmin.value ? LayoutAdmin : LayoutEksekutif));
const propertiLayout = computed(() =>
    memakaiLayoutAdmin.value
        ? {
              breadcrumbs: [
                  { title: 'Analisis bulanan', href: '/admin/analisis-bulanan' },
                  { title: `Laporan ${namaBulan.value}`, href: `/eksekutif/laporan?bulan=${props.bulan}` },
              ],
          }
        : {},
);
const waktuPembuatan = computed(() => `${formatWita.format(new Date(props.dibuatPada))} WITA`);
const tanggalLengkap = (tanggal: string) => format(new Date(`${tanggal}T00:00:00`), 'd MMMM yyyy', { locale: id });
const rentangTanggal = (dari: string, sampai: string) => {
    if (dari === sampai) return tanggalLengkap(dari);

    const awal = format(new Date(`${dari}T00:00:00`), 'd', { locale: id });

    return `${awal}-${tanggalLengkap(sampai)}`;
};
const rentangPeriode = computed(() => `${tanggalLengkap(props.periode.dari)} sampai ${tanggalLengkap(props.periode.sampai)}`);
const adaData = computed(() => props.kpi.berlabel > 0);
const pilihanBulan = computed(() =>
    props.opsiBulan.map((nilai) => ({
        nilai,
        label: format(new Date(`${nilai}-01T00:00:00`), 'MMMM yyyy', { locale: id }),
    })),
);

const nada = computed(() => [
    { nama: 'Positif', jumlah: props.kpi.positif, kelas: 'bg-sentimen-positif', teks: 'text-sentimen-positif' },
    { nama: 'Netral', jumlah: props.kpi.netral, kelas: 'bg-sentimen-netral', teks: 'text-sentimen-netral' },
    { nama: 'Negatif', jumlah: props.kpi.negatif, kelas: 'bg-sentimen-negatif', teks: 'text-sentimen-negatif' },
]);

const nadaTerbanyak = computed(() => [...nada.value].sort((a, b) => b.jumlah - a.jumlah)[0]);

const ringkasan = computed(() => {
    if (props.kpi.berlabel === 0) {
        return `Belum ada pemberitaan yang tercatat pada ${namaBulan.value}.`;
    }

    return `${formatAngka(props.kpi.berlabel)} berita tercatat pada periode ini. Nada ${nadaTerbanyak.value.nama.toLowerCase()} paling banyak muncul, yaitu ${formatAngka(nadaTerbanyak.value.jumlah)} berita (${formatProporsi(nadaTerbanyak.value.jumlah, props.kpi.berlabel)}).`;
});

const tren = computed(() =>
    props.deret.baris.map((baris) => ({
        ...baris,
        berlabel: baris.jumlah_positif + baris.jumlah_netral + baris.jumlah_negatif,
    })),
);

/**
 * Berkas PDF dibuat server, jadi tombolnya cukup sebuah tautan unduh.
 *
 * Peramban tidak bisa menghasilkan berkas PDF tanpa membuka dialog cetak, jadi
 * dokumen yang sama disusun ulang sebagai templat Blade dan dirender dompdf.
 * Rupanya dijaga sama dengan pratinjau di halaman ini.
 */
const tautanUnduh = computed(() => `/eksekutif/laporan/unduh?bulan=${props.bulan}`);
</script>

<template>
    <Head :title="`Laporan pemberitaan ${namaBulan}`" />

    <component :is="layoutLaporan" v-bind="propertiLayout">
        <div class="contents" :data-laporan-admin="memakaiLayoutAdmin ? '' : undefined">
            <KopEksekutif
                class="sembunyikan-saat-cetak"
                judul="Laporan kondisi pemberitaan"
                keterangan="Pilih bulan, periksa ringkasannya, lalu unduh berkas PDF-nya."
            >
                <template #kendali>
                    <div class="flex flex-wrap items-end gap-3">
                        <PermukaanKendaliKop>
                            <div class="min-w-52 space-y-1.5 p-1">
                                <Label for="bulan-laporan" class="text-xs text-muted-foreground">Periode laporan</Label>
                                <Select v-model="bulanDipilih">
                                    <SelectTrigger id="bulan-laporan" class="h-9 w-full bg-background" aria-label="Pilih bulan laporan">
                                        <CalendarDays class="mr-2 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                        <SelectValue placeholder="Pilih bulan" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="pilihan in pilihanBulan" :key="pilihan.nilai" :value="pilihan.nilai">
                                            <span class="capitalize">{{ pilihan.label }}</span>
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </PermukaanKendaliKop>
                        <!--
                            Tautan biasa, bukan tombol yang memanggil skrip.
                            Peramban mengunduhnya sendiri lewat header dari
                            server, sehingga menu klik kanan, unduhan ulang, dan
                            penyimpanan ke folder pilihan pengguna semuanya
                            bekerja tanpa satu baris kode pun.
                        -->
                        <Button
                            v-if="adaData"
                            as="a"
                            :href="tautanUnduh"
                            class="min-h-12 w-full justify-start gap-3 rounded-xl bg-white px-3.5 py-2 text-brand shadow-lg ring-1 shadow-brand/25 ring-white/70 hover:bg-brand-lembut sm:w-auto"
                            title="Unduh laporan bulan ini sebagai PDF"
                        >
                            <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-brand text-white" aria-hidden="true">
                                <Download class="size-4" />
                            </span>
                            <span class="flex flex-col items-start gap-1 leading-none">
                                <span class="font-semibold">Unduh laporan</span>
                                <span class="text-[0.6875rem] font-normal text-brand/70">PDF · F4 potret</span>
                            </span>
                        </Button>
                        <Button
                            v-else
                            type="button"
                            disabled
                            class="min-h-12 w-full justify-start gap-3 rounded-xl bg-white px-3.5 py-2 text-brand shadow-lg ring-1 shadow-brand/25 ring-white/70 sm:w-auto"
                            aria-describedby="status-cetak"
                            title="Belum ada pemberitaan pada bulan ini"
                        >
                            <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-brand text-white" aria-hidden="true">
                                <Download class="size-4" />
                            </span>
                            <span class="flex flex-col items-start gap-1 leading-none">
                                <span class="font-semibold">Unduh laporan</span>
                                <span class="text-[0.6875rem] font-normal text-brand/70">PDF · F4 potret</span>
                            </span>
                        </Button>
                    </div>
                    <PermukaanKendaliKop v-if="!adaData" class="mt-2 max-w-sm">
                        <p id="status-cetak" class="px-2 py-1 text-xs leading-relaxed text-muted-foreground">
                            Laporan belum dapat diunduh karena belum ada pemberitaan pada bulan ini.
                        </p>
                    </PermukaanKendaliKop>
                </template>

                Periode selalu dimulai tanggal 1 dan berakhir pada hari terakhir bulan yang dipilih.
            </KopEksekutif>

            <article class="laporan-cetak overflow-hidden rounded-2xl bg-white text-slate-900 shadow-xl ring-1 ring-slate-200">
                <header class="kop-laporan-cetak bg-brand px-5 py-5 text-white sm:px-8 sm:py-6">
                    <div class="flex items-start justify-between gap-5">
                        <div class="flex min-w-0 items-center gap-3.5">
                            <span class="flex shrink-0 items-center gap-2 rounded-xl bg-white/10 p-2 ring-1 ring-white/20">
                                <img
                                    src="/img/Lambang_Kota_Kendari.webp"
                                    alt="Lambang Kota Kendari"
                                    class="size-9 object-contain"
                                    width="36"
                                    height="36"
                                />
                                <span class="h-8 w-px bg-white/25" aria-hidden="true"></span>
                                <img src="/img/logo-simedia.webp" alt="Lambang SIMAK" class="size-9 object-contain" width="36" height="36" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-white">Pemerintah Kota Kendari</p>
                                <p class="text-xs text-white/70">Sistem Pemantauan dan Analisis Pemberitaan Media</p>
                            </div>
                        </div>

                        <div class="hidden text-right sm:block">
                            <p class="text-xs font-medium tracking-wide text-white/70 uppercase">Periode</p>
                            <p class="mt-1 text-sm font-semibold capitalize">{{ namaBulan }}</p>
                        </div>
                    </div>

                    <div class="mt-7 border-t border-white/20 pt-5">
                        <h1 class="max-w-[24ch] text-2xl leading-tight font-semibold tracking-tight sm:text-3xl">
                            Laporan kondisi pemberitaan Kota Kendari
                        </h1>
                        <p class="mt-2 text-sm text-white/75">Periode {{ rentangPeriode }}</p>
                    </div>
                </header>

                <div class="isi-laporan-cetak space-y-8 px-5 py-6 sm:px-8 sm:py-8">
                    <section
                        class="ringkasan-laporan-cetak jangan-putus grid gap-5 border-b border-slate-200 pb-7 lg:grid-cols-[1fr_auto] lg:items-start"
                    >
                        <div>
                            <h2 class="text-lg font-semibold tracking-tight text-slate-950">Ringkasan keadaan</h2>
                            <p class="mt-2 max-w-[72ch] text-sm leading-relaxed text-slate-600">{{ ringkasan }}</p>
                        </div>
                        <div class="flex items-center gap-2 rounded-xl bg-slate-100 px-3 py-2 text-xs font-medium text-slate-700">
                            <CalendarDays class="size-4 text-brand" aria-hidden="true" />
                            Dibuat {{ waktuPembuatan }}
                        </div>
                    </section>

                    <section
                        v-if="adaData"
                        class="analisis-laporan-cetak jangan-putus rounded-xl border border-aksen-ungu/20 bg-aksen-ungu/5 p-5"
                        aria-labelledby="analisis-pemberitaan"
                    >
                        <div class="flex items-start gap-3">
                            <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-aksen-ungu text-white">
                                <Newspaper class="size-4.5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <h2 id="analisis-pemberitaan" class="text-lg font-semibold tracking-tight text-slate-950">Analisis pemberitaan</h2>
                                <p class="mt-1 max-w-[72ch] text-xs leading-relaxed text-slate-500">
                                    Ikhtisar topik dan pola pemberitaan selama bulan yang dipilih.
                                </p>
                            </div>
                        </div>

                        <template v-if="narasi?.ringkasan">
                            <h3 v-if="narasi.judul" class="mt-4 max-w-[72ch] text-base leading-snug font-semibold text-aksen-ungu">
                                {{ narasi.judul }}
                            </h3>
                            <p class="mt-2 max-w-[72ch] text-sm leading-relaxed whitespace-pre-line text-slate-700">
                                {{ narasi.ringkasan }}
                            </p>

                            <ul v-if="narasi.poin.length" class="mt-4 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                                <li
                                    v-for="poin in narasi.poin"
                                    :key="poin.teks"
                                    class="flex items-start gap-2 text-sm leading-relaxed text-slate-600"
                                >
                                    <span class="mt-2 size-1.5 shrink-0 rounded-full bg-aksen-ungu" aria-hidden="true"></span>
                                    <span>{{ poin.teks }}</span>
                                </li>
                            </ul>

                            <p v-if="narasi.penjelasan_tren" class="mt-4 border-t border-aksen-ungu/15 pt-3 text-sm leading-relaxed text-slate-600">
                                <span class="font-semibold text-slate-800">Pola sepanjang bulan:</span>
                                {{ narasi.penjelasan_tren }}
                            </p>
                        </template>

                        <p v-else class="mt-4 text-sm leading-relaxed text-slate-600">
                            Analisis pemberitaan untuk bulan ini sedang disiapkan. Angka laporan tetap dapat dibaca dan dicetak.
                        </p>
                    </section>

                    <section class="ikhtisar-laporan-cetak jangan-putus" aria-labelledby="ikhtisar-laporan">
                        <h2 id="ikhtisar-laporan" class="text-lg font-semibold tracking-tight text-slate-950">Ikhtisar pemberitaan</h2>
                        <div class="mt-4 grid border-y border-slate-200 sm:grid-cols-3">
                            <div class="border-b border-slate-200 py-4 sm:border-r lg:border-b-0">
                                <div class="flex items-center gap-2 text-xs font-medium text-slate-500">
                                    <Newspaper class="size-4 text-brand" aria-hidden="true" />
                                    Total pemberitaan
                                </div>
                                <p class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">{{ formatAngka(kpi.berlabel) }}</p>
                                <p class="mt-1 text-xs text-slate-500">berita pada bulan ini</p>
                            </div>
                            <div class="border-b border-slate-200 py-4 sm:border-r sm:pl-5 lg:border-b-0">
                                <div class="flex items-center gap-2 text-xs font-medium text-slate-500">
                                    <Radio class="size-4 text-brand" aria-hidden="true" />
                                    Media memberitakan
                                </div>
                                <p class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                                    {{ formatAngka(kpi.media_aktif)
                                    }}<span class="text-lg text-slate-400">/{{ formatAngka(kpi.media_total_aktif) }}</span>
                                </p>
                                <p class="mt-1 text-xs text-slate-500">media pada bulan ini</p>
                            </div>
                            <div class="py-4 sm:pl-5">
                                <div class="flex items-center gap-2 text-xs font-medium text-slate-500">
                                    <Handshake class="size-4 text-brand" aria-hidden="true" />
                                    Status kerja sama
                                </div>
                                <p class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">{{ formatAngka(kpi.media_bekerja_sama) }}</p>
                                <p class="mt-1 text-xs text-slate-500">bekerja sama · {{ formatAngka(kpi.media_tidak_bekerja_sama) }} tidak</p>
                            </div>
                        </div>
                    </section>

                    <section
                        class="komposisi-laporan-cetak jangan-putus grid gap-6 lg:grid-cols-[0.85fr_1.15fr]"
                        aria-labelledby="komposisi-sentimen"
                    >
                        <div>
                            <h2 id="komposisi-sentimen" class="text-lg font-semibold tracking-tight text-slate-950">Komposisi sentimen</h2>
                            <p class="mt-1 text-sm text-slate-500">Perbandingan nada positif, netral, dan negatif pada pemberitaan bulan ini.</p>
                        </div>

                        <div class="space-y-3">
                            <div v-for="item in nada" :key="item.nama" class="grid grid-cols-[4.5rem_1fr_auto] items-center gap-3">
                                <span class="text-sm font-medium" :class="item.teks">{{ item.nama }}</span>
                                <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div
                                        class="h-full rounded-full"
                                        :class="item.kelas"
                                        :style="{ width: `${kpi.berlabel === 0 ? 0 : (item.jumlah / kpi.berlabel) * 100}%` }"
                                    ></div>
                                </div>
                                <span class="min-w-24 text-right text-sm font-semibold text-slate-900">
                                    {{ formatAngka(item.jumlah) }} · {{ formatProporsi(item.jumlah, kpi.berlabel) }}
                                </span>
                            </div>
                        </div>
                    </section>

                    <section class="bagian-tren-cetak" aria-labelledby="tren-pemberitaan">
                        <div class="flex flex-wrap items-end justify-between gap-2">
                            <div>
                                <h2 id="tren-pemberitaan" class="text-lg font-semibold tracking-tight text-slate-950">Tren dalam bulan</h2>
                                <p class="mt-1 text-sm text-slate-500">Ringkasan mingguan untuk melihat perubahan volume dan nada pemberitaan.</p>
                            </div>
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">{{ tren.length }} periode</span>
                        </div>

                        <div class="tabel-laporan-cetak mt-4 overflow-x-auto rounded-xl border border-slate-200">
                            <table class="w-full min-w-[640px] border-collapse text-left text-sm">
                                <thead class="bg-slate-100 text-xs font-semibold text-slate-600">
                                    <tr>
                                        <th scope="col" class="px-4 py-3">Rentang tanggal</th>
                                        <th scope="col" class="px-4 py-3 text-right">Total</th>
                                        <th scope="col" class="px-4 py-3 text-right">Positif</th>
                                        <th scope="col" class="px-4 py-3 text-right">Netral</th>
                                        <th scope="col" class="px-4 py-3 text-right">Negatif</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200">
                                    <tr v-for="baris in tren" :key="baris.tanggal">
                                        <td class="px-4 py-3 font-medium text-slate-900">
                                            {{ rentangTanggal(baris.rentang_dari, baris.rentang_sampai) }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold text-slate-900">{{ formatAngka(baris.berlabel) }}</td>
                                        <td class="px-4 py-3 text-right text-sentimen-positif">{{ formatAngka(baris.jumlah_positif) }}</td>
                                        <td class="px-4 py-3 text-right text-slate-600">{{ formatAngka(baris.jumlah_netral) }}</td>
                                        <td class="px-4 py-3 text-right text-sentimen-negatif">{{ formatAngka(baris.jumlah_negatif) }}</td>
                                    </tr>
                                    <tr v-if="tren.length === 0">
                                        <td colspan="5" class="px-4 py-8 text-center text-slate-500">Belum ada pemberitaan pada bulan ini.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="bagian-media-cetak mulai-halaman-baru" aria-labelledby="kondisi-media">
                        <div class="flex flex-wrap items-end justify-between gap-2">
                            <div>
                                <h2 id="kondisi-media" class="text-lg font-semibold tracking-tight text-slate-950">Kondisi media aktif</h2>
                                <p class="mt-1 text-sm text-slate-500">
                                    Seluruh media aktif ditampilkan, termasuk yang belum memberitakan pada bulan ini.
                                </p>
                            </div>
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
                                {{ formatAngka(peringkatMedia.length) }} media
                            </span>
                        </div>

                        <div class="tabel-laporan-cetak mt-4 overflow-x-auto rounded-xl border border-slate-200">
                            <table class="w-full min-w-[700px] border-collapse text-left text-sm">
                                <thead class="bg-slate-100 text-xs font-semibold text-slate-600">
                                    <tr>
                                        <th scope="col" class="w-10 px-4 py-3 text-center">No.</th>
                                        <th scope="col" class="px-4 py-3">Media</th>
                                        <th scope="col" class="px-4 py-3">Kerja sama</th>
                                        <th scope="col" class="px-4 py-3 text-right">Total</th>
                                        <th scope="col" class="px-4 py-3 text-right">Positif</th>
                                        <th scope="col" class="px-4 py-3 text-right">Netral</th>
                                        <th scope="col" class="px-4 py-3 text-right">Negatif</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200">
                                    <tr v-for="(media, urutan) in peringkatMedia" :key="media.id" class="break-inside-avoid">
                                        <td class="px-4 py-3 text-center text-slate-400">{{ urutan + 1 }}.</td>
                                        <td class="px-4 py-3 font-medium text-slate-900">{{ media.nama }}</td>
                                        <td class="px-4 py-3">
                                            <span
                                                class="inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-xs font-medium"
                                                :class="media.partner ? 'bg-brand-lembut text-brand' : 'bg-slate-100 text-slate-600'"
                                            >
                                                <span
                                                    class="size-1.5 rounded-full"
                                                    :class="media.partner ? 'bg-brand' : 'bg-slate-400'"
                                                    aria-hidden="true"
                                                ></span>
                                                {{ media.partner ? 'Ya' : 'Tidak' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold text-slate-900">{{ formatAngka(media.jumlah_artikel) }}</td>
                                        <td class="px-4 py-3 text-right text-sentimen-positif">{{ formatAngka(media.jumlah_positif) }}</td>
                                        <td class="px-4 py-3 text-right text-slate-600">{{ formatAngka(media.jumlah_netral) }}</td>
                                        <td class="px-4 py-3 text-right text-sentimen-negatif">{{ formatAngka(media.jumlah_negatif) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <footer class="kaki-laporan-cetak jangan-putus border-t border-slate-200 pt-5">
                        <div class="flex items-start gap-3 rounded-xl bg-brand-lembut p-4 text-brand">
                            <ShieldCheck class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                            <div>
                                <p class="text-sm font-semibold">Catatan penggunaan</p>
                                <p class="mt-1 text-xs leading-relaxed text-brand/80">
                                    Laporan ini menggambarkan volume dan sentimen pemberitaan media tentang Pemerintah Kota Kendari. Angka di dalamnya
                                    bukan penilaian kinerja pemerintah.
                                </p>
                            </div>
                        </div>

                        <div class="mt-5 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
                            <span class="inline-flex items-center gap-1.5">
                                <Newspaper class="size-3.5" aria-hidden="true" />
                                Sumber: agregasi berita SIMAK Kota Kendari
                            </span>
                            <span>Dicetak dari SIMAK · {{ waktuPembuatan }}</span>
                        </div>
                    </footer>
                </div>
            </article>
        </div>
    </component>
</template>
