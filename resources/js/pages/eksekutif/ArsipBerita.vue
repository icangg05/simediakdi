<script setup lang="ts">
import DataTableFacetedFilter from '@/components/data-table/DataTableFacetedFilter.vue';
import DataTablePagination from '@/components/data-table/DataTablePagination.vue';
import KartuArtikel from '@/components/domain/KartuArtikel.vue';
import PemilihRentangTanggal from '@/components/domain/PemilihRentangTanggal.vue';
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useFilterTabel } from '@/composables/useFilterTabel';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import type { OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { Head } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { Newspaper, Search, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

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
    { nilai: 'positif', label: 'Positif', aktif: 'bg-sentimen-positif text-white dark:text-background', titik: 'bg-sentimen-positif' },
    { nilai: 'netral', label: 'Netral', aktif: 'bg-sentimen-netral text-white dark:text-background', titik: 'bg-sentimen-netral' },
    { nilai: 'negatif', label: 'Negatif', aktif: 'bg-sentimen-negatif text-white dark:text-background', titik: 'bg-sentimen-negatif' },
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
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-primary dark:text-aksen-biru">Arsip berita</h1>
                <p class="text-sm text-muted-foreground">Seluruh berita tentang Pemerintah Kota, {{ rentangTerbaca }}</p>
            </div>
        </header>

        <!--
            Satu papan penyaring, bukan kendali yang tersebar.

            Dulu rentang tanggal berdiri di kop halaman sedangkan pencarian dan
            filter berdiri di atas tabel, sehingga dua kelompok kendali yang
            mengerjakan pekerjaan yang sama terpisah oleh judul halaman. Di sini
            keenamnya menempati satu bidang, tersusun dari yang paling sering
            diubah ke yang paling jarang.
        -->
        <Card class="muncul" style="animation-delay: 60ms">
            <CardContent class="space-y-3 p-4">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative min-w-[14rem] flex-1">
                        <Search class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" aria-hidden="true" />
                        <Input
                            v-model="kata"
                            type="search"
                            placeholder="Cari judul atau penulis"
                            class="h-9 pl-8"
                            :class="kata ? 'border-primary bg-primary/5' : ''"
                            aria-label="Cari berita di arsip"
                        />
                    </div>

                    <div class="flex items-center gap-1 rounded-full bg-muted p-1" role="group" aria-label="Urutan daftar">
                        <button
                            v-for="opsi in opsiUrutan"
                            :key="opsi.label"
                            type="button"
                            :aria-pressed="urutanAktif === `${opsi.urut}:${opsi.arah}`"
                            class="ease-[cubic-bezier(0.32,0.72,0,1)] rounded-full px-3 py-1.5 text-xs font-medium transition-colors duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :class="
                                urutanAktif === `${opsi.urut}:${opsi.arah}`
                                    ? 'bg-primary text-primary-foreground shadow-sm dark:bg-aksen-biru'
                                    : 'text-muted-foreground hover:text-foreground'
                            "
                            @click="kunjungi({ urut: opsi.urut, arah: opsi.arah })"
                        >
                            {{ opsi.label }}
                        </button>
                    </div>
                </div>

                <!--
                    Rentang tanggal berdiri langsung di halaman, sejajar dengan
                    penyaring lain. Pintasan di kiri untuk perpindahan yang
                    paling sering, dua kotak tanggal di kanan untuk rentang yang
                    tidak tertampung pintasan.
                -->
                <div class="flex flex-wrap items-center gap-2 border-t pt-3">
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

                    <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Saring menurut nada">
                        <button
                            v-for="n in nada"
                            :key="n.nilai"
                            type="button"
                            :aria-pressed="nadaTerpilih.includes(n.nilai)"
                            class="ease-[cubic-bezier(0.32,0.72,0,1)] inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-xs font-medium transition-colors duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :class="nadaTerpilih.includes(n.nilai) ? n.aktif : 'bg-muted text-muted-foreground hover:text-foreground'"
                            @click="alihkanNada(n.nilai)"
                        >
                            <span v-if="!nadaTerpilih.includes(n.nilai)" :class="n.titik" class="h-2 w-2 rounded-full" aria-hidden="true"></span>
                            {{ n.label }}
                        </button>
                    </div>

                    <!--
                        Penanda bahwa daftar ini sedang dipersempit ke satu
                        topik, dipindah masuk ke papan penyaring. Sebelumnya
                        penanda ini melayang sendiri di antara kop dan tabel,
                        padahal isinya penyaring yang sama seperti tetangganya.
                    -->
                    <span
                        v-if="disaringTopik"
                        class="inline-flex h-8 items-center gap-1.5 rounded-full bg-aksen-ungu/10 px-3 text-xs font-medium text-aksen-ungu"
                    >
                        Hanya berita dari satu topik
                        <button
                            type="button"
                            class="rounded-full p-0.5 hover:bg-aksen-ungu/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            aria-label="Tampilkan semua berita, bukan hanya satu topik"
                            @click="kunjungi({ artikel: null })"
                        >
                            <X class="h-3 w-3" aria-hidden="true" />
                        </button>
                    </span>

                    <Button v-if="saringanAktif.length" variant="ghost" size="sm" class="ml-auto h-8 px-2.5 text-xs" @click="aturUlangSaringan">
                        Atur ulang penyaring
                        <X class="ml-1.5 h-3.5 w-3.5" aria-hidden="true" />
                    </Button>
                </div>
            </CardContent>
        </Card>

        <Card class="muncul overflow-hidden" style="animation-delay: 120ms">
            <CardHeader class="flex-row flex-wrap items-center justify-between gap-3 bg-aksen-biru/[0.07] py-3.5">
                <CardTitle class="flex items-center gap-2.5 text-base">
                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-xl bg-aksen-biru text-white shadow-sm dark:text-background">
                        <Newspaper class="h-[18px] w-[18px]" aria-hidden="true" />
                    </span>
                    Hasil
                    <span class="angka rounded-full bg-background/70 px-2 py-0.5 text-xs font-medium text-muted-foreground">
                        {{ formatAngka(artikel.total) }}
                    </span>
                </CardTitle>

                <p v-if="artikel.total > 0" class="angka text-xs text-muted-foreground">
                    Menampilkan {{ formatAngka(artikel.from) }} sampai {{ formatAngka(artikel.to) }}
                </p>
            </CardHeader>

            <CardContent class="pt-2">
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
            </CardContent>
        </Card>
    </LayoutEksekutif>
</template>
