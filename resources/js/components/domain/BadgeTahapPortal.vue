<script setup lang="ts">
import { AlertTriangle, CheckCircle2, Clock, MinusCircle } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Satu-satunya tempat yang boleh menerjemahkan tahap artikel untuk peran media.
 *
 * Sebelumnya petanya tinggal di dalam halaman Tambah berita. Begitu beranda
 * ikut menampilkan tahap yang sama, dua salinan berarti satu layar bisa
 * berbunyi "sedang diproses" untuk berita yang di layar sebelahnya sudah lama
 * diputus di luar pantauan.
 *
 * Menyebut relevansi, bukan sentimen. Hanya sentimen yang dirahasiakan dari
 * media (dokumen 01 bagian 8). Relevansi justru harus terbaca, kalau tidak
 * media tidak punya cara mengetahui mengapa beritanya tidak pernah muncul.
 */
const props = defineProps<{
    tahap: 'tampil' | 'diproses' | 'di_luar_pantauan' | 'gagal';
    /** Label disembunyikan dari mata, tetap terbaca pembaca layar. */
    ringkas?: boolean;
}>();

/**
 * Warna hanya memisahkan dua hal: keadaan akhir yang baik, dan keadaan yang
 * perlu ditindaklanjuti. Pembeda sebenarnya adalah ikon dan teksnya, sesuai
 * aturan bahwa warna tidak boleh menjadi satu-satunya penanda.
 *
 * Tidak satu pun memakai token sentimen. Di aplikasi ini hijau, kuning, dan
 * merah sentimen berarti nada pemberitaan, dan lencana tahap yang meminjam
 * warna itu akan terbaca seolah beritanya sendiri yang bernada.
 *
 * Semua pasangan warna di bawah sudah diperiksa di mode terang dan gelap,
 * paling rendah 4,8:1.
 */
const varian = computed(
    () =>
        ({
            tampil: {
                teks: 'Tampil',
                judul: 'Sudah dinilai relevan dan tampil di halaman Berita saya',
                ikon: CheckCircle2,
                kelas: 'bg-primary text-primary-foreground',
            },
            diproses: {
                teks: 'Sedang diproses',
                judul: 'Sudah masuk antrean penilaian, belum tampil di Berita saya sampai penilaiannya selesai',
                ikon: Clock,
                kelas: 'bg-muted text-foreground/75',
            },
            di_luar_pantauan: {
                teks: 'Di luar pantauan',
                judul: 'Dinilai bukan berita Pemerintah Kota Kendari, jadi tidak masuk Berita saya',
                ikon: MinusCircle,
                kelas: 'bg-muted text-foreground/75',
            },
            gagal: {
                teks: 'Gagal dibaca',
                judul: 'Halamannya tidak bisa diunduh sistem, jadi penilaiannya tidak pernah berjalan',
                ikon: AlertTriangle,
                kelas: 'bg-muted text-destructive',
            },
        })[props.tahap],
);
</script>

<template>
    <span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium" :class="varian.kelas" :title="varian.judul">
        <component :is="varian.ikon" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        <span :class="ringkas ? 'sr-only' : ''">{{ varian.teks }}</span>
    </span>
</template>
