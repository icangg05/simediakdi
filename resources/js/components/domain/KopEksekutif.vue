<script setup lang="ts">
import KotaKendari from '@/components/ilustrasi/KotaKendari.vue';

/**
 * Kop navy panel eksekutif, satu bentuk untuk keempat halamannya.
 *
 * Ditarik jadi komponen setelah pemakaiannya menyentuh empat halaman. Sebelum
 * itu ornamennya disalin utuh di tiap berkas, sekitar enam puluh baris sekali
 * salin, dan menggeser satu busur berarti menyunting empat tempat yang harus
 * sepakat. Prinsip produk nomor 6 menyebut ambangnya dua kali pakai.
 *
 * Sengaja bukan `KopHalaman`. Komponen itu melayani panel admin, yang kopnya
 * berdiri di atas bilah breadcrumb dan tidak pernah memuat kendali maupun
 * angka. Kop ini punya dua lapis: cangkang navy untuk identitas dan kalimat,
 * dan inti terang di dalamnya untuk angka yang bisa ditekan. Menggabungkan
 * keduanya menjadi satu komponen berarti satu berkas yang harus melayani dua
 * bentuk halaman yang berbeda.
 *
 * Empat bidang isi, semuanya opsional kecuali judul:
 *
 * - `kendali`: apa pun yang bisa diatur pengguna, misalnya rentang tanggal.
 *   Diletakkan di dalam talam terang, karena seluruh kendali di aplikasi ini
 *   berwarna untuk latar terang dan akan hilang kalau ditempel ke navy.
 * - `pil`: baris lencana keterangan, di bawah garis merek.
 * - slot bawaan: kalimat pembuka di atas navy.
 * - `inti`: bidang terang di kaki kop, untuk angka pembuka halaman.
 */
withDefaults(
    defineProps<{
        judul: string;
        keterangan?: string;
        /**
         * Siluet Kota Kendari di sudut kanan.
         *
         * Hanya untuk halaman ringkasan. Ia penanda halaman muka panel ini, dan
         * mengulangnya di keempat halaman membuatnya berhenti menandai apa pun.
         */
        siluet?: boolean;
    }>(),
    { keterangan: undefined, siluet: false },
);

/**
 * Busur sepusat di sudut kop, digambar saat halaman terbuka.
 *
 * Panjang tiap busur dihitung di sini, bukan ditulis tangan di templat.
 * `stroke-dasharray` tidak bisa membaca panjang lintasan sendiri lewat CSS,
 * dan angka yang meleset membuat busurnya berhenti setengah jalan.
 */
const BUSUR = [38, 62, 88, 116].map((jari, urutan) => ({
    jari,
    panjang: Math.ceil(2 * Math.PI * jari),
    jeda: 200 + urutan * 130,
}));
</script>

