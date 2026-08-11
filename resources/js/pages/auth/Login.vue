<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Activity,
    ArrowRight,
    Eye,
    EyeOff,
    LayoutDashboard,
    LoaderCircle,
    Lock,
    Mail,
    Rss,
    ScanSearch,
    ShieldCheck,
    TriangleAlert,
} from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

/** Kata sandi terlihat atau tersembunyi. Tombol matanya ada di dalam kolom. */
const sandiTerlihat = ref(false);

/**
 * Caps Lock aktif saat mengetik kata sandi.
 *
 * Kolom kata sandi menyembunyikan isinya, jadi pengguna tidak punya cara lain
 * mengetahui hurufnya terlanjur kapital semua. Ini penyebab gagal masuk yang
 * paling sering dan paling membingungkan, karena pesan galatnya hanya bilang
 * kombinasi tidak cocok.
 */
const capsLockAktif = ref(false);

const periksaCapsLock = (event: KeyboardEvent) => {
    capsLockAktif.value = event.getModifierState?.('CapsLock') ?? false;
};

/**
 * Empat tahap yang dikerjakan sistem terhadap satu berita.
 *
 * Isinya bukan hiasan. Sebagian besar pengguna halaman ini membuka SIMEDIA
 * beberapa kali sebulan saja, dan urutan ini yang menjelaskan angka apa yang
 * akan mereka lihat begitu masuk.
 */
const tahap = [
    {
        ikon: Rss,
        warna: 'text-aksen-toska',
        judul: 'Kumpulkan',
        detail: 'Berita dari 30 media partner.',
    },
    {
        ikon: ScanSearch,
        warna: 'text-aksen-biru',
        judul: 'Saring relevansi',
        detail: 'Hanya yang menyangkut Pemerintah Kota Kendari.',
    },
    {
        ikon: Activity,
        warna: 'text-sentimen-positif',
        judul: 'Nilai nada',
        detail: 'Negatif, netral, atau positif terhadap Pemkot.',
    },
    {
        ikon: LayoutDashboard,
        warna: 'text-aksen-ungu',
        judul: 'Ringkas',
        detail: 'Satu halaman, terbaca di bawah dua menit.',
    },
];

