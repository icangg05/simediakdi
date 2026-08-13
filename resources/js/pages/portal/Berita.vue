<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import BadgeTahapPortal from '@/components/domain/BadgeTahapPortal.vue';
import KopHalaman from '@/components/domain/KopHalaman.vue';
import PemilihRentangTanggal from '@/components/domain/PemilihRentangTanggal.vue';
import PilKop from '@/components/domain/PilKop.vue';
import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import { useFilterTabel } from '@/composables/useFilterTabel';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutPortal from '@/layouts/LayoutPortal.vue';
import { cn } from '@/lib/utils';
import type { FilterDefinisi, KolomDefinisi, PaginasiMeta } from '@/types/tabel';
import { Head, Link } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { CalendarRange, ExternalLink, Newspaper, Plus, PlusCircle } from 'lucide-vue-next';
import { computed } from 'vue';

interface Baris {
    id: number;
    judul: string;
    url: string;
    diambil_at: string;
    dipublikasikan_at: string | null;
    jumlah_kata: number | null;
    ditambahkan_sendiri: boolean;
    tahap: 'tampil' | 'diproses' | 'di_luar_pantauan' | 'gagal';
}

const props = defineProps<{
    artikel: { data: Baris[] } & PaginasiMeta;
    periode: { dari: string; sampai: string };
}>();

const { formatAngka } = useFormatAngka();

// Instance sendiri, bukan yang di dalam DataTable. `kunjungi` menggabungkan
// perubahan tanggal ke query string yang sedang berlaku, jadi pencarian dan
// urutan yang sudah dipilih tidak ikut hilang saat rentangnya diganti.
const { kunjungi } = useFilterTabel('/portal/berita');

const kolom: KolomDefinisi[] = [
    { kunci: 'judul', judul: 'Judul', bisaDiurutkan: true },
    { kunci: 'sumber', judul: 'Asal', lebar: 'w-36' },
    { kunci: 'tahap', judul: 'Tahap', lebar: 'w-40' },
    { kunci: 'diambil_at', judul: 'Terpantau', bisaDiurutkan: true, lebar: 'w-40' },
    { kunci: 'jumlah_kata', judul: 'Kata', kelas: 'angka text-right', lebar: 'w-20' },
];

/**
 * Saringan asal baris, pasangan dari kolom "Asal" di tabelnya.
 *
 * Nilainya `crawler` dan `portal`, sama persis dengan filter asal di panel
 * admin, sedangkan labelnya ditulis dari sudut pandang media. Media tidak
 * memakai kata "crawler", dan admin tidak menyebut kirimannya "Anda tambahkan".
 *
 * Gunanya bukan sekadar menyaring. Proporsi kiriman mandiri yang tinggi adalah
 * petunjuk bahwa sumber feed media itu perlu diperiksa, dan tanpa saringan ini
 * media tidak punya cara menghitungnya selain memindai tabel baris per baris.
 */
const filter: FilterDefinisi[] = [
    {
        kunci: 'asal',
        label: 'Asal',
        opsi: [
            { nilai: 'crawler', label: 'Ditemukan otomatis' },
            { nilai: 'portal', label: 'Anda tambahkan' },
        ],
    },
];

const rentang = computed(
    () =>
        `${format(new Date(props.periode.dari), 'd MMM', { locale: id })} sampai ` +
        `${format(new Date(props.periode.sampai), 'd MMM yyyy', { locale: id })}`,
);

const waktu = (n: string) => format(new Date(n), 'd MMM yyyy, HH:mm', { locale: id });

/*
 * Tombol satu-satunya di halaman ini, dan warnanya navy karena ia aksi utama
 * peran media. Ia diletakkan di kop, bukan di dekat tabel, karena tabel ini
 * halaman baca dan tombol yang berdiri di antara saringan dan hasilnya akan
 * terbaca sebagai saringan lain.
 */
const TOMBOL_KOP = cn(
    buttonVariants({ size: 'sm' }),
    'gap-1.5 bg-white text-brand shadow-xs shadow-black/10 hover:bg-white/90 focus-visible:ring-offset-brand',
);
</script>

