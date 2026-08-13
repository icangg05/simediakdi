<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { getInitials } from '@/composables/useInitials';
import { hrefAktif, navPerPeran } from '@/nav';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { useElementVisibility } from '@vueuse/core';
import { ChevronDown, Menu } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Navigasi mendatar untuk panel eksekutif.
 *
 * Dipakai menggantikan sidebar hanya di halaman walikota. Menunya empat dan
 * dilihat di layar besar saat presentasi, dan lebar yang dimakan sidebar lebih
 * berguna untuk grafik. Admin dengan sepuluh menu tetap memakai sidebar.
 *
 * Daftar menunya dibaca dari `@/nav`, sumber yang sama dengan sidebar.
 *
 * Kopnya navy merek, bukan putih. Halaman ini dibuka pimpinan daerah dan sering
 * ditampilkan di ruang rapat, dan pita berwarna di paling atas menyatakan ini
 * panel resmi Pemerintah Kota sebelum satu angka pun dibaca.
 *
 * **Menu di sini sengaja tidak ikut aturan warna per fungsi.** Di dalam isi
 * halaman, warna menyatakan apa yang sedang dibaca: hijau nada positif, toska
 * media, ungu tulisan model. Bilah ini bukan isi, ia perabot yang membawa
 * pengguna ke isi, dan memberi empat menunya empat warna berarti pengguna
 * belajar arti warna dari perabot lalu menemukannya berarti hal lain di
 * halaman. Keadaan menu dinyatakan bentuk dan terang gelap: yang sedang dibuka
 * jadi pil putih penuh, sisanya teks di atas navy.
 */
const page = usePage<SharedData>();
const auth = computed(() => page.props.auth);

const menuTerbuka = ref(false);

/**
 * Bilah tidak lagi melekat di puncak layar, rel mengambang yang menggantikannya.
 *
 * `useElementVisibility` memakai IntersectionObserver, bukan pendengar peristiwa
 * gulir. Bedanya penting di ponsel: pendengar gulir dipanggil tiap frame dan
 * tiap panggilannya membaca posisi elemen, yang memaksa browser menghitung
 * ulang tata letak di tengah gulir. Observer hanya berbunyi dua kali, saat
 * bilahnya keluar layar dan saat masuk lagi.
 *
 * Kelasnya sudah terpasang di proyek dan dipakai `BaseChart` untuk menunda muat
 * grafik, jadi tidak ada kebergantungan baru yang ditambahkan.
 */
const bilah = ref<HTMLElement | null>(null);
const bilahTerlihat = useElementVisibility(bilah);

const mainNavItems = computed(() => navPerPeran[auth.value.user?.peran] ?? []);
const beranda = computed(() => mainNavItems.value[0]?.href ?? '/dashboard');
const aktif = computed(() => hrefAktif(mainNavItems.value, page.url));

/**
 * Keterangan satu baris per menu, hanya dipakai daftar ponsel.
 *
 * Di layar sempit menunya berdiri sendiri sebagai satu halaman penuh, dan di
 * sana ruangnya ada. Empat judul pendek tanpa keterangan memaksa pengguna
 * menebak beda "Sentimen" dan "Arsip Berita", dan tebakan yang salah berarti
 * satu perjalanan bolak balik di koneksi seluler.
 *
 * Ditulis di sini, bukan di `@/nav`, karena hanya berlaku untuk panel ini.
 * Sidebar admin menampilkan sepuluh menu berkelompok dan tidak punya ruang
 * untuk satu baris keterangan di tiap barisnya.
 */
const KETERANGAN: Record<string, string> = {
    '/eksekutif': 'Kondisi pemberitaan hari ini',
    '/eksekutif/sentimen': 'Rincian nada pemberitaan',
    '/eksekutif/media': 'Siapa yang paling banyak menulis',
    '/eksekutif/berita': 'Cari dan saring seluruh berita',
};
</script>