/** Tiga nada yang dipakai seluruh sistem. Warna selalu berpasangan dengan label. */
const nada = [
    { label: 'Negatif', kelas: 'bg-sentimen-negatif' },
    { label: 'Netral', kelas: 'bg-sentimen-netral' },
    { label: 'Positif', kelas: 'bg-sentimen-positif' },
];

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Masuk" />

    <div class="flex h-svh flex-col overflow-hidden bg-background lg:grid lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)]">
        <!--
            Panel merek. Hanya muncul mulai lebar 1024 piksel. Di bawah itu
            layarnya tidak cukup tinggi untuk memuat panel ini dan form
            sekaligus, dan yang harus menang adalah form.

            Selalu navy di mode terang maupun gelap, mengikuti keputusan yang
            sama dengan sidebar: identitas warnanya tidak boleh hilang begitu
            pengguna menyalakan mode gelap.

            Kelas `dark` dipasang di sini bukan untuk mengikuti preferensi
            pengguna, melainkan karena permukaan ini memang gelap. Token aksen
            punya varian yang dicerahkan di konteks gelap, dan tanpa kelas ini
            ikon tahapnya tenggelam di atas navy.
        -->
        <aside class="dark relative isolate hidden h-full flex-col overflow-y-auto bg-brand px-14 py-10 lg:flex xl:px-20">
            <!--
                Sapuan warna merek. `overflow-hidden` wajib ada di sini.

                Ketiga sapuan sengaja melebihi tepi panel supaya lengkungannya
                tidak terlihat sebagai lingkaran, dan tanpa pengapit ini bagian
                yang menonjol ke bawah ikut terhitung sebagai tinggi isi panel.
                Itu yang membuat panel bisa digulir walaupun isinya sudah muat.
            -->
            <div class="pointer-events-none absolute inset-0 -z-10 overflow-hidden" aria-hidden="true">
                <div
                    class="hanyut absolute -left-24 -top-32 h-[34rem] w-[34rem] rounded-full bg-[radial-gradient(closest-side,oklch(0.62_0.11_200/0.42),transparent)] blur-2xl"
                ></div>
                <div
                    class="hanyut hanyut-lambat absolute -right-32 top-1/3 h-[30rem] w-[30rem] rounded-full bg-[radial-gradient(closest-side,oklch(0.55_0.14_285/0.38),transparent)] blur-2xl"
                ></div>
                <div
                    class="absolute -bottom-40 left-1/4 h-[28rem] w-[28rem] rounded-full bg-[radial-gradient(closest-side,oklch(0.60_0.13_250/0.34),transparent)] blur-2xl"
                ></div>
            </div>

            <!--
                Isi panel dipusatkan lewat margin auto, bukan `justify-center`.
                Pada flexbox, isi yang lebih tinggi dari wadahnya dan dipusatkan
                dengan `justify-center` akan terpotong di bagian atas, dan
                potongan itu tidak bisa dijangkau dengan menggulir.
            -->
            <div class="m-auto flex w-full flex-col gap-8">
                <!-- Kop instansi. -->
                <div class="muncul flex items-center gap-4">
                    <img
                        src="/img/Lambang_Kota_Kendari.webp"
                        alt="Lambang Pemerintah Kota Kendari"
                        class="kop-lambang h-16 w-auto shrink-0 drop-shadow-[0_4px_12px_oklch(0_0_0/0.45)]"
                        width="112"
                        height="128"
                    />
                    <div class="min-w-0">
                        <p class="text-lg font-semibold leading-tight text-white">Pemerintah Kota Kendari</p>
                        <p class="text-sm leading-tight text-white/70">Dinas Komunikasi dan Informatika</p>
                    </div>
                </div>

                <!-- Pesan utama dan diagram alur. -->
                <div class="flex flex-col gap-8">
                    <div class="muncul max-w-xl" style="animation-delay: 90ms">
                        <h2 class="judul-panel text-balance text-[2rem] font-semibold leading-[1.15] tracking-tight text-white xl:text-4xl">
                            Nada pemberitaan Kota Kendari, terbaca dalam satu layar.
                        </h2>
                        <p class="penjelas-panel mt-4 max-w-md text-pretty text-base leading-relaxed text-white/75">
                            SIMEDIA mengumpulkan berita tentang Kendari, menyaring yang menyangkut Pemerintah Kota, lalu menilai nadanya.
                        </p>
                    </div>

                    <ol class="muncul relative max-w-lg" style="animation-delay: 180ms">
                        <!-- Tulang punggung alur. Komet putih menuruninya sekali tiap putaran. -->
                        <span class="absolute bottom-5 left-[1.375rem] top-5 w-px overflow-hidden bg-white/15" aria-hidden="true">
                            <span class="komet absolute inset-x-0 top-0 h-1/3 bg-gradient-to-b from-transparent via-white to-transparent"></span>
                        </span>

                        <li
                            v-for="(langkah, urutan) in tahap"
                            :key="langkah.judul"
                            class="relative flex gap-5 pb-5 last:pb-0"
                            :style="{ '--urutan': urutan }"
                        >
                            <span
                                class="simpul relative z-10 flex size-11 shrink-0 items-center justify-center rounded-2xl border border-white/15 bg-white/10 backdrop-blur-sm"
                                :class="langkah.warna"
                            >
                                <component :is="langkah.ikon" class="size-5" :stroke-width="1.75" />
                            </span>
                            <div class="mt-1.5">
                                <p class="text-sm font-semibold text-white">{{ langkah.judul }}</p>
                                <p class="mt-1 text-sm leading-relaxed text-white/65">{{ langkah.detail }}</p>
                            </div>
                        </li>
                    </ol>
                </div>

                <!-- Tiga nada yang dipakai seluruh sistem. -->
                <div class="keping-nada muncul flex flex-wrap items-center gap-2" style="animation-delay: 270ms">
                    <span
                        v-for="item in nada"
                        :key="item.label"
                        class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-medium text-white/85"
                    >
                        <span class="size-2 rounded-full" :class="item.kelas" aria-hidden="true"></span>
                        {{ item.label }}
                    </span>
                </div>
            </div>
        </aside>

        <!--
            Panel masuk.

            Di bawah 1024 piksel panel ini sendirian mengisi layar, jadi latarnya
            memakai navy merek beserta sapuan gradiennya. Mulai 1024 piksel panel
            merek di sebelahnya yang membawa warna itu, dan di sini latarnya
            berganti menjadi abu lembut supaya kartunya punya tepi.
        -->
        <main
            class="relative isolate flex min-h-0 flex-1 flex-col overflow-y-auto bg-brand px-4 py-5 sm:px-10 sm:py-12 lg:h-full lg:bg-muted/60 lg:px-14 lg:py-12 xl:px-20"
        >
            <!--
                Sapuan gradien tetap dipasang di layar besar, hanya diredam.
                Kartu masuk memakai efek kaca, dan kaca hanya terbaca kalau ada
                warna yang bisa tembus di belakangnya. Di atas abu datar, kaca
                terlihat sama saja dengan bidang buram biasa.
            -->
            <div class="latar-masuk pointer-events-none absolute inset-0 -z-10 lg:opacity-40" aria-hidden="true"></div>

            <!--
                Isi dipusatkan lewat margin auto, bukan `justify-center` di
                induknya. Kalau isinya tumbuh, misalnya pesan galat validasi
                muncul bersamaan dengan peringatan Caps Lock, `justify-center`
                memotong bagian atas kartu dan potongan itu tidak bisa digulir.
            -->
            <div class="m-auto w-full max-w-[26rem]">
                <!--
                    Kop instansi versi ringkas. Panel merek tidak ada di layar
                    kecil, dan lambang Pemerintah Kota tidak boleh ikut hilang
                    bersamanya.
                -->
                <div class="muncul mb-5 flex items-center justify-center gap-3 lg:hidden">
                    <img
                        src="/img/Lambang_Kota_Kendari.webp"
                        alt="Lambang Pemerintah Kota Kendari"
                        class="h-11 w-auto shrink-0 drop-shadow-[0_4px_12px_oklch(0_0_0/0.45)]"
                        width="112"
                        height="128"
                    />
                    <div class="min-w-0">
                        <p class="text-sm font-semibold leading-tight text-white">Pemerintah Kota Kendari</p>
                        <p class="text-xs leading-tight text-white/70">Dinas Komunikasi dan Informatika</p>
                    </div>
                </div>

                <!--
                    Form dibungkus kartu kaca. Bidangnya tembus pandang sebagian
                    dan mengaburkan sapuan gradien di belakangnya, jadi warna
                    merek tetap terasa tanpa ikut mengotori teks form.
                    `backdrop-blur` yang mengerjakan pengaburannya, bukan gambar
                    latar, sehingga isinya tetap tajam.

                    Tepi terangnya bukan hiasan. Tanpa garis itu, bidang kaca di
                    atas gradien terang kehilangan batas dan kartunya tidak
                    terbaca lagi sebagai satu bidang utuh.
                -->
                <Card
                    class="muncul w-full border-white/50 bg-white/[0.82] p-5 shadow-[0_18px_40px_-24px_oklch(0.20_0.06_252/0.65)] backdrop-blur-xl backdrop-saturate-150 dark:border-white/[0.12] dark:bg-white/[0.08] sm:p-7"
                >
                    <div>
                        <h1 class="text-xl font-semibold tracking-tight text-foreground sm:text-2xl">Masuk ke SIMEDIA</h1>
                        <p class="mt-1.5 hidden text-sm leading-relaxed text-muted-foreground sm:block">
                            Gunakan akun yang diberikan Diskominfo Kota Kendari.
                        </p>
                    </div>

                    <div
                        v-if="status"
                        class="mt-5 flex items-start gap-3 rounded-md border border-sentimen-positif/30 bg-sentimen-positif-lembut px-4 py-3 text-sm text-foreground"
                    >
                        <ShieldCheck class="mt-0.5 size-4 shrink-0 text-sentimen-positif" :stroke-width="1.75" />
                        <span>{{ status }}</span>
                    </div>

                    <form @submit.prevent="submit" class="mt-5 flex flex-col gap-4 sm:mt-6">
                        <div class="grid gap-2">
                            <Label for="email">Alamat email</Label>
                            <div class="group relative">
                                <Mail
                                    class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground transition-colors duration-200 group-focus-within:text-brand dark:group-focus-within:text-brand-terang"
                                    :stroke-width="1.75"
                                />
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    autofocus
                                    tabindex="1"
                                    autocomplete="email"
                                    v-model="form.email"
                                    placeholder="nama@instansi.go.id"
                                    class="h-11 border-brand/15 bg-brand/[0.04] pl-10 transition-shadow duration-200 focus-visible:ring-brand/70 dark:border-white/[0.12] dark:bg-white/[0.05] dark:focus-visible:ring-brand-terang"
                                />
                            </div>
                            <InputError :message="form.errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <div class="flex items-center justify-between gap-3">
                                <Label for="password">Kata sandi</Label>
                                <TextLink v-if="canResetPassword" :href="route('password.request')" class="text-sm" tabindex="5"
                                    >Lupa kata sandi?</TextLink
                                >
                            </div>
                            <div class="group relative">
                                <Lock
                                    class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground transition-colors duration-200 group-focus-within:text-brand dark:group-focus-within:text-brand-terang"
                                    :stroke-width="1.75"
                                />
                                <Input
                                    id="password"
                                    :type="sandiTerlihat ? 'text' : 'password'"
                                    required
                                    tabindex="2"
                                    autocomplete="current-password"
                                    v-model="form.password"
                                    placeholder="Masukkan kata sandi"
                                    class="h-11 border-brand/15 bg-brand/[0.04] pl-10 pr-11 transition-shadow duration-200 focus-visible:ring-brand/70 dark:border-white/[0.12] dark:bg-white/[0.05] dark:focus-visible:ring-brand-terang"
                                    @keyup="periksaCapsLock"
                                    @keydown="periksaCapsLock"
                                    @blur="capsLockAktif = false"
                                />
                                <button
                                    type="button"
                                    tabindex="3"
                                    class="absolute right-1.5 top-1/2 flex size-8 -translate-y-1/2 items-center justify-center rounded-md text-muted-foreground transition-colors duration-200 hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/70 focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                    :aria-label="sandiTerlihat ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                                    :aria-pressed="sandiTerlihat"
                                    @click="sandiTerlihat = !sandiTerlihat"
                                >
                                    <EyeOff v-if="sandiTerlihat" class="size-4" :stroke-width="1.75" />
                                    <Eye v-else class="size-4" :stroke-width="1.75" />
                                </button>
                            </div>
                            <!-- Teksnya memakai warna dasar, bukan kuning peringatan. Kuning pada
                             tingkat terang itu gagal kontras di atas latar terang. Nada
                             peringatannya dibawa bidang latarnya. -->
                            <p
                                v-if="capsLockAktif"
                                class="flex items-center gap-2 rounded-lg bg-sentimen-review-lembut px-3 py-2 text-sm text-foreground"
                            >
                                <TriangleAlert class="size-4 shrink-0" :stroke-width="1.75" />
                                Caps Lock sedang aktif.
                            </p>
                            <InputError :message="form.errors.password" />
                        </div>

                        <Label for="remember" class="flex w-fit items-center gap-3 text-sm font-normal">
                            <Checkbox id="remember" v-model:checked="form.remember" tabindex="4" />
                            <span>Ingat saya di perangkat ini</span>
                        </Label>

                        <!--
                        Tombol utama. Label dan panahnya duduk berdampingan di tengah,
                        dan panahnya bergeser saat disorot sehingga arah aksinya terasa
                        sebelum tombolnya ditekan.
                    -->
                        <Button
                            type="submit"
                            tabindex="6"
                            :disabled="form.processing"
                            class="tekan group mt-1 h-11 w-full gap-2.5 rounded-md bg-brand text-base shadow-[0_10px_24px_-14px_oklch(0.36_0.09_252/0.85)] transition-colors duration-200 hover:bg-brand-terang dark:bg-brand-terang dark:hover:bg-brand"
                        >
                            <span>{{ form.processing ? 'Memeriksa akun' : 'Masuk' }}</span>
                            <span class="panah flex size-7 items-center justify-center rounded bg-white/15 group-hover:translate-x-0.5">
                                <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                                <ArrowRight v-else class="size-4" :stroke-width="2" />
                            </span>
                        </Button>
                    </form>

                    <p class="mt-5 border-t border-brand/10 pt-4 text-sm text-muted-foreground dark:border-white/[0.12]">
                        Belum punya akun? Hubungi admin Diskominfo Kota Kendari.
                    </p>
                </Card>
            </div>
        </main>
    </div>
