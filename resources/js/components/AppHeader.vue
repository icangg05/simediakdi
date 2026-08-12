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
        Melekat di puncak layar saat digulir.

        Dashboard eksekutif panjangnya lebih dari empat ribu piksel, dan tanpa
        ini pindah dari kaki halaman ke halaman lain berarti menggulir naik
        sampai atas lebih dulu. Kepekatannya sedikit dikurangi dan diberi
        `backdrop-blur` supaya isi yang lewat di belakangnya terbaca sebagai
        gerakan, bukan sebagai potongan yang hilang mendadak. Blur hanya
        dipasang di elemen melekat seperti ini, tidak pernah di bidang yang ikut
        bergulir, karena blur pada bidang bergulir memaksa kartu grafis
        menggambar ulang terus menerus dan panel ini dibuka dari ponsel.
    -->
    <div class="sticky top-0 z-40 bg-brand/95 text-white backdrop-blur supports-[backdrop-filter]:bg-brand/85">
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
            class="sr-only left-4 top-3 z-50 rounded-full bg-white px-4 py-2 text-sm font-semibold text-brand shadow-lg focus:not-sr-only focus:absolute"
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
                    class="tekan ease-[cubic-bezier(0.32,0.72,0,1)] flex items-center gap-2 rounded-full px-3.5 py-2 text-sm font-medium transition-colors duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
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
                            class="tekan h-auto gap-2 rounded-full py-1 pl-1 pr-2 text-white hover:bg-white/15 hover:text-white focus-visible:ring-white"
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
        <div class="tumbuh h-px w-full bg-gradient-to-r from-white/50 via-white/15 to-transparent" aria-hidden="true"></div>
    </div>
</template>