<template>
    <!--
        Bilah ini ikut tergulir dan tidak melekat di puncak layar.

        Bilah melekat berarti satu bidang yang harus digambar ulang di atas
        seluruh isi yang lewat di belakangnya sepanjang gulir, dan di ponsel itu
        biaya yang dibayar terus menerus demi empat menu yang jarang ditekan
        selagi membaca. Yang menggantikannya rel mengambang di bawah, yang baru
        dipasang setelah bilahnya benar benar keluar layar.

        Latarnya tetap pekat penuh, tanpa `backdrop-blur-sm`. Blur memaksa
        kompositor merasterisasi ulang seluruh bidang di belakangnya, dan
        biayanya melonjak saat yang lewat di belakang adalah kanvas grafik,
        karena kanvas berada di lapisan terpisah yang membatalkan cache blur
        terus menerus. Di atas latar 95 persen pekat efek blurnya sendiri hampir
        tidak terlihat, jadi yang hilang nyaris nol.
    -->
    <div ref="bilah" class="relative z-40 bg-brand text-white">
        <!-- Sapuan cahaya di sudut kanan, senada dengan kop halaman di bawahnya. -->
        <div
            class="pointer-events-none absolute inset-0"
            aria-hidden="true"
            style="background: radial-gradient(28rem 10rem at 92% -60%, rgb(255 255 255 / 0.14), transparent 70%)"
        ></div>

        <!--
            Lompat ke isi, tautan pertama yang ditemukan papan ketik.
            Tersembunyi sampai difokus. Tanpa ini pengguna papan ketik menekan
            Tab melewati seluruh menu setiap kali berpindah halaman.
        -->
        <a
            href="#isi"
            class="sr-only top-3 left-4 z-50 rounded-full bg-white px-4 py-2 text-sm font-semibold text-brand shadow-lg focus:not-sr-only focus:absolute"
        >
            Lompat ke isi halaman
        </a>

        <div class="relative mx-auto flex h-16 w-full max-w-[1400px] items-center gap-2 px-4 md:px-6">
            <!-- Menu ponsel. Empat menu tidak muat mendatar di layar sempit. -->
            <Sheet v-model:open="menuTerbuka">
                <SheetTrigger :as-child="true">
                    <Button variant="ghost" size="icon" class="tekan size-9 text-white hover:bg-white/15 hover:text-white lg:hidden">
                        <Menu class="size-5" />
                        <span class="sr-only">Buka menu</span>
                    </Button>
                </SheetTrigger>
                <SheetContent side="left" class="w-[320px] p-6">
                    <SheetHeader class="text-left">
                        <SheetTitle class="flex items-center gap-2.5">
                            <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-brand">
                                <img src="/img/Lambang_Kota_Kendari.webp" alt="" class="size-6 object-contain" width="24" height="24" />
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm leading-tight">Pemerintah Kota Kendari</span>
                                <span class="block truncate text-xs font-normal text-muted-foreground">Pemantauan pemberitaan media</span>
                            </span>
                        </SheetTitle>
                    </SheetHeader>

                    <!--
                        Baris menu ponsel dibuat setinggi jempol dan diberi
                        keterangan. Yang sedang dibuka memakai pita navy di tepi
                        kiri, bukan hanya latar abu: latar abu di ponsel murah
                        yang layarnya pudar nyaris tidak terbaca, sedangkan pita
                        menyatakan keadaan lewat bentuk.
                    -->
                    <nav class="-mx-2 mt-6 space-y-1" aria-label="Menu utama">
                        <Link
                            v-for="item in mainNavItems"
                            :key="item.title"
                            :href="item.href"
                            :aria-current="item.href === aktif ? 'page' : undefined"
                            class="tekan relative flex items-center gap-3 overflow-hidden rounded-xl px-3 py-2.5"
                            :class="item.href === aktif ? 'bg-brand-lembut' : 'hover:bg-muted'"
                            @click="menuTerbuka = false"
                        >
                            <span v-if="item.href === aktif" class="absolute inset-y-1.5 left-0 w-1 rounded-full bg-brand" aria-hidden="true"></span>

                            <span
                                class="grid size-9 shrink-0 place-items-center rounded-lg"
                                :class="item.href === aktif ? 'bg-brand text-white' : 'bg-muted text-muted-foreground'"
                            >
                                <component :is="item.icon" v-if="item.icon" class="size-[18px]" aria-hidden="true" />
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold">{{ item.title }}</span>
                                <span class="block truncate text-xs text-muted-foreground">{{ KETERANGAN[item.href] }}</span>
                            </span>
                        </Link>
                    </nav>
                </SheetContent>
            </Sheet>

            <Link :href="beranda" class="tekan flex min-w-0 items-center rounded-lg">
                <AppLogo />
            </Link>

            <!--
                Menu sebagai pil, bukan tab bergaris bawah. Di atas bidang navy
                pekat, garis bawah tipis nyaris tidak terlihat dari jarak ruang
                rapat, sedangkan pil putih penuh terbaca dari belakang ruangan.
            -->
            <nav class="ml-6 hidden items-center gap-1 lg:flex" aria-label="Menu utama">
                <Link
                    v-for="item in mainNavItems"
                    :key="item.title"
                    :href="item.href"
                    :aria-current="item.href === aktif ? 'page' : undefined"
                    class="tekan flex items-center gap-2 rounded-full px-3.5 py-2 text-sm font-medium transition-colors duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white focus-visible:outline-solid"
                    :class="
                        item.href === aktif ? 'bg-white text-brand shadow-lg shadow-brand/40' : 'text-white/75 hover:bg-white/10 hover:text-white'
                    "
                >
                    <component :is="item.icon" v-if="item.icon" class="size-4" aria-hidden="true" />
                    {{ item.title }}
                </Link>
            </nav>

            <div class="ml-auto flex items-center">
                <DropdownMenu>
                    <DropdownMenuTrigger :as-child="true">
                        <Button
                            variant="ghost"
                            class="tekan h-auto gap-2 rounded-full py-1 pr-2 pl-1 text-white hover:bg-white/15 hover:text-white focus-visible:ring-white"
                        >
                            <!--
                                Lambang SIMEDIA, bukan foto pengguna.

                                Bentuknya kotak membulat, bukan lingkaran.
                                Lambangnya punya busur sinyal di sudut kiri
                                atas, dan lingkaran memotong ujung busur itu.
                                Identitas penggunanya tidak hilang: nama dan
                                surelnya tetap tercetak di dalam menu yang
                                dibuka tombol ini.

                                Nama pengguna tetap dipakai sebagai `alt`
                                cadangan lewat inisial, sehingga tombolnya tetap
                                punya bunyi kalau berkas gambarnya gagal dimuat.
                            -->
                            <Avatar class="size-8 overflow-hidden rounded-xl bg-white/10 ring-1 ring-white/25">
                                <AvatarImage src="/img/logo-simedia.webp" alt="SIMEDIA" class="rounded-xl object-contain p-0.5" />
                                <AvatarFallback class="rounded-xl bg-white/20 text-xs font-semibold text-white">
                                    {{ getInitials(auth.user?.name) }}
                                </AvatarFallback>
                            </Avatar>
                            <ChevronDown class="size-4 opacity-70" aria-hidden="true" />
                            <span class="sr-only">Buka menu akun</span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <UserMenuContent :user="auth.user" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>

        <!--
            Rel merek di tepi bawah bilah, menandai batas antara perabot dan isi.
            Tumbuh dari kiri saat halaman pertama dibuka, lalu diam. Ia juga yang
            memberi bilah ini tepi saat melekat di atas kartu putih yang lewat di
            belakangnya.
        -->
        <div class="tumbuh h-px w-full bg-linear-to-r from-white/50 via-white/15 to-transparent" aria-hidden="true"></div>
    </div>

    <!--
        Rel menu mengambang, pengganti bilah melekat.

        Muncul hanya setelah bilah di atas keluar layar, jadi selama pembaca
        masih di kepala halaman tidak ada satu pun perabot yang menutupi isi.
        Tempatnya di tepi kanan, di tengah tinggi layar, karena itu titik yang
        paling dekat dengan ibu jari pada genggaman satu tangan dan yang paling
        jauh dari arah baca teks di kiri.

        Isinya ikon saja, judulnya baru keluar saat disorot. Empat judul yang
        selalu tercetak akan menjadi pita teks setinggi layar yang menutupi tepi
        kartu di belakangnya, sedangkan yang dibutuhkan pembaca yang sedang
        membaca hanya jalan keluar, bukan daftar yang terbaca terus menerus.

        Judulnya tetap ada di dalam DOM, hanya `opacity` yang nol, jadi pembaca
        layar tetap membacakan nama menunya dan tautannya tidak pernah menjadi
        tombol tanpa nama. Ia juga ikut muncul saat disinggahi papan ketik lewat
        `group-focus-visible`, sebab `hover` tidak pernah terjadi di sana.

        Yang dianimasikan hanya `transform` dan `opacity`, keduanya ditangani
        kompositor. Judulnya diletakkan `absolute` di luar alur, sehingga
        munculnya tidak pernah menggeser lebar rel dan tidak memicu penataan
        ulang di tengah gulir.

        Bidangnya navy pekat, bukan kaca. `brand-terang` dipakai, bukan `brand`
        yang dipakai bilah di puncak, dan perbedaan satu tingkat itu disengaja:
        rel ini menumpang di atas isi yang sedang dibaca, dan navy sepekat kop
        pada benda sekecil ini terbaca sebagai lubang gelap di tepi layar.

        Kaca sempat dipakai di sini lalu dilepas. Ia menuntut `backdrop-filter`,
        yang memaksa kompositor merasterisasi ulang bidang di belakangnya
        sepanjang gulir, dan itu berlawanan arah dengan seluruh pekerjaan yang
        membuat panel ini ringan. Bidang pekat tidak menuntut apa pun.

        Ikon putih menahan rasio 7,69 di atasnya dan ikon yang belum terpilih
        pada `text-white/85` menahan 6,07, keduanya jauh di atas ambang. Nilai
        ini tidak bergantung pada apa yang lewat di belakang, dan itu keuntungan
        kedua dari melepas kaca: kasus terburuknya hilang sama sekali.

        Di ponsel seluruh ukurannya diturunkan. Rel ini menumpang di atas isi yang
        sedang dibaca, dan ukuran layar lebarnya memakan 52 kali 184 piksel, cukup
        untuk menutupi tepi kanan setiap kartu yang lewat di layar 375 piksel.
        Ukuran ringkasnya 36 kali 138 piksel, dan bersama jarak tepi yang ikut
        turun dari 12 ke 6 piksel, seluruh jejaknya dari tepi layar menyempit dari
        64 menjadi 42 piksel. Luasnya berkurang sekitar separuh.

        Kepingnya 32 piksel di ponsel, di bawah ambang sasaran sentuh 44 piksel.
        Ini pilihan sadar: relnya menumpang di atas bacaan dan yang dipilih adalah
        jejak sekecil mungkin. Kalau ketukan meleset mulai dikeluhkan, naikkan
        kembali `size-8` menjadi `size-9`.

        Tingginya dipaku `.tengah-layar`, bukan `top-1/2`. Alasannya ada di
        app.css, ringkasnya: persen pada elemen `fixed` diukur terhadap viewport
        yang tingginya berubah sepanjang gulir di ponsel, dan menu ini ikut
        bergeser setiap kali bilah alamat peramban menyusut lalu muncul lagi.
    -->
    <Transition
        enter-active-class="ease-[cubic-bezier(0.32,0.72,0,1)] transition duration-300"
        enter-from-class="translate-x-6 opacity-0"
        leave-active-class="ease-[cubic-bezier(0.32,0.72,0,1)] transition duration-200"
        leave-to-class="translate-x-6 opacity-0"
    >
        <nav
            v-show="!bilahTerlihat"
            class="tengah-layar fixed right-1.5 z-40 flex -translate-y-1/2 flex-col gap-0.5 rounded-lg bg-brand-terang p-0.5 shadow-[inset_0_1px_0_0_rgb(255_255_255/0.18),0_12px_32px_-12px_rgb(22_63_108/0.55)] ring-1 ring-white/20 sm:right-3 sm:gap-1 sm:rounded-2xl sm:p-1.5"
            aria-label="Menu utama mengambang"
        >
            <Link
                v-for="item in mainNavItems"
                :key="item.title"
                :href="item.href"
                :aria-current="item.href === aktif ? 'page' : undefined"
                class="tekan group relative grid size-8 place-items-center rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white focus-visible:outline-solid sm:size-10 sm:rounded-xl"
                :class="item.href === aktif ? 'bg-white text-brand shadow-xs' : 'text-white/85 hover:bg-white/15 hover:text-white'"
            >
                <component :is="item.icon" v-if="item.icon" class="size-3.75 sm:size-4.5" aria-hidden="true" />

                <!-- Judulnya memakai `brand` yang lebih pekat, bukan
                     `brand-terang` milik relnya. Teks 12 piksel butuh kontras
                     lebih besar daripada ikon yang bentuknya tebal, dan warna
                     yang berbeda satu tingkat sekaligus memisahkan label dari
                     bidang yang memunculkannya. -->
                <span
                    class="pointer-events-none absolute right-full mr-2 translate-x-1 rounded-lg bg-brand px-2.5 py-1.5 text-xs font-semibold whitespace-nowrap text-white opacity-0 shadow-[0_8px_24px_-8px_rgb(22_63_108/0.55)] ring-1 ring-white/20 transition duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] group-hover:translate-x-0 group-hover:opacity-100 group-focus-visible:translate-x-0 group-focus-visible:opacity-100"
                >
                    {{ item.title }}
                </span>
            </Link>
        </nav>
    </Transition>
</template>