</template>

<style scoped>
/*
 * Penyesuaian untuk layar lebar tapi pendek.
 *
 * Ini satu satunya aturan di halaman ini yang membaca tinggi layar, bukan
 * lebarnya, dan memang harus begitu. Laptop 1366x768 hanya menyisakan sekitar
 * 660 piksel setelah bilah alamat dan bilah tugas, jadi titik henti berbasis
 * lebar tidak pernah tahu panel merek sudah tidak muat.
 *
 * Yang dikorbankan diurutkan dari yang paling murah: kalimat penjelas dan
 * keping nada hilang lebih dulu, judul dan lambang hanya mengecil. Empat tahap
 * alur tidak pernah disentuh, itu isi utama panelnya.
 */
@media (min-width: 1024px) and (max-height: 740px) {
    .kop-lambang {
        height: 3rem;
    }

    .judul-panel {
        font-size: 1.75rem;
        line-height: 1.2;
    }

    .penjelas-panel,
    .keping-nada {
        display: none;
    }
}

/*
 * Sapuan gradien di belakang kartu masuk, khusus layar kecil.
 *
 * Tiga sapuan dengan rona yang sama persis dengan panel merek: toska di kiri
 * atas, ungu di kanan, biru di kaki halaman. Jadi ponsel dan desktop terasa
 * satu halaman, bukan dua rancangan berbeda.
 *
 * Sengaja diam, tidak seperti sapuan di panel merek yang bergeser pelan.
 * Menganimasikan bidang seluas layar penuh di ponsel kelas menengah membakar
 * baterai tanpa ada yang menonton, dan di sini bidangnya adalah seluruh layar.
 */
