<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import JembatanTeluk from '@/components/ilustrasi/JembatanTeluk.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Activity,
    ArrowRight,
    Eye,
    EyeOff,
    Filter,
    Headset,
    LayoutDashboard,
    LoaderCircle,
    Lock,
    Rss,
    ShieldCheck,
    TriangleAlert,
    UserRound,
} from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    status?: string;
}>();

const form = useForm({
    username: '',
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
 * Isinya bukan hiasan. Sebagian besar pengguna halaman ini membuka SIMAK
 * beberapa kali sebulan saja, dan urutan ini yang menjelaskan angka apa yang
 * akan mereka lihat begitu masuk.
 *
 * Keempat ikonnya kini satu warna, yaitu biru langit merek, bukan tiga rona
 * aksen yang berbeda. Warna berbeda per baris membuat pembaca mencari arti di
 * balik perbedaan itu, padahal tidak ada. Di halaman ini hanya satu blok yang
 * warnanya benar benar berarti, yaitu ketiga nada di bawah, dan blok itu jauh
 * lebih mudah terbaca kalau tidak ada warna lain yang bersaing.
 */
const tahap = [
    {
        ikon: Rss,
        judul: 'Kumpulkan',
        detail: 'Berita dari 30 media partner.',
    },
    {
        ikon: Filter,
        judul: 'Saring relevansi',
        detail: 'Hanya yang menyangkut Pemerintah Kota Kendari.',
    },
    {
        ikon: Activity,
        judul: 'Nilai sentimen',
        detail: 'Negatif, netral, atau positif terhadap Pemkot.',
    },
    {
        ikon: LayoutDashboard,
        judul: 'Ringkas',
        detail: 'Satu halaman, terbaca di bawah dua menit.',
    },
];

/**
 * Tiga nada yang dipakai seluruh sistem.
 *
 * Ini satu satunya tempat di halaman ini yang memakai warna sentimen, dan
 * ketiganya selalu berpasangan dengan labelnya. Warna tidak pernah menjadi
 * penanda tunggal.
 */
const nada = [
    { label: 'Negatif', teks: 'text-sentimen-negatif', isi: 'bg-sentimen-negatif' },
    { label: 'Netral', teks: 'text-sentimen-netral', isi: 'bg-sentimen-netral' },
    { label: 'Positif', teks: 'text-sentimen-positif', isi: 'bg-sentimen-positif' },
];

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Masuk" />

    <div class="flex h-svh flex-col overflow-hidden bg-brand lg:grid lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
        <!--
            Panel merek. Hanya muncul mulai lebar 1024 piksel. Di bawah itu
            layarnya tidak cukup tinggi untuk memuat panel ini dan form
            sekaligus, dan yang harus menang adalah form.

            Selalu navy di mode terang maupun gelap, mengikuti keputusan yang
            sama dengan sidebar: identitas warnanya tidak boleh hilang begitu
            pengguna menyalakan mode gelap.

            Kelas `dark` dipasang di sini bukan untuk mengikuti preferensi
            pengguna, melainkan karena permukaan ini memang gelap. Token
            sentimen punya varian yang dicerahkan di konteks gelap, dan tanpa
            kelas ini ketiga label nada tenggelam di atas navy.
        -->
        <aside class="panel-merek dark relative isolate hidden h-full flex-col overflow-y-auto px-14 py-12 lg:flex xl:px-20">
            <!--
                Lapisan ornamen. `overflow-hidden` wajib ada di sini.

                Ornamennya sengaja melebihi tepi panel supaya tidak ada satu pun
                yang terbaca sebagai bentuk utuh yang berdiri sendiri, dan tanpa
                pengapit ini bagian yang menonjol ke bawah ikut terhitung sebagai
                tinggi isi panel. Itu yang membuat panel bisa digulir walaupun
                isinya sudah muat.
            -->
            <div class="pointer-events-none absolute inset-0 -z-10 overflow-hidden" aria-hidden="true">
                <!--
                    Ladang titik di sudut kanan atas.

                    Bidangnya dibatasi dan ditopeng miring, jadi ia ornamen yang
                    menempati satu sudut, bukan pola yang menutupi seluruh latar.
                    Bedanya penting: pola selatar penuh menjadi unsur kedua yang
                    bersaing dengan isi panel, dan itu yang sudah dicatat
                    `app.css` sebagai hal yang dihindari.
                -->
                <div class="titik absolute top-16 right-4 h-72 w-80"></div>

                <!-- Batang ukur. Empat batang setinggi berbeda, tanpa sumbu dan
                     tanpa angka, karena ia memang tidak mewakili data apa pun. -->
                <svg class="absolute top-32 right-32 h-9 w-14 text-white/20" viewBox="0 0 56 40" fill="currentColor">
                    <rect x="0" y="22" width="8" height="18" rx="2" />
                    <rect x="14" y="12" width="8" height="28" rx="2" />
                    <rect x="28" y="18" width="8" height="22" rx="2" />
                    <rect x="42" y="4" width="8" height="36" rx="2" />
                </svg>

                <!--
                    Cincin nada. Busur luarnya digambar sekali saat halaman
                    terbuka, mengikuti kurva yang sama dengan seluruh gerak lain
                    di halaman ini.
                -->
                <svg class="absolute top-1/2 -right-10 size-40 -translate-y-24" viewBox="0 0 120 120" fill="none">
                    <circle cx="60" cy="60" r="52" stroke="currentColor" stroke-width="1" class="text-white/10" />
                    <circle cx="60" cy="60" r="34" stroke="currentColor" stroke-width="9" class="text-white/5" />
                    <circle
                        cx="60"
                        cy="60"
                        r="34"
                        stroke="currentColor"
                        stroke-width="9"
                        stroke-linecap="round"
                        transform="rotate(-108 60 60)"
                        class="gambar text-brand-langit/40"
                        style="--panjang: 128; animation-delay: 420ms"
                    />
                </svg>

                <!--
                    Siluet Jembatan Teluk Kendari di kaki panel.

                    Satu satunya ornamen di halaman ini yang menyebut tempat.
                    Halaman ini dibuka pegawai Pemerintah Kota Kendari, dan
                    jembatan itu penanda yang mereka kenali tanpa perlu dibaca.
                -->
                <JembatanTeluk class="absolute -right-10 -bottom-6 w-88 text-white/25" />

                <!-- Cahaya latar. Dua saja, dan keduanya sangat lambat. -->
                <div
                    class="hanyut absolute -top-40 -left-40 h-144 w-xl rounded-full bg-[radial-gradient(closest-side,oklch(0.62_0.10_235/0.30),transparent)] blur-2xl"
                ></div>
                <div
                    class="hanyut hanyut-lambat absolute right-0 -bottom-56 h-136 w-136 rounded-full bg-[radial-gradient(closest-side,oklch(0.58_0.12_250/0.26),transparent)] blur-2xl"
                ></div>
            </div>

            <!--
                Isi panel dipusatkan lewat margin auto, bukan `justify-center`.
                Pada flexbox, isi yang lebih tinggi dari wadahnya dan dipusatkan
                dengan `justify-center` akan terpotong di bagian atas, dan
                potongan itu tidak bisa dijangkau dengan menggulir.
            -->
            <div class="m-auto flex w-full flex-col gap-9">
                <!-- Kop instansi. -->
                <div class="muncul flex items-center gap-4">
                    <img
                        src="/img/Lambang_Kota_Kendari.webp"
                        alt="Lambang Pemerintah Kota Kendari"
                        class="kop-lambang h-14 w-auto shrink-0 drop-shadow-[0_4px_12px_oklch(0_0_0/0.45)]"
                        width="112"
                        height="128"
                    />
                    <div class="min-w-0">
                        <p class="text-lg leading-tight font-semibold text-white">Pemerintah Kota Kendari</p>
                        <p class="text-sm leading-tight text-white/70">Dinas Komunikasi dan Informatika</p>
                    </div>
                </div>

                <!-- Pesan utama. -->
                <div class="muncul max-w-136" style="animation-delay: 90ms">
                    <!-- Guratan pembuka. Bukan label, tidak ada teks di dalamnya, dan
                         itu memang tujuannya: ia hanya menandai tempat judul dimulai. -->
                    <span class="mb-6 block h-1 w-11 rounded-full bg-brand-langit" aria-hidden="true"></span>

                    <h2 class="judul-panel text-[1.875rem] leading-[1.2] font-semibold tracking-tight text-balance text-white xl:text-[2.125rem]">
                        Sentimen pemberitaan Kota Kendari, terbaca dalam
                        <span class="text-brand-langit">satu layar.</span>
                    </h2>
                    <p class="penjelas-panel mt-5 max-w-md text-[0.9375rem] leading-relaxed text-pretty text-white/70">
                        SIMAK mengumpulkan berita tentang Kendari, menyaring yang menyangkut Pemerintah Kota, lalu menilai sentimennya.
                    </p>
                </div>

                <!--
                    Diagram alur dan cabang nada, satu blok tanpa jarak di antaranya.

                    Keduanya sengaja tidak dipisah `gap`. Tulang punggung alur
                    turun melewati empat tahap lalu bercabang menjadi tiga nada,
                    dan garis itu harus tersambung utuh supaya terbaca sebagai
                    satu jalur, bukan sebagai dua daftar yang kebetulan bertumpuk.
                -->
                <div class="flex max-w-lg flex-col">
                    <ol class="muncul relative" style="animation-delay: 180ms">
                        <!-- Tulang punggung alur. Komet terang menuruninya sekali tiap putaran. -->
                        <span class="rel-alur absolute top-6 bottom-0 left-5.5 w-px overflow-hidden bg-white/12" aria-hidden="true">
                            <span class="komet absolute inset-x-0 top-0 h-1/3 bg-linear-to-b from-transparent via-brand-langit to-transparent"></span>
                        </span>

                        <li
                            v-for="(langkah, urutan) in tahap"
                            :key="langkah.judul"
                            class="relative flex gap-5 pb-6 last:pb-4"
                            :style="{ '--urutan': urutan }"
                        >
                            <span
                                class="simpul relative z-10 flex size-11 shrink-0 items-center justify-center rounded-2xl border border-white/15 bg-white/[0.07] text-brand-langit"
                            >
                                <component :is="langkah.ikon" class="size-5" :stroke-width="1.75" />
                            </span>
                            <div class="mt-1.5">
                                <p class="text-[0.9375rem] leading-tight font-semibold text-white">{{ langkah.judul }}</p>
                                <p class="mt-1.5 text-[0.8125rem] leading-relaxed text-white/60">{{ langkah.detail }}</p>
                            </div>
                        </li>
                    </ol>

                    <!--
                        Percabangan nada.

                        Ini ornamen yang paling banyak bekerja di halaman ini,
                        dan satu satunya yang membawa arti. Batang yang sama
                        turun dari empat tahap di atas, lalu memecah menjadi tiga
                        untai berwarna yang berakhir tepat di label nadanya.
                        Bentuknya adalah pekerjaan sistem itu sendiri: satu
                        aliran berita masuk, tiga nada keluar.

                        Tinggi SVG dan tinggi tumpukan label sengaja dikunci sama
                        pada 100 piksel, dan ketiga ujung kurvanya berada di 17,
                        50, dan 83. Itu titik tengah ketiga baris label kalau
                        tingginya dibagi rata, jadi untainya benar benar mendarat
                        di labelnya, bukan sekadar mendekat.
                    -->
                    <div class="keping-nada muncul flex items-stretch" style="animation-delay: 300ms">
                        <svg class="h-25 w-15 shrink-0" viewBox="0 0 60 100" fill="none" aria-hidden="true">
                            <g fill="none" stroke-linecap="round" stroke-width="1.5">
                                <path class="gambar stroke-white/12" d="M22 0V50" style="--panjang: 60" />
                                <path
                                    class="gambar stroke-sentimen-negatif/70"
                                    d="M22 50C34 50 40 17 60 17"
                                    style="--panjang: 70; animation-delay: 340ms"
                                />
                                <path class="gambar stroke-sentimen-netral/70" d="M22 50H60" style="--panjang: 45; animation-delay: 440ms" />
                                <path
                                    class="gambar stroke-sentimen-positif/70"
                                    d="M22 50C34 50 40 83 60 83"
                                    style="--panjang: 70; animation-delay: 540ms"
                                />
                            </g>
                        </svg>

                        <ul class="flex h-25 flex-1 flex-col">
                            <li v-for="item in nada" :key="item.label" class="flex flex-1 items-center gap-2.5">
                                <span class="size-2 shrink-0 rounded-full" :class="item.isi" aria-hidden="true"></span>
                                <span class="text-sm font-medium" :class="item.teks">{{ item.label }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </aside>

        <!--
            Panel masuk.

            Di bawah 1024 piksel panel ini sendirian mengisi layar, jadi latarnya
            memakai navy merek beserta kedua cahayanya. Mulai 1024 piksel panel
            merek di sebelahnya yang membawa warna itu, dan di sini latarnya
            berganti menjadi bidang terang bertinta biru yang sangat samar.
        -->
        <main
            class="panel-masuk relative isolate flex min-h-0 flex-1 flex-col overflow-y-auto px-4 py-5 sm:px-10 sm:py-12 lg:h-full lg:px-12 lg:py-12 xl:px-16"
        >
            <!--
                Isi dipusatkan lewat margin auto, bukan `justify-center` di
                induknya. Kalau isinya tumbuh, misalnya pesan galat validasi
                muncul bersamaan dengan peringatan Caps Lock, `justify-center`
                memotong bagian atas kartu dan potongan itu tidak bisa digulir.
            -->
            <div class="m-auto w-full max-w-110">
                <!--
                    Kop instansi versi ringkas. Panel merek tidak ada di layar
                    kecil, dan lambang Pemerintah Kota tidak boleh ikut hilang
                    bersamanya.
                -->
                <div class="kop-ringkas muncul mb-5 flex items-center justify-center gap-3 lg:hidden">
                    <img
                        src="/img/Lambang_Kota_Kendari.webp"
                        alt="Lambang Pemerintah Kota Kendari"
                        class="h-11 w-auto shrink-0 drop-shadow-[0_4px_12px_oklch(0_0_0/0.45)]"
                        width="112"
                        height="128"
                    />
                    <div class="min-w-0">
                        <p class="text-sm leading-tight font-semibold text-white">Pemerintah Kota Kendari</p>
                        <p class="text-xs leading-tight text-white/70">Dinas Komunikasi dan Informatika</p>
                    </div>
                </div>

                <!--
                    Kartu masuk. Satu bidang putih, satu lengkung, satu bayangan.

                    Tidak ada baki pembungkus di luarnya dan tidak ada kartu di
                    dalamnya. Bidang bertumpuk membuat form terasa terkurung, dan
                    di halaman yang isinya hanya dua kolom isian, satu bidang
                    sudah cukup untuk memisahkannya dari latar.

                    Bayangannya bertinta navy, bukan hitam. Bayangan hitam di
                    atas latar kebiruan terbaca kotor.
                -->
                <div
                    class="kartu-masuk muncul rounded-3xl bg-card p-6 shadow-[0_28px_64px_-32px_oklch(0.36_0.09_252/0.45)] ring-1 ring-brand/[0.07] sm:p-8 dark:ring-white/10"
                >
                    <!--
                        Lambang SIMAK dan namanya berdiri berdampingan di dalam
                        kartu, bukan di kop instansi di atasnya.

                        Kop itu sudah membawa lambang Pemerintah Kota, dan dua
                        lambang berjajar di sana membuat pembaca menimbang mana
                        yang pemilik halaman. Di sini artinya lugas: inilah
                        sistem yang sedang dimasuki. Ia juga satu satunya
                        penempatan yang terlihat di semua lebar layar, karena
                        panel merek di kiri hilang di bawah 1024 piksel.
                    -->
                    <div class="kop-kartu flex items-center gap-3">
                        <span
                            class="lencana-simedia grid size-12 shrink-0 place-items-center rounded-2xl bg-brand/6 ring-1 ring-brand/10 dark:bg-white/10 dark:ring-white/15"
                        >
                            <img src="/img/logo-simedia.webp" alt="Lambang SIMAK" class="size-8 object-contain" width="32" height="32" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-[1.375rem] leading-tight font-semibold tracking-tight text-brand dark:text-white">SIMAK</span>
                            <span class="block text-xs leading-snug text-muted-foreground">Sistem Monitoring dan Analisis Kendari</span>
                        </span>
                    </div>

                    <!--
                        Tagline, satu baris di bawah nama sistem.

                        Ia menjelaskan tujuan sistem dengan kalimat yang bisa
                        dikutip, sedangkan kepanjangan akronim di atas hanya
                        menyebut isinya. Keduanya dibedakan tebal hurufnya
                        supaya tidak terbaca sebagai dua penjelas yang sama.
                    -->
                    <p class="tagline-masuk mt-3 text-sm leading-relaxed font-medium text-brand/80 dark:text-white/75">
                        Membaca Pemberitaan, Mengawal Kendari Semakin Maju.
                    </p>

                    <h1 class="judul-masuk mt-6 text-xl font-semibold tracking-tight text-foreground sm:text-[1.375rem]">Masuk ke SIMAK</h1>
                    <p class="penjelas-masuk mt-1.5 text-sm leading-relaxed text-muted-foreground">
                        Gunakan akun yang diberikan Diskominfo Kota Kendari.
                    </p>

                    <!-- Pesan berhasil, misalnya setelah kata sandi disetel ulang.
                         Hijau di sini bukan pilihan rasa. Ia token sentimen positif
                         yang sama dengan yang dipakai seluruh aplikasi, dan ikon
                         perisai menjadi penanda keduanya supaya warna tidak berdiri
                         sendiri. -->
                    <div
                        v-if="status"
                        class="mt-5 flex items-start gap-3 rounded-xl border border-sentimen-positif/30 bg-sentimen-positif-lembut px-4 py-3 text-sm text-foreground"
                    >
                        <ShieldCheck class="mt-0.5 size-4 shrink-0 text-sentimen-positif" :stroke-width="1.75" />
                        <span>{{ status }}</span>
                    </div>

                    <form @submit.prevent="submit" class="form-masuk mt-6 flex flex-col gap-4">
                        <div class="grid gap-2">
                            <Label for="username">Nama pengguna</Label>
                            <div class="group relative">
                                <UserRound
                                    class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground transition-colors duration-200 group-focus-within:text-brand dark:group-focus-within:text-brand-langit"
                                    :stroke-width="1.75"
                                />
                                <Input
                                    id="username"
                                    type="text"
                                    required
                                    autofocus
                                    tabindex="1"
                                    autocomplete="username"
                                    v-model="form.username"
                                    placeholder="nama pengguna Anda"
                                    class="kolom-isian h-12 rounded-xl border-transparent bg-brand/4.5 pl-11 transition-shadow duration-200 focus-visible:ring-brand/60 dark:bg-white/6 dark:focus-visible:ring-brand-langit"
                                />
                            </div>
                            <InputError :message="form.errors.username" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="password">Kata sandi</Label>
                            <div class="group relative">
                                <Lock
                                    class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground transition-colors duration-200 group-focus-within:text-brand dark:group-focus-within:text-brand-langit"
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
                                    class="kolom-isian h-12 rounded-xl border-transparent bg-brand/4.5 pr-12 pl-11 transition-shadow duration-200 focus-visible:ring-brand/60 dark:bg-white/6 dark:focus-visible:ring-brand-langit"
                                    @keyup="periksaCapsLock"
                                    @keydown="periksaCapsLock"
                                    @blur="capsLockAktif = false"
                                />
                                <!-- Tombol mata memakai warna dasar, bukan warna merek.
                                     Ia hanya mengubah cara isi kolom ditampilkan, tidak
                                     mengubah apa pun di sistem, jadi ia tidak berhak
                                     atas warna yang dipakai aksi utama. -->
                                <button
                                    type="button"
                                    tabindex="3"
                                    class="absolute top-1/2 right-2 flex size-8 -translate-y-1/2 items-center justify-center rounded-lg text-muted-foreground transition-colors duration-200 hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-brand/70 focus-visible:ring-offset-2 focus-visible:ring-offset-card focus-visible:outline-hidden"
                                    :aria-label="sandiTerlihat ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                                    :aria-pressed="sandiTerlihat"
                                    @click="sandiTerlihat = !sandiTerlihat"
                                >
                                    <EyeOff v-if="sandiTerlihat" class="size-4" :stroke-width="1.75" />
                                    <Eye v-else class="size-4" :stroke-width="1.75" />
                                </button>
                            </div>
                            <!-- Peringatan Caps Lock memakai token sentimen review, warna
                                 yang di seluruh aplikasi berarti perlu diperiksa dulu.
                                 Teksnya memakai warna dasar, bukan kuning: kuning pada
                                 tingkat terang gagal kontras di atas latar terang. Nada
                                 peringatannya dibawa bidang latar dan ikon segitiga. -->
                            <p
                                v-if="capsLockAktif"
                                class="flex items-center gap-2 rounded-xl bg-sentimen-review-lembut px-3 py-2 text-sm text-foreground"
                            >
                                <TriangleAlert class="size-4 shrink-0 text-sentimen-review" :stroke-width="1.75" />
                                Caps Lock sedang aktif.
                            </p>
                            <InputError :message="form.errors.password" />
                        </div>

                        <Label for="remember" class="flex w-fit items-center gap-3 text-sm font-normal">
                            <Checkbox id="remember" v-model:checked="form.remember" tabindex="4" />
                            <span>Ingat saya di perangkat ini</span>
                        </Label>

                        <!--
                            Tombol utama. Satu satunya bidang berwarna penuh di seluruh
                            form, karena ia satu satunya kendali yang mengubah keadaan
                            sistem.

                            Gradiennya berjalan dari biru terang di kiri menuju navy
                            merek persis di kanan, jadi ia terasa lebih hidup daripada
                            bidang datar tanpa pernah lepas dari warna merek. Ujung
                            paling terangnya masih mencapai 5,4:1 terhadap teks putih.

                            Kelas `aliran` hanya menempel selama pemeriksaan akun
                            berjalan. Cahaya yang menyusuri tombol saat tidak ada yang
                            diproses berbohong lebih keras daripada teks apa pun yang
                            bisa ditulis di sebelahnya.
                        -->
                        <Button
                            type="submit"
                            tabindex="6"
                            :disabled="form.processing"
                            class="tombol-utama tekan group relative mt-2 h-12 w-full gap-2.5 overflow-hidden rounded-xl text-base shadow-[0_14px_28px_-16px_oklch(0.36_0.09_252/0.9)]"
                            :class="form.processing ? 'aliran' : ''"
                        >
                            <span>{{ form.processing ? 'Memeriksa akun' : 'Masuk' }}</span>
                            <span class="panah flex size-7 items-center justify-center rounded-full bg-white/20 group-hover:translate-x-0.5">
                                <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                                <ArrowRight v-else class="size-4" :stroke-width="2" />
                            </span>
                        </Button>
                    </form>

                    <!--
                        Pemisah dengan lencana perisai di tengahnya.

                        Perisainya bukan klaim keamanan yang dikarang. Seluruh akses
                        SIMAK berjalan di atas HTTPS dan itu syarat yang tercatat di
                        spesifikasi produk, jadi lencana ini menyebut hal yang memang
                        berlaku. Ia juga memberi jeda antara aksi masuk dan blok
                        bantuan di bawahnya, sehingga keduanya tidak terbaca sebagai
                        satu kelompok.
                    -->
                    <div class="pemisah-masuk mt-7 flex items-center gap-3" aria-hidden="true">
                        <span class="h-px flex-1 bg-brand/10 dark:bg-white/10"></span>
                        <span class="grid size-7 place-items-center rounded-full bg-brand/6 text-brand/70 dark:bg-white/10 dark:text-white/60">
                            <ShieldCheck class="size-3.5" :stroke-width="1.75" />
                        </span>
                        <span class="h-px flex-1 bg-brand/10 dark:bg-white/10"></span>
                    </div>

                    <!--
                        Blok bantuan. Nama instansinya ditebalkan, bukan dijadikan
                        tautan, karena tidak ada satu pun alamat kontak yang tercatat
                        sebagai kebenaran produk. Tautan yang tidak menuju ke mana mana
                        lebih buruk daripada teks biasa.
                    -->
                    <div class="blok-bantuan mt-5 flex items-center gap-3">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand/6 text-brand dark:bg-white/10 dark:text-white/80">
                            <Headset class="size-4.5" :stroke-width="1.75" />
                        </span>
                        <p class="text-sm leading-relaxed text-muted-foreground">
                            Belum punya akun? Hubungi admin
                            <span class="font-medium text-foreground">Diskominfo Kota Kendari.</span>
                        </p>
                    </div>
                </div>
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
 * percabangan nada hilang lebih dulu, judul dan lambang hanya mengecil. Empat
 * tahap alur tidak pernah disentuh, itu isi utama panelnya.
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

    /*
     * Tulang punggung alur ikut dipendekkan begitu percabangan nada disembunyikan.
     *
     * Panjangnya disetel sampai tepi bawah daftar karena di layar tinggi ia harus
     * menyambung ke batang percabangan. Tanpa aturan ini, di layar pendek garis
     * itu menjuntai beberapa puluh piksel di bawah simpul terakhir menuju sesuatu
     * yang sudah tidak ada.
     */
    .rel-alur {
        bottom: 1.5rem;
    }
}

/*
 * Kartu masuk pada layar pendek.
 *
 * Sama seperti aturan panel merek di atas, ini membaca tinggi layar, bukan
 * lebarnya. Penyebabnya bukan hanya laptop pendek: begitu pengguna memperbesar
 * halaman lewat zoom peramban, tinggi layar dalam piksel CSS ikut menyusut dan
 * kartu yang tadinya muat mulai bisa digulir.
 *
 * `overflow-y-auto` di panel tetap dipertahankan sebagai jaring pengaman. Yang
 * dikerjakan aturan di bawah adalah membuat isinya benar benar muat, sehingga
 * jaring itu tidak pernah terpakai pada ukuran yang wajar. Menghapus
 * `overflow-y-auto` hanya akan memotong isi kartu, dan itu lebih buruk daripada
 * digulir.
 *
 * Tinggi kolom isian dan tombol tidak pernah turun di bawah 2,75rem, yaitu 44
 * piksel, ambang sasaran sentuh yang wajib dipenuhi.
 */
@media (max-height: 820px) {
    .panel-masuk {
        padding-top: 1.25rem;
        padding-bottom: 1.25rem;
    }

    .kartu-masuk {
        padding: 1.5rem;
    }

    .judul-masuk,
    .form-masuk,
    .pemisah-masuk {
        margin-top: 1.25rem;
    }

    .form-masuk {
        gap: 0.75rem;
    }

    .kolom-isian,
    .tombol-utama {
        height: 2.75rem;
    }

    .blok-bantuan {
        margin-top: 1rem;
    }
}

/*
 * Layar sangat pendek, misalnya zoom 150 persen di laptop 768 piksel atau ponsel
 * dalam posisi mendatar.
 *
 * Perapatan jarak sudah habis di titik ini, jadi yang tersisa adalah membuang
 * isi. Urutannya dari yang paling murah: kop instansi ringkas hilang lebih dulu
 * karena lambang SIMAK di dalam kartu sudah menjelaskan sistem apa yang sedang
 * dimasuki, lalu tagline, lalu kalimat penjelas, lalu pemisah berlencana yang
 * memang hiasan.
 * Blok bantuan bertahan paling lama karena ia satu satunya jalan keluar bagi
 * pengguna yang belum punya akun.
 */
@media (max-height: 640px) {
    .kop-ringkas,
    .tagline-masuk,
    .pemisah-masuk {
        display: none;
    }

    .judul-masuk {
        margin-top: 0.75rem;
    }

    .blok-bantuan {
        margin-top: 1.25rem;
    }
}

/*
 * Bidang panel merek.
 *
 * Tiga lapis, dan urutannya menentukan hasilnya. Paling bawah gradien diagonal
 * dari navy pekat di sudut kiri atas menuju biru yang lebih terbuka di kanan
 * bawah. Di atasnya satu pusat cahaya di sisi kanan, tempat seluruh ornamen
 * berdiri, sehingga garis garisnya punya bidang yang cukup terang untuk
 * terbaca. Paling atas guratan terang tipis di tepi kanan, yang memisahkan
 * panel ini dari panel masuk tanpa perlu garis pembatas.
 *
 * Sisi kiri sengaja dibiarkan paling gelap. Di situ judul dan seluruh teks
 * berdiri, dan teks putih butuh bidang tergelap yang bisa diberikan panel ini.
 */
.panel-merek {
    background-color: var(--color-brand);
    background-image:
        linear-gradient(to right, transparent 64%, oklch(0.55 0.13 248 / 0.5) 100%),
        radial-gradient(76% 60% at 86% 48%, oklch(0.5 0.13 250 / 0.85), transparent 70%),
        linear-gradient(148deg, oklch(0.21 0.055 253) 0%, oklch(0.27 0.075 252) 46%, oklch(0.32 0.09 250) 100%);
}

/*
 * Ladang titik.
 *
 * Warnanya dari `currentColor` supaya satu kelas teks mengatur seluruh
 * kepekatannya. Topeng miringnya memastikan titik terpadat berada di sudut
 * kanan atas dan hilang sepenuhnya sebelum menyentuh kolom teks.
 */
.titik {
    color: rgb(255 255 255 / 0.26);
    background-image: radial-gradient(currentColor 1.2px, transparent 1.2px);
    background-size: 22px 22px;
    -webkit-mask-image: linear-gradient(212deg, #000 6%, transparent 72%);
    mask-image: linear-gradient(212deg, #000 6%, transparent 72%);
}

/*
 * Latar panel masuk.
 *
 * Di bawah 1024 piksel bidangnya navy merek, dengan dua cahaya yang posisi dan
 * rononya sama persis dengan panel merek. Jadi ponsel dan desktop terasa satu
 * halaman, bukan dua rancangan berbeda.
 *
 * Sengaja diam, tidak seperti cahaya di panel merek yang bergeser pelan.
 * Menganimasikan bidang seluas layar penuh di ponsel kelas menengah membakar
 * baterai tanpa ada yang menonton, dan di sini bidangnya adalah seluruh layar.
 */
.panel-masuk {
    background-color: var(--color-brand);
    background-image:
        radial-gradient(36rem 26rem at 12% -10%, oklch(0.62 0.095 235 / 0.34), transparent 64%),
        radial-gradient(34rem 26rem at 92% 104%, oklch(0.54 0.105 255 / 0.3), transparent 64%);
}

/*
 * Mulai 1024 piksel panel merek di sebelah kiri yang memegang navy, dan panel
 * ini berganti menjadi bidang terang bertinta biru. Cahayanya dikumpulkan di
 * sudut kanan atas, arah yang sama dengan cahaya di panel merek, sehingga kedua
 * bidang terbaca disinari satu sumber yang sama.
 */
@media (min-width: 1024px) {
    /*
     * Ditulis `:global(.dark .panel-masuk)`, bukan `:global(.dark) .panel-masuk`.
     *
     * Bentuk kedua terlihat wajar dan justru itu jebakannya. Kompilator gaya
     * scoped Vue menerjemahkannya menjadi `.dark { ... }` polos, tanpa
     * `.panel-masuk` sama sekali. Akibatnya aturan ini menempel di elemen mana
     * pun yang berkelas `dark`, termasuk panel merek di sebelah kiri, dan
     * seluruh bidang navy merek berubah menjadi hampir hitam mulai lebar 1024
     * piksel. Jangan pisah lagi.
     */
    .panel-masuk {
        background-color: oklch(0.958 0.012 240);
        background-image:
            radial-gradient(44rem 32rem at 92% -10%, oklch(0.62 0.11 245 / 0.2), transparent 68%),
            radial-gradient(38rem 28rem at 4% 106%, oklch(0.6 0.09 220 / 0.12), transparent 68%);
    }

    :global(.dark .panel-masuk) {
        background-color: oklch(0.145 0.008 250);
        background-image:
            radial-gradient(44rem 32rem at 92% -10%, oklch(0.72 0.11 245 / 0.14), transparent 68%),
            radial-gradient(38rem 28rem at 4% 106%, oklch(0.7 0.09 220 / 0.12), transparent 68%);
    }
}

/*
 * Bidang tombol utama.
 *
 * Ditulis di sini, bukan sebagai kelas gradien Tailwind, karena kedua ujungnya
 * harus terkunci ke token merek. Ujung kirinya nilai tetap, satu satunya
 * heksadesimal di berkas ini, dan angkanya hasil hitungan kontras: pada
 * `#2a6cae` teks putih di atasnya mencapai 5,4:1, sedangkan biru yang lebih
 * terang sedikit saja sudah jatuh di bawah 4,5:1 yang diwajibkan.
 *
 * Tidak dijadikan token merek karena hanya dipakai di satu tempat. Prinsip
 * produk nomor 6 menyebut ambang sebuah abstraksi adalah dua kali pakai.
 */
.tombol-utama {
    background-image: linear-gradient(100deg, #2a6cae 0%, var(--color-brand) 100%);
    transition:
        filter 200ms cubic-bezier(0.32, 0.72, 0, 1),
        transform 200ms cubic-bezier(0.32, 0.72, 0, 1);
}

.tombol-utama:hover {
    filter: brightness(1.12);
}

/*
 * Panah di dalam tombol utama. Kurvanya sama dengan `.tekan` di app.css supaya
 * geseran panah dan tekanan tombol terasa satu gerakan.
 */
.panah {
    transition: transform 300ms cubic-bezier(0.32, 0.72, 0, 1);
}

/*
 * Cahaya latar bergeser sangat lambat.
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
 * satu satunya gerak yang berulang di panel, dan tugasnya menjelaskan bahwa
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
        background-color: rgb(255 255 255 / 0.07);
        transform: scale(1);
    }

    6% {
        border-color: rgb(255 255 255 / 0.45);
        background-color: rgb(255 255 255 / 0.18);
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
 *
 * Animasi `gambar` sudah dimatikan di app.css beserta `stroke-dasharray` nya,
 * jadi cincin nada dan percabangan nada tetap tergambar utuh.
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

    .panah,
    .tombol-utama {
        transition: none;
    }
}
</style>
