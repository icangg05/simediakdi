<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { Component } from 'vue';
import { computed } from 'vue';

/**
 * Kartu seksi bertanda rona, satu bentuk untuk seluruh halaman Pengaturan.
 *
 * Ditarik jadi komponen setelah pemakaiannya menyentuh enam kartu di satu
 * halaman. Sebelum itu kepala kartunya disalin utuh di tiap tempat, dan
 * menggeser satu ukuran tile berarti menyunting enam blok yang harus sepakat.
 * Prinsip produk nomor 6 menyebut ambangnya dua kali pakai.
 *
 * **Ronanya bukan selera, ia menyebut jenis pekerjaan kartunya.** Arti yang
 * dipakai halaman Pengaturan, dan yang harus dipakai halaman mana pun yang
 * ikut memakai komponen ini:
 *
 * | Rona     | Arti                                                  |
 * |----------|-------------------------------------------------------|
 * | `biru`   | Pemantauan dan keterangan, tidak ada yang bisa diubah |
 * | `ungu`   | Pekerjaan yang memakai kuota Gemini                   |
 * | `toska`  | Penilaian yang dikerjakan di server sendiri           |
 * | `brand`  | Kanal resmi keluar, pesan yang dikirim atas nama Pemkot |
 * | `netral` | Nilai terkunci, hanya bisa diubah lewat `.env`        |
 *
 * Ketiga rona pertama sama persis dengan arti yang sudah dipakai halaman Model
 * Relevansi dan halaman Antrean AI. Kalau arti sebuah rona berubah, berkas ini
 * dan halaman itu harus diubah bersamaan.
 *
 * Warna tidak pernah menjadi penanda tunggal. Setiap kartu wajib mengisi
 * `ikon`, dan judulnya menyebutkan sendiri apa yang dikerjakannya.
 */
const props = withDefaults(
    defineProps<{
        judul: string;
        /** Kalimat penjelas di bawah judul. Boleh kosong untuk kartu pendek. */
        catatan?: string;
        ikon: Component;
        rona?: 'biru' | 'ungu' | 'toska' | 'brand' | 'netral';
        /**
         * Ada pekerjaan yang sedang berjalan di dalam kartu ini.
         *
         * Menyalakan cahaya yang menyusuri garis kepala kartu. Kelasnya hanya
         * boleh menempel selama memang ada yang berjalan: garis yang terus
         * bergerak pada kartu yang diam berbohong lebih keras daripada teks
         * apa pun yang bisa ditulis di sebelahnya.
         */
        bekerja?: boolean;
        /** Isi menempel ke tepi kartu, untuk daftar yang punya pembatasnya sendiri. */
        padat?: boolean;
    }>(),
    { catatan: undefined, rona: 'netral', bekerja: false, padat: false },
);

/**
 * Rona diambil dari token, bukan dari heksadesimal.
 *
 * Nilai `warna` di bawah menunjuk variabel CSS yang sama dengan yang dibaca
 * kelas Tailwind di sebelahnya, jadi kabut dan tile tidak akan pernah bergeser
 * satu sama lain saat token dicerahkan untuk mode gelap.
 *
 * `brand` memakai varian terang, bukan navy penuh. Navy pekat sebagai kabut di
 * atas kartu gelap hanya menghasilkan bidang yang lebih gelap dari kartunya,
 * dan itu terbaca sebagai noda, bukan cahaya.
 */
const VARIAN = {
    biru: { tile: 'bg-aksen-biru/10 text-aksen-biru ring-aksen-biru/20', warna: 'var(--color-aksen-biru)' },
    ungu: { tile: 'bg-aksen-ungu/10 text-aksen-ungu ring-aksen-ungu/20', warna: 'var(--color-aksen-ungu)' },
    toska: { tile: 'bg-aksen-toska/10 text-aksen-toska ring-aksen-toska/20', warna: 'var(--color-aksen-toska)' },
    brand: { tile: 'bg-brand-lembut text-brand ring-brand/20 dark:text-white', warna: 'var(--color-brand-terang)' },
    netral: { tile: 'bg-muted text-muted-foreground ring-border', warna: 'var(--color-sentimen-netral)' },
} as const;

const varian = computed(() => VARIAN[props.rona]);

/*
 * Kabut rona di puncak kartu.
 *
 * Satu sapuan yang memudar sebelum menyentuh isi, bukan bidang berwarna. Kartu
 * di halaman ini berjajar enam ke bawah dengan bentuk yang sama persis, dan
 * tanpa penanda yang tertangkap dari sudut mata seluruhnya melebur menjadi
 * satu kolom putih panjang. Kepekatannya sengaja rendah: yang dicari adalah
 * arah, bukan bidang kedua yang harus dibaca.
 */
const kabut = computed(() => ({
    background: `radial-gradient(28rem 10rem at 4% -60%, color-mix(in oklab, ${varian.value.warna} 22%, transparent), transparent 72%)`,
}));

/** Garis rona di tepi atas kartu, tumbuh dari kiri saat halaman terbuka. */
const garis = computed(() => ({
    background: `linear-gradient(90deg, color-mix(in oklab, ${varian.value.warna} 80%, transparent), transparent)`,
}));
</script>

<template>
    <Card class="relative overflow-hidden">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-40" :style="kabut" aria-hidden="true"></div>

        <!-- Garis kepala. Wadahnya yang menyembunyikan luapan, supaya cahaya
             `.aliran` berhenti di tepi kartu alih-alih melintasinya. -->
        <div class="tumbuh pointer-events-none absolute inset-x-0 top-0 h-px overflow-hidden" aria-hidden="true">
            <div class="h-full w-full" :class="bekerja ? 'aliran relative' : ''" :style="garis"></div>
        </div>

        <CardHeader class="relative space-y-0 border-b p-4 sm:p-5">
            <div class="flex items-start gap-3">
                <span class="grid size-8 shrink-0 place-items-center rounded-lg ring-1 ring-inset" :class="varian.tile">
                    <component :is="ikon" class="size-4" aria-hidden="true" />
                </span>

                <div class="min-w-0 flex-1 space-y-1">
                    <CardTitle class="text-sm leading-tight font-semibold">{{ judul }}</CardTitle>
                    <p v-if="catatan" class="text-xs leading-relaxed text-pretty text-muted-foreground">{{ catatan }}</p>
                </div>

                <div v-if="$slots.aksi" class="flex shrink-0 items-center gap-2">
                    <slot name="aksi" />
                </div>
            </div>
        </CardHeader>

        <CardContent class="relative" :class="padat ? 'p-0' : 'p-4 sm:p-5'">
            <slot />
        </CardContent>
    </Card>
</template>