.latar-masuk {
    background-image:
        radial-gradient(38rem 26rem at 14% -8%, oklch(0.66 0.12 200 / 0.55), transparent 62%),
        radial-gradient(32rem 24rem at 104% 20%, oklch(0.58 0.15 285 / 0.5), transparent 62%),
        radial-gradient(36rem 28rem at 44% 106%, oklch(0.62 0.14 250 / 0.52), transparent 62%);
}

/*
 * Panah di dalam tombol utama. Kurvanya sama dengan `.tekan` di app.css supaya
 * geseran panah dan tekanan tombol terasa satu gerakan.
 */
.panah {
    transition: transform 300ms cubic-bezier(0.32, 0.72, 0, 1);
}

/*
 * Sapuan warna latar bergeser sangat lambat.
 *
 * Bidang navy datar terlihat mati di layar lebar, sedangkan pergeseran selambat
 * ini tidak pernah menarik mata menjauh dari form di sebelahnya. Hanya transform
 * yang berubah, jadi tidak ada penataan ulang tata letak.
 */
@keyframes hanyut {
    0%,
    100% {
        transform: translate3d(0, 0, 0) scale(1);
    }

    50% {
        transform: translate3d(2.5rem, -1.75rem, 0) scale(1.08);
    }
}

.hanyut {
    animation: hanyut 26s cubic-bezier(0.45, 0, 0.55, 1) infinite;
    will-change: transform;
}

