<script setup lang="ts">
import DataTableFacetedFilter from '@/components/data-table/DataTableFacetedFilter.vue';
import DataTablePagination from '@/components/data-table/DataTablePagination.vue';
import KartuArtikel from '@/components/domain/KartuArtikel.vue';
import KartuEksekutif from '@/components/domain/KartuEksekutif.vue';
import KopEksekutif from '@/components/domain/KopEksekutif.vue';
import PemilihRentangTanggal from '@/components/domain/PemilihRentangTanggal.vue';
import PilKop from '@/components/domain/PilKop.vue';
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useFilterTabel } from '@/composables/useFilterTabel';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import type { OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { Head } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { ArrowDownUp, Filter, Newspaper, Search, SlidersHorizontal, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/*
 * Arti warna di halaman ini mengikuti tabel yang sama dengan dashboard. Yang
 * paling terlihat di sini: ketiga tombol nada memakai warna nadanya sendiri
 * saat menyala, sehingga penyaring yang sedang berlaku terbaca dari warnanya
 * tanpa membaca satu label pun. Biru aksen dipakai hasil dan arsip, dan ungu
 * hanya untuk penanda bahwa daftar sedang dipersempit ke satu topik tulisan
 * model.
 */

type Label = 'negatif' | 'netral' | 'positif';

interface Baris {
    id: number;
    judul: string;
    url: string;
    media: string | null;
    diambil_at: string;
    label: Label | null;
    perlu_review: boolean;
    /** Alasan model atas label yang diberikannya. Kosong pada baris analisis lama. */
    ringkasan_ai: string | null;
}

const props = defineProps<{
    periode: { dari: string; sampai: string };
    artikel: { data: Baris[] } & PaginasiMeta;
    disaringTopik: boolean;
    opsi: Record<string, OpsiFilter[]>;
}>();

const { formatAngka } = useFormatAngka();

/**
 * Seluruh kendali halaman ini lewat satu pintu yang sama.
 *
 * Sebelumnya rentang tanggal memakai `usePeriodeEksekutif` sedangkan pencarian
 * dan filter memakai `useFilterTabel`. Keduanya menyusun query string dengan
 * cara yang berbeda: yang pertama menulis ulang alamat hanya dengan rentang dan
 * satu parameter tambahan, sehingga mengganti tanggal diam diam menghapus kata
 * pencarian, filter media, filter nada, dan urutan yang sedang dipakai.
 */
const { kueri, kunjungi, cari, cariDengan, saring, nilaiFilter, keHalaman } = useFilterTabel('/eksekutif/berita');

const rentangTerbaca = computed(
    () =>
        `${format(new Date(props.periode.dari), 'd MMMM', { locale: id })} sampai ` +
        `${format(new Date(props.periode.sampai), 'd MMMM yyyy', { locale: id })}`,
);

/** Kotak cari lokal, dikirim ke server setelah pengetikan berhenti. */
const kata = ref(cari.value);
const cariTertunda = useDebounceFn((nilai: string) => cariDengan(nilai), 350);

watch(kata, (nilai) => cariTertunda(nilai));
watch(cari, (nilai) => {
    if (nilai !== kata.value) kata.value = nilai;
});

/**
 * Tiga nada sebagai tombol berjajar, bukan daftar di balik dropdown.
 *
 * Pilihannya cuma tiga dan ketiganya punya warna yang sudah dikenal di seluruh
 * panel ini. Menyembunyikan tiga pilihan di balik satu ketukan menukar ruang
 * layar dengan langkah tambahan, padahal ruangnya tidak sedang kurang.
 */
const nada = [
    { nilai: 'positif', label: 'Positif', aktif: 'bg-sentimen-positif text-white shadow-sm dark:text-background', titik: 'bg-sentimen-positif' },
    { nilai: 'netral', label: 'Netral', aktif: 'bg-sentimen-netral text-white shadow-sm dark:text-background', titik: 'bg-sentimen-netral' },
    { nilai: 'negatif', label: 'Negatif', aktif: 'bg-sentimen-negatif text-white shadow-sm dark:text-background', titik: 'bg-sentimen-negatif' },
];

const nadaTerpilih = computed(() => nilaiFilter('sentimen'));

function alihkanNada(nilai: string) {
    const terpilih = nadaTerpilih.value;

    saring('sentimen', terpilih.includes(nilai) ? terpilih.filter((n) => n !== nilai) : [...terpilih, nilai]);
}

/**
 * Urutan sebagai tiga tombol, menggantikan kepala kolom yang bisa diklik.
 *
 * Daftarnya bukan tabel lagi, jadi tidak ada kepala kolom untuk diklik. Nilai
 * bawaannya harus ditulis sama dengan bawaan controller, yaitu `diambil_at`
 * menurun, supaya tombol yang menyala saat halaman dibuka pertama kali benar
 * benar menggambarkan urutan yang sedang tampil.
 */
const opsiUrutan = [
    { label: 'Terbaru', urut: 'diambil_at', arah: 'desc' },
    { label: 'Terlama', urut: 'diambil_at', arah: 'asc' },
    { label: 'Judul A sampai Z', urut: 'judul', arah: 'asc' },
];

const urutanAktif = computed(() => `${kueri.value.urut ?? 'diambil_at'}:${kueri.value.arah ?? 'desc'}`);

/**
 * Penyaring yang bisa diatur ulang, di luar rentang tanggal dan urutan.
 *
 * Rentang tanggal adalah konteks halaman, bukan penyaring yang menempel. Ikut
 * menghapusnya lewat tombol atur ulang berarti pengguna yang cuma ingin
 * membuang filter media juga kehilangan rentang tiga bulan yang baru saja
 * dipilihnya.
 */
const saringanAktif = computed(() => ['cari', 'media', 'sentimen', 'artikel'].filter((k) => (kueri.value[k] ?? '') !== ''));

function aturUlangSaringan() {
    kunjungi({ cari: null, media: null, sentimen: null, artikel: null });
}
</script>

<template>
    <Head title="Arsip berita" />

    <LayoutEksekutif>
        <!--
            Kotak cari duduk di dalam talam terang kop, bukan di papan penyaring
            di bawahnya.

            Mencari judul adalah satu-satunya hal yang dibawa pembaca ke halaman
            ini, dan kendali yang paling sering dipakai berhak atas tempat yang
            paling dulu ditemukan mata. Sisa penyaring, yang dipakai jauh lebih
            jarang, tetap berkumpul di papan di bawahnya.
        -->
        <KopEksekutif judul="Arsip berita" :keterangan="`Seluruh berita tentang Pemerintah Kota, ${rentangTerbaca}`">
            <template #kendali>
                <div class="relative w-full sm:w-[22rem]">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                    <Input
                        v-model="kata"
                        type="search"
                        placeholder="Cari judul atau penulis"
                        class="h-10 border-transparent bg-transparent pl-9 text-sm shadow-none focus-visible:ring-1"
                        aria-label="Cari berita di arsip"
                    />
                </div>
            </template>

            <!-- Rentangnya tidak diulang sebagai pil. Kalimat keterangan tepat
                 di atas baris ini sudah menyebutnya dengan kata yang sama
                 persis, dan pil yang mengulang kalimat di sebelahnya hanya
                 menambah satu benda untuk dibaca. -->
            <template #pil>
                <PilKop :ikon="Newspaper">{{ formatAngka(artikel.total) }} berita ditemukan</PilKop>
                <PilKop v-if="saringanAktif.length" nada="kerja" :ikon="Filter"> {{ formatAngka(saringanAktif.length) }} penyaring aktif </PilKop>
            </template>
        </KopEksekutif>

        <!--
            Satu papan penyaring, bukan kendali yang tersebar.

            Isinya tersusun dari yang paling sering diubah ke yang paling jarang,
            dan tiap kelompok diberi label sendiri. Sebelumnya keenam kendali
            berdiri tanpa nama dalam tiga baris bergaris, dan pengguna harus
            menebak bahwa deretan pil pertama mengatur urutan sedangkan deretan
            kedua menyaring nada.
        -->
        <Card class="muncul relative overflow-hidden" style="animation-delay: 60ms">
            <div
                class="tumbuh pointer-events-none absolute inset-x-0 top-0 h-[2px] bg-gradient-to-r from-aksen-biru to-transparent"
                aria-hidden="true"
            ></div>

            <CardContent class="space-y-3 p-4 pt-5">
                <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    <SlidersHorizontal class="size-3.5 shrink-0" aria-hidden="true" />
                    <span class="shrink-0">Penyaring</span>
                    <span class="h-px flex-1 bg-gradient-to-r from-border to-transparent" aria-hidden="true"></span>

                    <Button v-if="saringanAktif.length" variant="ghost" size="sm" class="-my-1 h-7 shrink-0 px-2 text-xs" @click="aturUlangSaringan">
                        Atur ulang
                        <X class="ml-1.5 size-3.5" aria-hidden="true" />
                    </Button>
                </p>

                <!--
                    Rentang tanggal berdiri langsung di halaman, sejajar dengan
                    penyaring lain. Pintasan di kiri untuk perpindahan yang
                    paling sering, dua kotak tanggal di kanan untuk rentang yang
                    tidak tertampung pintasan.
                -->
                <div class="flex flex-wrap items-center gap-2">
                    <PemilihRentangTanggal
                        :dari="periode.dari"
                        :sampai="periode.sampai"
                        inline
                        tanpa-sheet
                        @ubah="(dari, sampai) => kunjungi({ dari, sampai })"
                    />
                </div>

                <div class="flex flex-wrap items-center gap-2 border-t pt-3">
                    <DataTableFacetedFilter
                        :filter="{ kunci: 'media', label: 'Media', opsi: props.opsi.media }"
                        :terpilih="nilaiFilter('media')"
                        @ubah="(nilai) => saring('media', nilai)"
                    />

                    <!--
                        Tombol nada memakai warna nadanya sendiri saat menyala.
                        Itu satu-satunya tempat di halaman ini warna menyatakan
                        pilihan pengguna dan bukan isi data, dan keduanya tetap
                        sepakat karena warnanya persis warna nada yang sedang
                        disaring.
                    -->
                    <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Saring menurut nada">
                        <button
                            v-for="n in nada"
                            :key="n.nilai"
                            type="button"
                            :aria-pressed="nadaTerpilih.includes(n.nilai)"
                            class="tekan ease-[cubic-bezier(0.32,0.72,0,1)] inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-xs font-semibold transition-colors duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :class="nadaTerpilih.includes(n.nilai) ? n.aktif : 'bg-muted text-muted-foreground hover:text-foreground'"
                            @click="alihkanNada(n.nilai)"
                        >
                            <span v-if="!nadaTerpilih.includes(n.nilai)" :class="n.titik" class="size-2 rounded-full" aria-hidden="true"></span>
                            {{ n.label }}
                        </button>
                    </div>

                    <!--
                        Penanda bahwa daftar ini sedang dipersempit ke satu topik
                        tulisan model, karena itu warnanya ungu. Sebelumnya
                        penanda ini melayang sendiri di antara kop dan tabel,
                        padahal isinya penyaring yang sama seperti tetangganya.
                    -->
                    <span
                        v-if="disaringTopik"
                        class="inline-flex h-8 items-center gap-1.5 rounded-full bg-aksen-ungu/10 px-3 text-xs font-semibold text-aksen-ungu ring-1 ring-inset ring-aksen-ungu/25"
                    >
                        Hanya berita dari satu topik
                        <button
                            type="button"
                            class="tekan rounded-full p-0.5 hover:bg-aksen-ungu/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            aria-label="Tampilkan semua berita, bukan hanya satu topik"
                            @click="kunjungi({ artikel: null })"
                        >
                            <X class="size-3" aria-hidden="true" />
                        </button>
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-2 border-t pt-3">
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                        <ArrowDownUp class="size-3.5 shrink-0" aria-hidden="true" />
                        Urutkan
                    </span>

                    <div class="flex items-center gap-1 rounded-full bg-muted p-1" role="group" aria-label="Urutan daftar">
                        <button
                            v-for="opsi in opsiUrutan"
                            :key="opsi.label"
                            type="button"
                            :aria-pressed="urutanAktif === `${opsi.urut}:${opsi.arah}`"
                            class="tekan ease-[cubic-bezier(0.32,0.72,0,1)] rounded-full px-3 py-1.5 text-xs font-semibold transition-colors duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :class="
                                urutanAktif === `${opsi.urut}:${opsi.arah}`
                                    ? 'bg-aksen-biru text-white shadow-sm dark:text-background'
                                    : 'text-muted-foreground hover:text-foreground'
                            "
                            @click="kunjungi({ urut: opsi.urut, arah: opsi.arah })"
                        >
                            {{ opsi.label }}
                        </button>
                    </div>
                </div>
            </CardContent>
        </Card>

        <div class="muncul" style="animation-delay: 120ms">
            <KartuEksekutif
                judul="Hasil"
                :catatan="
                    artikel.total > 0
                        ? `Menampilkan ${formatAngka(artikel.from)} sampai ${formatAngka(artikel.to)} dari ${formatAngka(artikel.total)} berita`
                        : 'Tidak ada berita yang cocok dengan penyaring yang sedang dipakai'
                "
                :ikon="Newspaper"
                rona="biru"
            >
                <!--
                    Baris berita, bukan sel tabel.

                    Bentuknya sama dengan daftar berita di dashboard dan halaman
                    sentimen, karena isinya memang benda yang sama. Tabel empat
                    kolom memaksa judul dipotong dua baris di kolom sempit, dan
                    di layar 375 piksel kolom nada terdorong keluar layar.
                    Sebagai baris, judulnya punya lebar penuh dan alasan model
                    atas nadanya ikut terbaca tanpa membuka artikelnya.
                -->
                <div v-if="artikel.data.length" class="divide-y">
                    <div v-for="b in artikel.data" :key="b.id" class="tekan rounded-lg px-2 hover:bg-muted/60">
                        <KartuArtikel
                            :judul="b.judul"
                            :url="b.url"
                            :media="b.media"
                            :diambil-at="b.diambil_at"
                            :label="b.label"
                            :perlu-review="b.perlu_review"
                            :ringkasan-ai="b.ringkasan_ai"
                            tampilkan-sentimen
                        />
                    </div>
                </div>

                <KeadaanKosong
                    v-else
                    judul="Belum ada berita yang cocok"
                    :keterangan="
                        saringanAktif.length
                            ? 'Tidak ada berita yang cocok dengan penyaring yang sedang dipakai. Longgarkan penyaringnya, atau atur ulang.'
                            : 'Perlebar rentang tanggalnya, atau periksa apakah crawler berjalan.'
                    "
                >
                    <Button v-if="saringanAktif.length" variant="outline" size="sm" class="mt-1" @click="aturUlangSaringan">
                        Atur ulang penyaring
                    </Button>
                </KeadaanKosong>

                <DataTablePagination v-if="artikel.total > 0" :meta="artikel" @ke-halaman="keHalaman" />
            </KartuEksekutif>
        </div>
    </LayoutEksekutif>
</template>
