<script setup lang="ts">
/**
 * Kop navy merek, satu bentuk untuk seluruh panel admin.
 *
 * Ditarik jadi komponen setelah pemakaiannya menyentuh tujuh halaman. Sebelum
 * itu ornamennya disalin utuh di tiap berkas, sekitar dua puluh baris sekali
 * salin, dan menggeser satu busur berarti menyunting tujuh tempat yang harus
 * sepakat. Prinsip produk nomor 6 menyebut ambangnya dua kali pakai, dan ini
 * jauh melewatinya.
 *
 * Tiga bidang isi, semuanya opsional kecuali judul:
 *
 * - `aksi`: tombol di sisi kanan judul.
 * - slot bawaan: baris pil keterangan, di bawah garis merek.
 * - `bawah`: apa pun yang butuh lebar penuh, misalnya daftar keterangan.
 */
defineProps<{
    judul: string;
    keterangan?: string;
}>();
</script>

<template>
    <header class="muncul relative overflow-hidden rounded-xl bg-brand text-white shadow-lg ring-1 shadow-brand/20 ring-brand-terang/40">
        <!--
            Ornamen kop: sapuan cahaya dan busur sepusat.

            Keduanya digambar, bukan pola berulang. Pola yang mengulang di
            belakang teks putih menjadi unsur kedua yang harus dibaca mata,
            sedangkan busur yang melebar keluar layar hanya memberi arah tanpa
            pernah menuntut perhatian. `pointer-events-none` supaya lapisan ini
            tidak pernah mencuri klik dari tombol di atasnya.
        -->
        <div
            class="pointer-events-none absolute inset-0"
            aria-hidden="true"
            style="
                background:
                    radial-gradient(38rem 22rem at 88% -30%, rgb(255 255 255 / 0.16), transparent 70%),
                    radial-gradient(30rem 20rem at 4% 120%, rgb(255 255 255 / 0.08), transparent 70%);
            "
        ></div>

        <svg class="pointer-events-none absolute -top-24 -right-16 size-80 opacity-[0.18]" viewBox="0 0 200 200" fill="none" aria-hidden="true">
            <circle cx="150" cy="60" r="38" stroke="currentColor" stroke-width="1" />
            <circle cx="150" cy="60" r="62" stroke="currentColor" stroke-width="1" />
            <circle cx="150" cy="60" r="88" stroke="currentColor" stroke-width="1" />
            <circle cx="150" cy="60" r="116" stroke="currentColor" stroke-width="1" />
        </svg>

        <div class="relative space-y-4 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 space-y-1">
                    <h1 class="max-w-[46ch] text-2xl leading-tight font-semibold tracking-tight text-balance">{{ judul }}</h1>
                    <p v-if="keterangan" class="max-w-[62ch] text-sm text-white/70">{{ keterangan }}</p>
                </div>

                <div v-if="$slots.aksi" class="flex shrink-0 flex-wrap items-center gap-2">
                    <slot name="aksi" />
                </div>
            </div>

            <!-- Garis merek, tumbuh dari kiri saat halaman terbuka. Ia
                 memisahkan judul dari keterangan tanpa menambah satu baris teks
                 pun. -->
            <div class="tumbuh h-px w-24 bg-linear-to-r from-white/70 to-transparent" aria-hidden="true"></div>

            <div v-if="$slots.default" class="flex flex-wrap items-center gap-2">
                <slot />
            </div>

            <slot name="bawah" />
        </div>
    </header>
</template>