<template>
    <Head title="Berita saya" />

    <!--
        `lebar` dinaikkan ke sedang, sebelumnya sempit.

        Halaman ini memuat tabel berkolom lima, dan max-w-3xl memaksa kolom
        Judul menyempit sampai hampir setiap baris terpotong dua baris.
        Halaman satu tugas memang pantas sempit, tetapi tabel penyaring bukan
        halaman satu tugas.
    -->
    <LayoutPortal
        lebar="sedang"
        :breadcrumbs="[
            { title: 'Portal media', href: '/portal' },
            { title: 'Berita saya', href: '/portal/berita' },
        ]"
    >
        <!--
            Kop navy bersama, sama dengan beranda portal dan seluruh panel admin.
            Sebelumnya halaman ini hanya mencetak satu h1 polos di atas paragraf
            abu, dan bersebelahan dengan beranda yang berkop navy ia terbaca
            sebagai halaman dari aplikasi yang berbeda.
        -->
        <KopHalaman
            judul="Berita saya"
            keterangan="Berita temuan sistem muncul setelah dinilai masuk pantauan Pemkot Kendari, sedangkan berita yang Anda tambahkan sendiri selalu ada di sini beserta tahapnya. Disaring menurut tanggal terbit, bukan tanggal berita itu terpantau."
        >
            <template #aksi>
                <Link href="/portal/lapor" :class="TOMBOL_KOP">
                    <Plus class="size-4" aria-hidden="true" />
                    Tambah berita terlewat
                </Link>
            </template>

            <PilKop :ikon="CalendarRange">{{ rentang }}</PilKop>
            <PilKop :ikon="Newspaper">{{ formatAngka(props.artikel.total) }} berita pada rentang ini</PilKop>
        </KopHalaman>

        <DataTable
            :kolom="kolom"
            :data="props.artikel.data"
            :meta="props.artikel"
            :filter="filter"
            pencarian
            url-basis="/portal/berita"
            judul-kosong="Tidak ada berita pada rentang ini"
            keterangan-kosong="Coba perlebar rentang tanggalnya. Kalau berita Anda memang tidak pernah muncul, tambahkan sendiri lewat halaman Tambah berita."
        >
            <!-- Rentang tanggal berdiri sejajar dengan pencarian dan filter
                 asal, tidak disembunyikan di balik sheet. Halaman ini memang
                 halaman penyaring, dan menutupi hasilnya dengan lapisan membuat
                 pengguna membuka-tutup lapisan itu hanya untuk melihat akibat
                 pilihannya.

                 `tanpa-sheet` yang mewujudkan itu, dan sebelumnya memang
                 tertinggal: `inline` hanya memindahkan pintasan periode ke
                 header, sedangkan kedua kotak tanggalnya tetap di dalam sheet.
                 Halaman arsip eksekutif sudah memakai keduanya, dan halaman ini
                 sekarang menyusul. -->
            <template #aksi>
                <PemilihRentangTanggal
                    :dari="props.periode.dari"
                    :sampai="props.periode.sampai"
                    inline
                    tanpa-sheet
                    @ubah="(dari, sampai) => kunjungi({ dari, sampai })"
                />
            </template>

            <template #sel-judul="{ baris }">
                <a
                    :href="baris.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="group inline-flex items-start gap-1 font-medium hover:underline"
                >
                    <span class="line-clamp-2">{{ baris.judul }}</span>
                    <ExternalLink
                        class="mt-0.5 size-3 shrink-0 text-muted-foreground transition-transform duration-150 group-hover:translate-x-px group-hover:-translate-y-px group-hover:text-brand dark:group-hover:text-brand-terang"
                        aria-hidden="true"
                    />
                </a>
            </template>

            <!-- Penanda asal baris, bukan penilaian atas isinya, jadi ia tidak
                 melanggar aturan bahwa portal tidak menampilkan sentimen.
                 Gunanya: media bisa melihat berapa banyak beritanya yang hanya
                 masuk karena dikirim sendiri, dan itu petunjuk bahwa sumber
                 feed mereka perlu diperiksa.

                 Kiriman mandiri diberi ikon dan tepi putus-putus, bukan warna.
                 Rona apa pun di kolom ini akan bersaing dengan kolom Tahap di
                 sebelahnya, dan yang perlu terbaca lebih dulu adalah tahapnya. -->
            <template #sel-sumber="{ baris }">
                <Badge v-if="baris.ditambahkan_sendiri" variant="outline" class="gap-1 border-dashed font-normal">
                    <PlusCircle class="size-3 shrink-0" aria-hidden="true" />
                    Anda tambahkan
                </Badge>
                <Badge v-else variant="secondary" class="font-normal">Otomatis</Badge>
            </template>

            <!-- Kolom ini yang membuat selisih dengan KPI beranda bisa dibaca,
                 bukan ditebak. Berita temuan sistem di sini selalu berlencana
                 "Tampil"; yang berlencana lain pasti kiriman media sendiri. -->
            <template #sel-tahap="{ baris }">
                <BadgeTahapPortal :tahap="baris.tahap" />
            </template>

            <template #sel-diambil_at="{ baris }">
                <span class="text-xs text-muted-foreground">{{ waktu(baris.diambil_at) }}</span>
            </template>
        </DataTable>
    </LayoutPortal>
</template>