.hanyut-lambat {
    animation-duration: 34s;
    animation-direction: reverse;
}

/*
 * Komet yang menuruni tulang punggung alur.
 *
 * Satu putaran memakan 7,2 detik dan menyentuh keempat simpul berurutan. Ini
 * satu-satunya gerak yang berulang di panel, dan tugasnya menjelaskan bahwa
 * keempat tahap itu satu jalur, bukan empat daftar terpisah.
 *
 * Persentase translateY dihitung dari tinggi komet sendiri, sehingga nilainya
 * tidak perlu tahu setinggi apa tulang punggungnya.
 */
@keyframes komet {
    0% {
        transform: translateY(-100%);
        opacity: 0;
    }

    12% {
        opacity: 1;
    }

    88% {
        opacity: 1;
    }

    100% {
        transform: translateY(300%);
        opacity: 0;
    }
}

.komet {
    animation: komet 7.2s cubic-bezier(0.55, 0, 0.45, 1) infinite;
}

/*
 * Simpul menyala saat komet melewatinya.
 *
 * Jedanya dihitung dari urutan simpul, jadi nyalanya mengikuti arah baca dari
 * atas ke bawah. Durasinya sama dengan satu putaran komet supaya keduanya tidak
 * pernah bergeser fase.
 */
@keyframes nyala {
    0%,
    14%,
    100% {
        border-color: rgb(255 255 255 / 0.15);
        background-color: rgb(255 255 255 / 0.1);
        transform: scale(1);
    }

    6% {
        border-color: rgb(255 255 255 / 0.5);
        background-color: rgb(255 255 255 / 0.22);
        transform: scale(1.06);
    }
}

.simpul {
    animation: nyala 7.2s cubic-bezier(0.32, 0.72, 0, 1) infinite;
    animation-delay: calc(var(--urutan) * 1.45s);
}

/*
 * Gerak berulang dimatikan sama sekali, bukan dipercepat. Panel ini terbaca
 * penuh tanpa satu pun animasi berjalan.
 */
@media (prefers-reduced-motion: reduce) {
    .hanyut,
    .komet,
    .simpul {
        animation: none;
    }

    .komet {
        opacity: 0;
    }

    .panah {
        transition: none;
    }
}
</style>