<template>
    <!--
        Bentuknya dua lapis seperti pelat yang duduk di dalam talamnya.
        Cangkang navy membawa identitas, kalimat, dan kendali, sedangkan inti
        terang di dalamnya membawa angka. Pemisahan itu bukan hiasan: yang di
        cangkang dibaca sekilas, yang di inti bisa ditekan sampai ke daftar
        beritanya, dan keduanya memang dua cara membaca yang berbeda.

        Jari-jari inti dihitung dari jari-jari cangkang dikurangi tebal
        talamnya, supaya kedua lengkungnya sepusat dan tidak terlihat seperti
        dua kotak yang kebetulan bertumpuk.
    -->
    <header class="muncul relative overflow-hidden rounded-2xl bg-brand p-1.5 text-white shadow-xl ring-1 shadow-brand/25 ring-brand-terang/40">
        <!--
            Ornamen kop, dan tidak satu pun berupa pola berulang. Pola yang
            mengulang di belakang teks putih menjadi unsur kedua yang harus
            dibaca mata, sedangkan sapuan cahaya dan busur yang melebar keluar
            bidang hanya memberi arah tanpa menuntut perhatian.
            `pointer-events-none` supaya lapisan ini tidak pernah mencuri klik
            dari kendali di atasnya.
        -->
        <div
            class="pointer-events-none absolute inset-0"
            aria-hidden="true"
            style="
                background:
                    radial-gradient(42rem 24rem at 86% -32%, rgb(255 255 255 / 0.18), transparent 70%),
                    radial-gradient(32rem 22rem at 2% 118%, rgb(255 255 255 / 0.1), transparent 70%);
            "
        ></div>

        <!--
            Di layar sempit busurnya digeser lebih jauh keluar bidang dan
            dikecilkan. Kop selebar 358 piksel membuat busur yang sama menyapu
            persis melintasi judul, dan garis yang memotong huruf lebih terasa
            sebagai cacat cetak daripada ornamen.
        -->
        <svg
            class="pointer-events-none absolute -top-40 -right-32 size-88 text-white/25 sm:-top-32 sm:-right-24 sm:size-104"
            viewBox="0 0 200 200"
            fill="none"
            aria-hidden="true"
        >
            <circle
                v-for="busur in BUSUR"
                :key="busur.jari"
                class="gambar"
                cx="150"
                cy="60"
                :r="busur.jari"
                stroke="currentColor"
                stroke-width="0.75"
                :style="{ '--panjang': busur.panjang, animationDelay: `${busur.jeda}ms` }"
            />
        </svg>

        <KotaKendari v-if="siluet" class="ilustrasi-kota-navy pointer-events-none absolute top-1 right-3 hidden w-[34%] max-w-[360px] md:block" />

        <div class="relative space-y-4 p-4 pb-5 sm:p-5 sm:pb-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 space-y-1">
                    <h1 class="max-w-[24ch] text-2xl leading-tight font-semibold tracking-tight text-balance sm:text-[1.75rem]">{{ judul }}</h1>
                    <p v-if="keterangan" class="max-w-[62ch] text-sm text-white/75">{{ keterangan }}</p>
                </div>

                <!--
                    Kendali duduk di dalam talam terang miliknya sendiri.
                    Isinya komponen yang dipakai beberapa halaman dan seluruh
                    warnanya disetel untuk latar terang, jadi menempelkannya
                    langsung ke navy akan menghasilkan tombol abu di atas biru
                    tua yang labelnya nyaris hilang.

                    Talamnya melebar penuh di layar sempit. Deretan pintasan
                    tanggal lebih lebar daripada kop pada 390 piksel, dan tanpa
                    lebar penuh tombol terakhirnya terpotong tepi kartu.
                -->
                <div
                    v-if="$slots.kendali"
                    class="w-full min-w-0 rounded-xl bg-background p-1 text-foreground shadow-lg ring-1 shadow-brand/30 ring-white/25 sm:w-auto sm:shrink-0"
                >
                    <slot name="kendali" />
                </div>
            </div>

            <!-- Garis merek, tumbuh dari kiri saat halaman terbuka. Ia
                 memisahkan judul dari keterangan tanpa menambah satu baris teks
                 pun. -->
            <div class="tumbuh h-px w-28 bg-linear-to-r from-white/70 to-transparent" aria-hidden="true"></div>

            <div v-if="$slots.pil" class="flex flex-wrap items-center gap-2">
                <slot name="pil" />
            </div>

            <div v-if="$slots.default" class="max-w-[60ch] text-sm leading-relaxed text-pretty text-white/85">
                <slot />
            </div>
        </div>

        <!--
            Inti terang, dan satu-satunya blok halaman yang masuk dengan gerakan
            berbeda. Kalau setiap blok masuk dengan gerakan yang sama, tidak ada
            satu pun yang terbaca sebagai pembuka.
        -->
        <div
            v-if="$slots.inti"
            class="tajam relative space-y-4 rounded-[0.625rem] bg-card p-4 text-card-foreground sm:p-5"
            style="animation-delay: 160ms"
        >
            <slot name="inti" />
        </div>
    </header>
</template>
