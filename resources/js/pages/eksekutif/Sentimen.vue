<script setup lang="ts">
import ChartDonatSentimen from '@/components/chart/ChartDonatSentimen.vue';
import ChartTrenSentimen from '@/components/chart/ChartTrenSentimen.vue';
import KartuArtikel from '@/components/domain/KartuArtikel.vue';
import KartuEksekutif from '@/components/domain/KartuEksekutif.vue';
import KopEksekutif from '@/components/domain/KopEksekutif.vue';
import PemilihRentangTanggal from '@/components/domain/PemilihRentangTanggal.vue';
import PilKop from '@/components/domain/PilKop.vue';
import SentimenBelumTersedia from '@/components/domain/SentimenBelumTersedia.vue';
import TautanTujuan from '@/components/domain/TautanTujuan.vue';
import { Card, CardContent } from '@/components/ui/card';
import { useFormatAngka } from '@/composables/useFormatAngka';
import { useGerbangSentimen } from '@/composables/useGerbangSentimen';
import { usePeriodeEksekutif } from '@/composables/usePeriodeEksekutif';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import type { DeretTren } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { ArrowRight, Info, Newspaper, ThumbsUp, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';

/*
 * Arti warna di halaman ini sama persis dengan dashboard, dan itu wajib.
 * Pimpinan berpindah antara keduanya lewat menu di kop, dan hijau yang berarti
 * nada positif di satu halaman lalu berarti hal lain di halaman sebelahnya
 * membuat seluruh sistem warnanya berhenti bisa dipercaya. Tabelnya ada di
 * `KartuEksekutif` dan `TautanTujuan`.
 */

interface Berita {
    id: number;
    judul: string;
    url: string;
    media: string | null;
    diambil_at: string;
    /** Alasan model atas label yang diberikannya. Kosong pada baris analisis lama. */
    ringkasan_ai: string | null;
}

const props = defineProps<{
    periode: { dari: string; sampai: string };
    kpi: {
        berlabel: number;
        negatif: number;
        netral: number;
        positif: number;
        negatif_persen: number;
    };
    deret: DeretTren;
    beritaNegatif: Berita[];
    beritaNetral: Berita[];
    beritaPositif: Berita[];
}>();

const { formatAngka, formatPersen } = useFormatAngka();
const { sentimenTersedia, alasanSentimen } = useGerbangSentimen();
const { pindah, kueri } = usePeriodeEksekutif(props.periode, '/eksekutif/sentimen');

/**
 * Rentang dalam kalimat, bukan dua tanggal ISO.
 *
 * Disalin bentuknya dari dashboard supaya kop kedua halaman terbaca sama.
 * Pimpinan berpindah antara keduanya lewat menu di kop, dan judul yang
 * bentuknya berbeda membuat perpindahan itu terasa seperti masuk aplikasi lain.
 */
const rentangTerbaca = computed(
    () =>
        `${format(new Date(props.periode.dari), 'd MMMM', { locale: id })} sampai ` +
        `${format(new Date(props.periode.sampai), 'd MMMM yyyy', { locale: id })}`,
);

/**
 * Legenda donat, dipindah keluar dari grafik menjadi daftar yang bisa diklik.
 *
 * Legenda bawaan ECharts hanya teks mati di bawah lingkaran, dan angkanya harus
 * dicari dengan menyorot potongannya satu per satu. Sebagai daftar, tiap nada
 * langsung menyebutkan jumlah dan porsinya, lalu membuka arsip yang sudah
 * tersaring ke nada itu. Itu memenuhi prinsip produk nomor dua: tiap angka di
 * layar punya jalan untuk dicek asalnya.
 */
const nada = computed(() =>
    (
        [
            {
                kunci: 'positif',
                nama: 'Positif',
                arti: 'memberitakan hal baik',
                jumlah: props.kpi.positif,
                titik: 'bg-sentimen-positif',
                teks: 'text-sentimen-positif',
            },
            {
                kunci: 'netral',
                nama: 'Netral',
                arti: 'menyampaikan informasi',
                jumlah: props.kpi.netral,
                titik: 'bg-sentimen-netral',
                teks: 'text-sentimen-netral',
            },
            {
                kunci: 'negatif',
                nama: 'Negatif',
                arti: 'menyoroti masalah atau kritik',
                jumlah: props.kpi.negatif,
                titik: 'bg-sentimen-negatif',
                teks: 'text-sentimen-negatif',
            },
        ] as const
    ).filter((n) => n.jumlah > 0),
);

/**
 * Penyebutnya berita berlabel, penjumlahan tiga nada itu saja. Angka yang sama
 * dipakai donat di sebelahnya, jadi porsi di daftar ini selalu cocok dengan
 * besar potongan yang dilihat pembaca.
 */
function porsi(jumlah: number): number {
    return props.kpi.berlabel === 0 ? 0 : Math.round((jumlah / props.kpi.berlabel) * 100);
}

/**
 * Nada pil porsi negatif di kop.
 *
 * Ambang dua puluh lima persen sama dengan yang dipakai dashboard untuk
 * menyimpulkan "perlu perhatian". Dua halaman yang menyimpulkan hal berbeda
 * dari angka yang sama adalah keadaan yang paling cepat membuat pembaca
 * berhenti mempercayai keduanya.
 */
const nadaPilNegatif = computed(() => (props.kpi.negatif_persen >= 25 ? ('buruk' as const) : ('netral' as const)));

/**
 * Tiga daftar berita, satu per nada.
 *
 * Disusun sebagai data, bukan tiga blok markup yang saling menyalin. Ketiganya
 * berbeda hanya pada warna, judul, dan sumber datanya, dan menuliskannya tiga
 * kali berarti perbaikan pada satu kartu cepat atau lambat lupa diterapkan ke
 * dua lainnya.
 *
 * Urutannya positif, netral, negatif, mengikuti urutan yang sama dengan batang
 * komposisi dan legenda donat di halaman ini. Pembaca yang berpindah dari
 * grafik ke daftar menemukan nada pada posisi yang sama.
 */
const daftarNada = computed(() => [
    {
        kunci: 'positif' as const,
        judul: 'Berita bernada positif',
        catatan: 'Media memberitakan hal baik tentang Pemerintah Kota',
        ikon: ThumbsUp,
        berita: props.beritaPositif,
        tile: 'bg-sentimen-positif',
        rel: 'from-sentimen-positif/40',
        jumlah: props.kpi.positif,
        kosong: 'Tidak ada berita bernada positif pada rentang ini.',
    },
    {
        kunci: 'netral' as const,
        judul: 'Berita bernada netral',
        catatan: 'Media menyampaikan informasi tanpa menilai',
        ikon: Info,
        berita: props.beritaNetral,
        tile: 'bg-sentimen-netral',
        rel: 'from-sentimen-netral/40',
        jumlah: props.kpi.netral,
        kosong: 'Tidak ada berita bernada netral pada rentang ini.',
    },
    {
        kunci: 'negatif' as const,
        judul: 'Berita bernada negatif',
        catatan: 'Media menyoroti masalah atau menyampaikan kritik',
        ikon: TriangleAlert,
        berita: props.beritaNegatif,
        tile: 'bg-sentimen-negatif',
        rel: 'from-sentimen-negatif/40',
        jumlah: props.kpi.negatif,
        kosong: 'Tidak ada berita bernada negatif pada rentang ini.',
    },
]);
</script>

<template>
    <Head title="Analisis sentimen" />

    <LayoutEksekutif>
        <KopEksekutif judul="Analisis sentimen" :keterangan="`Rincian nada pemberitaan tentang Pemerintah Kota, ${rentangTerbaca}`">
            <template #kendali>
                <PemilihRentangTanggal :dari="periode.dari" :sampai="periode.sampai" inline @ubah="(dari, sampai) => pindah({ dari, sampai })" />
            </template>

            <template v-if="sentimenTersedia" #pil>
                <PilKop :ikon="Newspaper">{{ formatAngka(kpi.berlabel) }} berita berlabel</PilKop>
                <PilKop :nada="nadaPilNegatif" :ikon="TriangleAlert">{{ formatPersen(kpi.negatif_persen) }} bernada negatif</PilKop>
            </template>
        </KopEksekutif>

        <SentimenBelumTersedia v-if="!sentimenTersedia" :alasan="alasanSentimen" />

        <template v-else>
            <!--
                Dua panel grafik, bobotnya tidak sama. Tren menjawab "berubah ke
                mana", komposisi menjawab "sekarang seperti apa", dan pertanyaan
                pertama yang lebih sering dibawa pembaca ke halaman ini. Karena
                itu tren mendapat dua kolom dan komposisi satu.
            -->
            <div class="grid gap-4 lg:grid-cols-3">
                <!--
                    Kedua kartu grafik tidak memakai kepala berjudul seperti tiga
                    kartu daftar di bawahnya. Judulnya sudah dicetak BaseChart
                    sebaris dengan lencana "lihat sebagai tabel" dan "unduh", dan
                    lencana pertama itu satu-satunya cara pembaca layar membaca
                    grafiknya, jadi ia tidak boleh dipindah. Perbedaan bentuk itu
                    sekaligus memberi halaman irama: dua panel analisis di atas,
                    tiga panel daftar di bawah.

                    Yang tetap dipasang garis rona di tepi atasnya, supaya kedua
                    kartu ini tidak menjadi satu-satunya yang keluar dari
                    keluarga bentuk panel eksekutif.
                -->
                <Card class="muncul relative overflow-hidden lg:col-span-2" style="animation-delay: 60ms">
                    <div
                        class="tumbuh pointer-events-none absolute inset-x-0 top-0 h-[2px] bg-gradient-to-r from-aksen-biru to-transparent"
                        aria-hidden="true"
                    ></div>

                    <CardContent class="space-y-3 p-4 pt-5">
                        <ChartTrenSentimen judul="Perubahan dari waktu ke waktu" :data="deret.baris as never" :satuan="deret.satuan" :tinggi="280" />
                        <p class="text-xs leading-relaxed text-muted-foreground">
                            Warnanya ditumpuk, jadi tinggi seluruh tumpukan berarti jumlah berita berlabel pada titik itu.
                        </p>
                    </CardContent>
                </Card>

                <Card class="muncul relative overflow-hidden" style="animation-delay: 120ms">
                    <!--
                        Garis kepala kartu ini tiga warna sekaligus, bukan satu
                        rona aksen. Isinya memang pembagian ketiga nada, dan
                        garis yang membawa ketiganya menyatakan itu sebelum
                        donatnya sempat dibaca.
                    -->
                    <div
                        class="tumbuh pointer-events-none absolute inset-x-0 top-0 h-[2px] bg-gradient-to-r from-sentimen-positif via-sentimen-netral to-sentimen-negatif"
                        aria-hidden="true"
                    ></div>

                    <CardContent class="space-y-3 p-4 pt-5">
                        <!--
                            Tanpa judul tambahan di sini. ChartDonatSentimen
                            sudah mencetak judulnya sendiri lewat BaseChart,
                            sebaris dengan tombol tabel dan unduh, dan menambah
                            kepala di atasnya menghasilkan dua judul untuk satu
                            grafik yang sama. Itu persis yang terjadi pada
                            percobaan pertama halaman ini.
                        -->
                        <ChartDonatSentimen :negatif="kpi.negatif" :netral="kpi.netral" :positif="kpi.positif" :tinggi="220" />

                        <!--
                            Legenda yang bisa dibuka, menggantikan legenda mati
                            bawaan grafik. Tiap baris membawa jumlah dan porsinya
                            sendiri, jadi angkanya tidak perlu dicari dengan
                            menyorot potongan donat satu per satu.

                            Angkanya diberi warna nadanya. Baris ini satu-satunya
                            tempat di halaman yang menyandingkan ketiga angka
                            berdampingan, dan warna pada angkanya yang membuat
                            baris mana yang sedang dibaca tidak perlu dicocokkan
                            ke titik di tepi kiri.
                        -->
                        <ul v-if="nada.length" class="divide-y overflow-hidden rounded-xl border">
                            <li v-for="(n, urutan) in nada" :key="n.kunci" class="muncul" :style="{ animationDelay: `${200 + urutan * 80}ms` }">
                                <Link
                                    :href="`/eksekutif/berita?${kueri({ sentimen: n.kunci })}`"
                                    class="tekan group flex items-center gap-3 px-3 py-2.5 hover:bg-muted"
                                >
                                    <span :class="n.titik" class="size-2.5 shrink-0 rounded-full" aria-hidden="true"></span>

                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium">{{ n.nama }}</span>
                                        <span class="block truncate text-xs text-muted-foreground">{{ n.arti }}</span>
                                    </span>

                                    <span class="shrink-0 text-right">
                                        <span :class="n.teks" class="angka block text-sm font-semibold leading-tight">
                                            {{ formatAngka(n.jumlah) }}
                                        </span>
                                        <span class="angka block text-xs text-muted-foreground">{{ porsi(n.jumlah) }} dari 100</span>
                                    </span>

                                    <ArrowRight
                                        class="ease-[cubic-bezier(0.32,0.72,0,1)] size-4 shrink-0 text-muted-foreground transition-transform duration-300 group-hover:translate-x-1"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </li>
                        </ul>

                        <p v-else class="rounded-xl border border-dashed px-3 py-6 text-center text-sm text-muted-foreground">
                            Belum ada berita yang selesai dianalisis pada rentang ini.
                        </p>
                    </CardContent>
                </Card>
            </div>

            <!--
                Tiga baris, satu per nada, masing-masing selebar halaman.

                Sebelumnya ketiga nada berdampingan sebagai tiga kolom sempit,
                dan pada lebar itu ringkasan model terpotong di baris kedua
                sehingga kalimatnya berhenti sebelum menjelaskan apa pun. Satu
                nada satu baris memberi tiap berita selebar halaman, dan
                ringkasannya utuh.

                Seluruh kartu diberi warna nada isinya, bukan hanya kepalanya,
                supaya tiga blok yang bertumpuk tidak terbaca sebagai satu daftar
                panjang.
            -->
            <div v-for="(d, urutan) in daftarNada" :key="d.kunci" class="muncul" :style="{ animationDelay: `${180 + urutan * 60}ms` }">
                <KartuEksekutif :judul="d.judul" :catatan="d.catatan" :ikon="d.ikon" :rona="d.kunci" bertinta>
                    <!--
                        Hitungan di lencana adalah jumlah seluruh berita bernada
                        itu pada rentangnya, bukan jumlah baris yang tampil.
                        Daftarnya dibatasi sepuluh, dan tanpa angka ini pembaca
                        akan mengira sepuluh itulah seluruhnya.
                    -->
                    <template v-if="d.jumlah > 0" #aksi>
                        <span :class="d.tile" class="angka rounded-full px-3 py-1 text-xs font-semibold text-white shadow-sm dark:text-background">
                            {{ formatAngka(d.jumlah) }}
                            <template v-if="d.kunci === 'negatif'">&middot; {{ formatPersen(kpi.negatif_persen) }}</template>
                        </span>
                    </template>

                    <!--
                        Lini masa, bentuk yang sama dengan kartu berita terbaru di
                        dashboard. Rel menurun dengan titik menyatakan urutan
                        waktu sebelum satu keterangan tanggal pun dibaca, dan
                        pembaca yang datang dari dashboard menemukan bentuk yang
                        sudah dikenalnya. Relnya turun dari kepala daftar saat
                        halaman terbuka, arah baca barisnya.

                        Titiknya tidak perlu diberi warna per baris seperti di
                        dashboard. Seluruh isi kartu ini satu nada, dan warnanya
                        sudah dinyatakan kepala kartu, tepi, serta latarnya.
                        Lencana sentimen per baris juga dilepas karena alasan
                        yang sama.

                        Tingginya dibatasi dan sisanya digulir, tetapi hanya mulai
                        lebar sm. Tiga daftar berisi sepuluh berita yang ditumpuk
                        penuh membuat halaman ini panjang sekali di layar lebar,
                        sedangkan yang dicari pembaca biasanya beberapa baris
                        teratas tiap nada.

                        Di ponsel batas itu justru merugikan. Ia menghasilkan tiga
                        wadah gulir di dalam halaman yang juga digulir, dan
                        kartunya selebar layar sehingga ibu jari hampir selalu
                        mendarat di dalam salah satunya. Yang tergulir jadi daftar
                        dalam, bukan halaman, lalu gulirnya berhenti dulu di ujung
                        daftar sebelum diserahkan ke halaman. Jeda serah terima
                        itu yang terbaca sebagai gulir patah-patah. Di ponsel
                        halamannya memang sudah satu kolom panjang, jadi tidak ada
                        yang benar-benar dihemat wadah gulir ini.
                    -->
                    <div v-if="d.berita.length" class="sm:max-h-[22rem] sm:overflow-y-auto sm:pr-1">
                        <ol class="relative">
                            <span
                                :class="d.rel"
                                class="tumbuh-turun absolute bottom-4 left-[5px] top-4 w-px bg-gradient-to-b via-border to-transparent"
                                aria-hidden="true"
                            ></span>

                            <li v-for="b in d.berita" :key="b.id" class="tekan relative rounded-lg py-1 pl-7 pr-2 hover:bg-background/60">
                                <span :class="d.tile" class="absolute left-0 top-[18px] size-2.5 rounded-full" aria-hidden="true"></span>

                                <KartuArtikel
                                    :judul="b.judul"
                                    :url="b.url"
                                    :media="b.media"
                                    :diambil-at="b.diambil_at"
                                    :ringkasan-ai="b.ringkasan_ai"
                                    :label="d.kunci"
                                />
                            </li>
                        </ol>
                    </div>

                    <p v-else class="rounded-xl border border-dashed px-3 py-8 text-center text-sm text-muted-foreground">{{ d.kosong }}</p>

                    <!--
                        Tautan ke arsip hanya muncul kalau memang ada yang tidak
                        tertampung. Tombol "lihat semua" di bawah daftar yang
                        sudah lengkap hanya menambah satu keputusan tanpa
                        menambah satu pun berita.
                    -->
                    <div v-if="d.jumlah > d.berita.length" class="mt-3 flex">
                        <TautanTujuan :href="`/eksekutif/berita?${kueri({ sentimen: d.kunci })}`" :rona="d.kunci" ukuran="sedang" :ikon="Newspaper">
                            <span class="angka">Lihat {{ formatAngka(d.jumlah) }} berita lengkapnya</span>
                        </TautanTujuan>
                    </div>
                </KartuEksekutif>
            </div>
        </template>
    </LayoutEksekutif>
</template>
