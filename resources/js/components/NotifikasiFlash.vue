<script setup lang="ts">
/**
 * Toast pesan hasil aksi, dipasang sekali di akar aplikasi.
 *
 * Letaknya di luar pohon halaman, dan itu memperbaiki bug yang bikin toast
 * kadang lenyap sebelum durasinya habis. Adapter Inertia memberi komponen
 * halaman sebuah `key` yang berubah menjadi `Date.now()` pada setiap kunjungan
 * tanpa `preserveState`, jadi seluruh pohon di bawahnya dibongkar dan dipasang
 * ulang. `Toaster` bawaan vue-sonner memulai daftar toast-nya dari array kosong
 * saat dipasang, sehingga toast yang sedang tampil ikut hilang seketika. Selama
 * `Toaster` tinggal di dalam AppLayout, menekan Klasifikasi pada baris kedua
 * membuang toast baris pertama di tengah jalan, dan itu terbaca sebagai durasi
 * yang tidak menentu.
 */
import { Toaster } from '@/components/ui/sonner';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { h, onMounted, watch } from 'vue';
import { toast } from 'vue-sonner';

const page = usePage<SharedData>();

const DURASI = 4500;

/**
 * Galat bertahan lebih lama daripada sukses.
 *
 * Isinya menyebut tindakan yang perlu diambil, misalnya kunci Gemini yang
 * kuotanya habis, dan itu tidak selalu terbaca dalam empat setengah detik.
 */
const DURASI_GALAT = 8000;

/**
 * Warna hanya di border, badannya tetap putih.
 *
 * Awalan `group-[.toaster]:` wajib, bukan hiasan. Sonner.vue menempelkan
 * `group-[.toaster]:border-border` pada setiap toast, dan tailwind-merge hanya
 * membuang kelas lama kalau kelas barunya memakai varian yang sama persis.
 * Tanpa awalan itu keduanya lolos, lalu selector bervarian menang karena
 * spesifisitasnya lebih tinggi, dan seluruh toast kembali berwarna abu.
 */
const BORDER = {
    hijau: 'group-[.toaster]:border-2 group-[.toaster]:border-emerald-500',
    merah: 'group-[.toaster]:border-2 group-[.toaster]:border-rose-500',
    // Kuning untuk yang belum diputuskan. Bukan hijau dan bukan merah, karena
    // Gemini tidak menjawab relevan maupun tidak relevan, melainkan menolak
    // menjawab dan menyerahkannya ke manusia.
    kuning: 'group-[.toaster]:border-2 group-[.toaster]:border-amber-500',
};

/** Border mengikuti nada, dengan hijau sebagai bawaan untuk sukses biasa. */
const BORDER_NADA: Record<string, string> = {
    tidak_relevan: BORDER.merah,
    perlu_review: BORDER.kuning,
};

/**
 * Warna kata sentimen saja, bukan seluruh baris keterangannya.
 *
 * Ditempel pada `span` di dalam keterangan, jadi tidak perlu awalan varian
 * seperti border di atas. Warna yang ditulis langsung pada sebuah elemen selalu
 * menang atas warna yang diwarisi dari induknya, sekuat apa pun selector induk
 * itu.
 */
const WARNA_SENTIMEN: Record<string, string> = {
    positif: 'font-medium text-emerald-600 dark:text-emerald-400',
    netral: 'font-medium text-slate-600 dark:text-slate-400',
    negatif: 'font-medium text-rose-600 dark:text-rose-400',
};

/** Warna keraguan, sama dengan border kuning yang dipakai nada perlu review. */
const WARNA_RAGU = 'font-medium text-amber-600 dark:text-amber-400';

const kapital = (teks: string) => teks.charAt(0).toUpperCase() + teks.slice(1);

/** Kata pembuka keterangan, satu per hasil relevansi. */
const LABEL_NADA: Record<string, string> = {
    relevan: 'Relevan',
    tidak_relevan: 'Tidak relevan',
    perlu_review: 'Perlu review, relevansi belum diputuskan',
};

