<script setup lang="ts">
/**
 * Siluet Kota Kendari untuk sudut kanan blok kesimpulan.
 *
 * Bentuknya diambil dari penanda kota yang dikenali warga: kubah dan menara
 * Masjid Al-Alam, tugu di alun-alun, gedung pemerintahan berpilar, pohon
 * kelapa, dan riak Teluk Kendari di bawahnya. Bukan peta, bukan foto, dan tidak
 * pernah mewakili angka apa pun.
 *
 * Semua warnanya turunan navy merek. Palet sentimen sengaja tidak dipakai di
 * sini: hijau, merah, dan kuning di halaman ini selalu berarti nada
 * pemberitaan, dan hiasan tidak boleh meminjam arti itu. Satu-satunya
 * pengecualian toska pada dedaunan, rona yang tidak dipakai satu label pun.
 *
 * aria-hidden: tidak ada informasi di dalamnya yang tidak sudah tertulis
 * sebagai teks di sebelahnya.
 */
</script>

<template>
    <svg class="ilustrasi-kota" viewBox="0 0 400 210" fill="none" aria-hidden="true" preserveAspectRatio="xMidYMax meet">
        <!-- Lingkaran langit, memberi kedalaman tanpa menambah objek. -->
        <circle cx="322" cy="52" r="76" class="fill-brand/[0.05]" />
        <circle cx="322" cy="52" r="50" class="fill-brand/[0.05]" />

        <!-- Awan. -->
        <g class="fill-brand/[0.07]">
            <path d="M36 54h44a12 12 0 0 0 0-24 18 18 0 0 0-33-7 13 13 0 0 0-11 31Z" />
            <path d="M250 34h30a9 9 0 0 0 0-18 13 13 0 0 0-24-5 9 9 0 0 0-6 23Z" />
        </g>

        <!-- Menara latar belakang. -->
        <g class="fill-brand/10">
            <rect x="8" y="116" width="30" height="64" rx="2" />
            <rect x="44" y="98" width="22" height="82" rx="2" />
            <rect x="330" y="106" width="28" height="74" rx="2" />
            <rect x="362" y="124" width="26" height="56" rx="2" />
            <rect x="300" y="130" width="24" height="50" rx="2" />
        </g>

        <!-- Masjid: kubah, badan, dua menara. -->
        <g class="fill-brand/[0.18]">
            <rect x="90" y="122" width="11" height="58" rx="3" />
            <circle cx="95.5" cy="120" r="6" />
            <rect x="187" y="122" width="11" height="58" rx="3" />
            <circle cx="192.5" cy="120" r="6" />
            <path d="M110 148a34 34 0 0 1 68 0Z" />
            <rect x="108" y="148" width="72" height="32" rx="2" />
            <rect x="141" y="98" width="6" height="14" rx="3" />
            <circle cx="144" cy="94" r="6" />
        </g>

        <!-- Tugu alun-alun. -->
        <g class="fill-brand/25">
            <path d="M203 172l4-84h8l4 84z" />
            <circle cx="211" cy="80" r="8" />
            <rect x="194" y="170" width="34" height="10" rx="2" />
        </g>

        <!-- Gedung pemerintahan berpilar. -->
        <g>
            <path d="M228 128l38-22 38 22z" class="fill-brand/25" />
            <rect x="234" y="128" width="64" height="52" class="fill-brand/[0.18]" />
            <g class="fill-brand/[0.08]">
                <rect x="242" y="140" width="7" height="40" />
                <rect x="257" y="140" width="7" height="40" />
                <rect x="272" y="140" width="7" height="40" />
                <rect x="287" y="140" width="7" height="40" />
            </g>
            <rect x="226" y="176" width="80" height="4" rx="2" class="fill-brand/25" />
        </g>

        <!-- Blok terdepan, warnanya paling pekat supaya kedalamannya terbaca. -->
        <g>
            <rect x="52" y="142" width="40" height="38" rx="2" class="fill-brand/30" />
            <g class="jendela fill-white/40">
                <rect x="59" y="150" width="7" height="8" rx="1" />
                <rect x="70" y="150" width="7" height="8" rx="1" />
                <rect x="81" y="150" width="7" height="8" rx="1" />
                <rect x="59" y="164" width="7" height="8" rx="1" />
                <rect x="70" y="164" width="7" height="8" rx="1" />
                <rect x="81" y="164" width="7" height="8" rx="1" />
            </g>
        </g>

        <!-- Pohon kelapa. -->
        <g class="stroke-aksen-toska/45" stroke-width="3.5" stroke-linecap="round">
            <path d="M318 180c0-18 2-30 8-38" />
            <path d="M326 142c-12-6-22-4-28 4M326 142c12-6 23-4 28 5M326 142c-6-11-4-20 3-26M326 142c9-8 19-8 26-2" />
            <path d="M32 180c0-14 2-24 7-31" />
            <path d="M39 149c-10-5-18-3-23 4M39 149c10-5 19-3 23 4M39 149c-5-9-3-17 3-21" />
        </g>

        <!-- Riak Teluk Kendari. -->
        <g class="stroke-brand/20" stroke-width="2.5" stroke-linecap="round">
            <path d="M14 190q14-7 28 0t28 0" />
            <path d="M96 198q14-7 28 0t28 0" />
            <path d="M186 190q14-7 28 0t28 0" />
            <path d="M282 198q14-7 28 0t28 0" />
        </g>
    </svg>
</template>

<style>
/*
 * Navy pekat di atas kartu gelap sama saja dengan tidak menggambar apa pun.
 * Ronanya dinaikkan khusus di dalam ilustrasi ini, tidak menyentuh token merek
 * yang dipakai kop halaman.
 */
.dark .ilustrasi-kota {
    --color-brand: oklch(0.72 0.07 250);
}

/*
 * Siluet di atas bidang navy, misalnya kop dashboard eksekutif.
 *
 * Seluruh isi berkas ini digambar dengan `fill-brand/…`, jadi satu penimpaan
 * variabel cukup untuk membalik ilustrasinya menjadi putih tembus pandang.
 * Tidak ada satu pun bentuk yang perlu digambar dua kali, dan kepekatan
 * relatif antar lapisnya, yang menjadi kedalaman gambar ini, tetap utuh.
 *
 * Berlaku sama di kedua mode. Kop tetap navy saat mode gelap dinyalakan, jadi
 * penimpaan `.dark` di atas justru salah untuk pemakaian ini dan sengaja
 * ditulis ulang di sini.
 */
.ilustrasi-kota-navy,
.dark .ilustrasi-kota-navy {
    --color-brand: oklch(0.99 0.005 250);
    --color-aksen-toska: oklch(0.9 0.06 200);
}

/*
 * Jendela blok terdepan dibalik menjadi gelap.
 *
 * Di atas kartu terang jendelanya putih supaya terbaca sebagai lubang cahaya
 * pada blok navy. Begitu bloknya sendiri menjadi putih tembus pandang,
 * jendela putih hilang ke dalamnya, dan yang terbaca sebagai lubang justru
 * warna navy kopnya sendiri.
 */
.ilustrasi-kota-navy .jendela {
    fill: oklch(0.364 0.09 253.3 / 0.5);
}
</style>
