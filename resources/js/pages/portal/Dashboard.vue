<script setup lang="ts">
import KartuArtikel from '@/components/domain/KartuArtikel.vue';
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Button } from '@/components/ui/button';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutPortal from '@/layouts/LayoutPortal.vue';
import { Head, Link } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { Plus } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    /** Nama media pengguna. Null kalau akunnya belum ditautkan ke media mana pun. */
    media: string | null;
    periode: { dari: string; sampai: string };
    kpi: { berita_30_hari: number; ditambahkan_sendiri: number; sedang_diproses: number };
    beritaTerbaru: {
        id: number;
        judul: string;
        url: string;
        media: string | null;
        diambil_at: string;
        ditambahkan_sendiri: boolean;
        tahap: 'tampil' | 'diproses' | 'di_luar_pantauan' | 'gagal';
    }[];
}>();

const { formatAngka } = useFormatAngka();

const judul = computed(() => props.media ?? 'Portal media');

const rentang = computed(
    () =>
        `${format(new Date(props.periode.dari), 'd MMM', { locale: id })} sampai ` +
        `${format(new Date(props.periode.sampai), 'd MMM yyyy', { locale: id })}`,
);

/**
 * Tiga angka beserta jendela waktunya masing-masing.
 *
 * Keterangan jendela menempel pada angkanya, bukan pada judul stripnya, karena
 * yang ketiga memang tidak mengikuti jendela yang sama. Satu judul "30 hari
 * terakhir" di atas ketiganya akan salah untuk salah satunya, dan angka yang
 * keterangannya salah lebih buruk daripada angka yang tidak ada.
 */
const statistik = computed(() => [
    { label: 'Berita terpantau', nilai: props.kpi.berita_30_hari, keterangan: '30 hari terakhir' },
    { label: 'Anda tambahkan', nilai: props.kpi.ditambahkan_sendiri, keterangan: '30 hari terakhir' },
    { label: 'Sedang diproses', nilai: props.kpi.sedang_diproses, keterangan: 'Belum selesai dinilai' },
]);
</script>

