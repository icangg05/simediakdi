<script setup lang="ts">
/**
 * Siluet Jembatan Teluk Kendari, untuk kaki panel merek halaman masuk.
 *
 * Bentuknya diambil dari penanda kota yang paling dikenali dari kejauhan:
 * satu pilon jangkung dengan kabel yang memanjar ke dua arah, lantai jembatan
 * yang sedikit melengkung, dan riak teluk di bawahnya. Deretan blok rendah di
 * kiri hanya latar kota, bukan bangunan tertentu.
 *
 * Bukan peta, bukan foto, dan tidak pernah mewakili angka apa pun.
 *
 * Berbeda dengan `KotaKendari.vue` yang mewarnai dirinya sendiri lewat token
 * merek, seluruh isi berkas ini digambar dengan `currentColor`. Ilustrasi ini
 * hanya pernah berdiri di atas bidang navy, jadi pemanggil cukup menyetel satu
 * kelas warna teks dan seluruh garisnya ikut. Kepekatan relatif antar lapis,
 * yang menjadi kedalaman gambar ini, dibawa `opacity` per kelompok.
 *
 * aria-hidden: tidak ada informasi di dalamnya yang tidak sudah tertulis
 * sebagai teks di sebelahnya.
 */
</script>

<template>
    <svg viewBox="0 0 340 200" fill="none" aria-hidden="true" preserveAspectRatio="xMaxYMax meet">
        <g stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" fill="none">
            <!-- Latar kota di kiri. Paling samar, karena ia yang paling jauh. -->
            <g opacity="0.28" stroke-width="1.5">
                <path d="M10 134v-26h16v26" />
                <path d="M30 134v-38h13v38" />
                <path d="M47 134v-18h15v18" />
                <path d="M300 134v-30h14v30" />
                <path d="M318 134v-20h13v20" />
            </g>

            <!-- Kabel penahan, memanjar dari puncak pilon ke lantai jembatan. -->
            <g opacity="0.45" stroke-width="1">
                <path d="M176 34 44 129" />
                <path d="M176 34 80 127" />
                <path d="M176 34 116 125" />
                <path d="M176 34 148 124" />
                <path d="M176 34 204 124" />
                <path d="M176 34 236 125" />
                <path d="M176 34 272 127" />
                <path d="M176 34 308 129" />
            </g>

            <!-- Tiang penyangga di bawah lantai. -->
            <g opacity="0.4" stroke-width="2">
                <path d="M62 131v35" />
                <path d="M118 129v37" />
                <path d="M234 129v37" />
                <path d="M290 131v35" />
            </g>

            <!-- Lantai jembatan. Lengkungnya sangat landai, seperti aslinya. -->
            <path d="M4 134Q170 111 336 134" opacity="0.7" stroke-width="2.5" />

            <!-- Pilon. Dua kaki bertemu di puncak, dua palang mengikatnya. -->
            <g opacity="0.85" stroke-width="2.5">
                <path d="M164 132 176 28" />
                <path d="M188 132 176 28" />
                <path d="M170 74h12" />
                <path d="M167 102h18" />
            </g>

            <!-- Riak teluk. Tiga jalur, makin ke bawah makin samar. -->
            <g stroke-width="2">
                <path d="M22 158q16-7 32 0t32 0" opacity="0.3" />
                <path d="M132 158q16-7 32 0t32 0" opacity="0.3" />
                <path d="M244 158q16-7 32 0t32 0" opacity="0.3" />
                <path d="M74 178q16-7 32 0t32 0" opacity="0.18" />
                <path d="M190 178q16-7 32 0t32 0" opacity="0.18" />
            </g>
        </g>
    </svg>
</template>