/**
 * Keterangan toast: hasil relevansi, lalu sentimen bila ada.
 *
 * Dirakit sebagai komponen, bukan string, karena hanya kata sentimennya yang
 * diberi warna. Satu kalimat utuh tidak bisa diwarnai sebagian.
 *
 * Sentimen hanya menyusul pada artikel relevan. Yang tidak relevan tidak pernah
 * dinilai nadanya, dan yang perlu review bahkan belum sampai ke pertanyaan itu.
 *
 * Artikel relevan yang sentimennya kosong bukan artikel yang belum dinilai.
 * Sentimen selalu dijalankan begitu relevansinya berbunyi relevan, jadi label
 * yang kosong berarti Gemini menjalankannya lalu menolak memutuskan nadanya.
 */
function keterangan(flash: SharedData['flash']) {
    const catatan = flash?.catatan ? `${flash.catatan}. ` : '';
    const nada = flash?.nada;

    if (!nada) return catatan ? { render: () => h('span', catatan.trim()) } : undefined;

    const sentimen = nada === 'relevan' ? flash?.sentimen : null;
    const sentimenRagu = nada === 'relevan' && !sentimen;

    return {
        render: () =>
            h('span', [
                catatan,
                LABEL_NADA[nada] ?? nada,
                ...(sentimen ? ['. Sentimen ', h('span', { class: WARNA_SENTIMEN[sentimen] }, kapital(sentimen))] : []),
                ...(sentimenRagu ? ['. Sentimen ', h('span', { class: WARNA_RAGU }, 'perlu review')] : []),
            ]),
    };
}

function tampilkan(flash: SharedData['flash']) {
    // Tautan opsional dari controller, dirender sebagai tombol di dalam
    // toast. Dipakai aksi yang memindahkan barisnya keluar dari layar:
    // tanpa tombol ini admin tahu apa yang terjadi tetapi tidak punya cara
    // membuka datanya lagi selain mencarinya kembali.
    const tautan = flash?.tautan;
    const aksi = tautan ? { action: { label: tautan.label, onClick: () => router.visit(tautan.url) } } : {};

    if (flash?.sukses) {
        toast.success(flash.sukses, {
            duration: DURASI,
            classes: { toast: BORDER_NADA[flash.nada ?? ''] ?? BORDER.hijau },
            description: keterangan(flash),
            ...aksi,
        });
    }

    if (flash?.galat) {
        toast.error(flash.galat, {
            duration: DURASI_GALAT,
            classes: { toast: BORDER.merah },
            ...aksi,
        });
    }
}

// `nonce` ikut diamati, bukan hiasan: dua pesan yang isinya sama persis
// menghasilkan nilai watch yang identik, dan toast kedua tidak pernah muncul.
watch(
    () => [page.props.flash?.nonce, page.props.flash?.sukses, page.props.flash?.galat],
    () => tampilkan(page.props.flash),
);

/**
 * Flash bawaan muat penuh dibaca setelah dipasang, bukan lewat `immediate`.
 *
 * `Toaster` baru mendaftarkan dirinya ke antrean vue-sonner saat komponen anak
 * ini disiapkan, yaitu setelah setup induknya selesai. Watch dengan `immediate`
 * berjalan lebih dulu, jadi toast-nya diterbitkan ke daftar pelanggan yang masih
 * kosong lalu hilang tanpa jejak. Itu bukan kasus langka: setiap kali versi aset
 * berubah, kunjungan Inertia berikutnya dijawab 409 dan berubah menjadi muat
 * penuh, sehingga toast hasil klasifikasi yang barusan berjalan tidak pernah
 * tampil. `onMounted` induk berjalan setelah anaknya, jadi pelanggannya sudah ada.
 */
onMounted(() => tampilkan(page.props.flash));
</script>

<template>
    <!-- Tanpa `rich-colors`. Varian kaya bawaan sonner mewarnai seluruh badan
         toast menurut jenisnya, dan itu menutup perbedaan yang justru ingin
         dibaca: hijau untuk relevan dan merah untuk tidak relevan. -->
    <Toaster position="top-right" />
</template>