<template>
    <Head title="Portal media" />

    <!--
        Kop halaman diberi remah jejak, sebelumnya kosong sama sekali.

        AppSidebarHeader tidak menggambar apa pun saat remahnya kosong, jadi
        beranda portal menyisakan satu batang putih setinggi 64 piksel yang
        hanya berisi tombol lipat sidebar. Halaman lain di aplikasi ini selalu
        mengisinya, dan batang kosong itu terbaca sebagai halaman yang belum
        selesai dibuat.

        Remah pertama menyebut panelnya, bukan langsung nama halaman. Satu
        aplikasi ini menampung tiga panel dengan tiga peran, dan menyebut yang
        mana yang sedang dibuka menjawab pertanyaan yang lebih sering muncul
        daripada "saya ada di halaman apa".
    -->
    <LayoutPortal
        lebar="sedang"
        :breadcrumbs="[
            { title: 'Portal media', href: '/portal' },
            { title: 'Beranda', href: '/portal' },
        ]"
    >
        <div class="space-y-6">
            <!--
                Kop navy, satu bidang yang mengerjakan tiga hal sekaligus: menyebut
                media siapa yang sedang dilihat, menaruh ketiga angkanya, dan
                menyodorkan satu-satunya aksi yang dipunyai peran ini.

                Bukan tiga kartu berjajar. Ketiga angka ini dibaca bersama sebagai
                satu keadaan, dan memisahkannya ke dalam tiga kotak bertepi justru
                menyarankan tiga hal yang berdiri sendiri. Garis rambut sudah cukup
                memisahkan, dan sisanya menjadi ruang.
            -->
            <section class="muncul relative overflow-hidden rounded-lg bg-[color:var(--color-brand)] text-white">
                <!--
                    Satu sapuan cahaya dari kanan atas, bukan pola berulang. Bidang
                    navy rata terlihat murah pada layar lebar, sedangkan pola halus
                    akan bersaing dengan tepi angka di bawahnya.
                -->
                <div
                    class="pointer-events-none absolute inset-0 bg-[radial-gradient(38rem_22rem_at_88%_-35%,hsl(0_0%_100%/0.18),transparent_70%)]"
                    aria-hidden="true"
                ></div>

                <div class="relative space-y-5 p-5 sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
                        <div class="min-w-0">
                            <h1 class="truncate text-xl font-semibold tracking-tight sm:text-2xl">{{ judul }}</h1>
                            <p class="mt-1.5 max-w-[60ch] text-sm leading-relaxed text-white/75">
                                Berita media Anda yang masuk pantauan Pemerintah Kota Kendari, {{ rentang }}.
                            </p>
                        </div>

                        <Button as-child size="sm" class="tekan shrink-0 bg-white text-[color:var(--color-brand)] shadow-none hover:bg-white/90">
                            <Link href="/portal/lapor">
                                <Plus class="mr-1.5 h-4 w-4" aria-hidden="true" />
                                Tambah berita terlewat
                            </Link>
                        </Button>
                    </div>

                    <dl class="grid grid-cols-3 divide-x divide-white/20 border-t border-white/20 pt-4">
                        <div v-for="s in statistik" :key="s.label" class="px-3 first:pl-0 last:pr-0 sm:px-5">
                            <dt class="text-xs font-medium text-white/75">{{ s.label }}</dt>
                            <dd>
                                <span class="angka mt-1 block text-2xl font-semibold leading-none sm:text-3xl">
                                    {{ formatAngka(s.nilai) }}
                                </span>
                                <span class="mt-1.5 block text-[11px] leading-tight text-white/65">{{ s.keterangan }}</span>
                            </dd>
                        </div>
                    </dl>
                </div>
            </section>

            <!--
                Daftar berita, bukan kartu berisi daftar. Ini isi utama halaman, dan
                membungkusnya dalam kotak bertepi hanya menambah satu lapisan yang
                harus dibaca sebelum sampai ke barisnya.
            -->
            <section>
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <h2 class="text-base font-semibold tracking-tight">Berita terbaru</h2>
                    <Link href="/portal/berita" class="text-sm font-medium text-primary underline-offset-4 hover:underline">
                        Lihat semua berita
                    </Link>
                </div>
                <p class="mb-3 mt-1 max-w-[70ch] text-sm leading-relaxed text-muted-foreground">
                    Berita temuan sistem muncul setelah selesai dinilai, sedangkan berita yang Anda tambahkan sendiri muncul sejak dikirim, apa pun
                    tahapnya. Hanya yang berlencana "Tampil" yang ikut terhitung di Berita saya.
                </p>

                <KeadaanKosong
                    v-if="!props.beritaTerbaru.length"
                    class="rounded-lg border border-dashed"
                    judul="Belum ada berita dari media Anda"
                    keterangan="Sistem menyisir RSS media Anda setiap tiga jam, lalu menilai tiap berita. Kalau berita Anda tetap tidak muncul, tambahkan sendiri lewat tombol di atas."
                />

                <!--
                    Tanpa animasi masuk, dan itu keputusan, bukan kelalaian.
                    Kelas `muncul` mulai dari opacity nol, jadi baris yang
                    diberi jeda bertahap terbaca sebagai teks pudar selama hampir
                    satu detik. Pada halaman yang dibuka untuk membaca judul,
                    yang dibayar dengan kepudaran itu tidak sebanding. Satu
                    momen masuk sudah dikerjakan kop di atas.
                -->
                <ul v-else class="-mx-3 border-y border-border">
                    <li
                        v-for="b in props.beritaTerbaru"
                        :key="b.id"
                        class="border-t border-border px-3 py-1 transition-colors first:border-t-0 hover:bg-muted/60"
                    >
                        <!-- tampilkanSentimen sengaja tidak dipasang. Portal
                             media tidak pernah menampilkan nada berita. Tahap
                             dan ditambahkanSendiri bukan sentimen: yang pertama
                             menyebut relevansi, yang kedua menandai asal baris,
                             dan keduanya bukan penilaian atas nada isinya. -->
                        <KartuArtikel
                            :judul="b.judul"
                            :url="b.url"
                            :media="b.media"
                            :diambil-at="b.diambil_at"
                            :ditambahkan-sendiri="b.ditambahkan_sendiri"
                            :tahap="b.tahap"
                        />
                    </li>
                </ul>
            </section>

            <!--
                Penutup halaman, dan sekaligus jawaban atas satu-satunya pertanyaan
                yang membuat peran ini membuka portal: kenapa berita saya tidak ada
                di sini. Sebelumnya jawabannya hanya muncul di keadaan kosong,
                padahal justru media yang sudah punya beberapa berita terpantau yang
                paling sering menyadari ada yang hilang.
            -->
            <section class="rounded-lg border bg-muted/40 p-5">
                <h2 class="text-sm font-semibold">Berita Anda tidak muncul di daftar?</h2>
                <p class="mt-2 max-w-[65ch] text-sm leading-relaxed text-muted-foreground">
                    Sistem menyisir RSS media Anda setiap tiga jam, lalu menilai tiap berita. Berita yang baru terbit umumnya masuk dalam dua jam.
                    Lewat dari itu dan tetap tidak muncul, tempel tautannya dan sistem akan memeriksa halamannya langsung. Berita yang Anda tambahkan
                    masuk antrean penilaian yang sama dengan berita temuan sistem, dan tahapnya terbaca di daftar di atas.
                </p>
                <Button as-child size="sm" variant="outline" class="tekan mt-4">
                    <Link href="/portal/lapor">
                        <Plus class="mr-1.5 h-4 w-4" aria-hidden="true" />
                        Tambah berita terlewat
                    </Link>
                </Button>
            </section>
        </div>
    </LayoutPortal>
</template>
