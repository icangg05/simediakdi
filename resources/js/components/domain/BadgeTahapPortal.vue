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
 * Empat tahap, empat rona, dan tidak satu pun meminjam palet sentimen.
 *
 * Aturan itu tetap berlaku dan justru dipertegas di sini. Di aplikasi ini hijau,
 * kuning, dan merah sentimen berarti nada pemberitaan, dan lencana tahap yang
 * meminjam warna itu akan terbaca seolah beritanya sendiri yang bernada.
 * Sebelumnya aturan itu dijaga dengan cara mengabukan tiga dari empat tahap,
 * sehingga "sedang diproses", "di luar pantauan", dan "gagal dibaca" tampil
 * dalam bidang abu yang sama dan hanya bisa dibedakan dengan membaca ikonnya.
 *
 * Sekarang ronanya diambil dari kosakata warna yang sudah berlaku di seluruh
 * panel admin, dan tidak satu pun berasal dari token sentimen:
 *
 * | Tahap            | Rona          | Arti rona di seluruh aplikasi        |
 * |------------------|---------------|--------------------------------------|
 * | tampil           | Navy merek    | Pekerjaan yang tuntas                |
 * | diproses         | Aksen ungu    | Mesin penilai sedang bekerja         |
 * | di_luar_pantauan | Abu netral    | Di luar lingkup, bukan kabar buruk   |
 * | gagal            | Destructive   | Galat teknis yang perlu ditindak     |
 *
 * `bg-brand`, bukan `bg-primary`. Token `--primary` dibalik menjadi nyaris putih
 * di mode gelap, jadi lencana "Tampil" berubah menjadi bidang putih dan
 * kehilangan identitas warnanya persis saat pengguna menyalakan mode gelap.
 * Navy merek ditetapkan instansi dan tidak dibalik di mode mana pun, sama dengan
 * keputusan yang sudah dipakai sidebar dan kop halaman.
 *
 * Warna tetap tidak pernah menjadi penanda tunggal. Setiap lencana membawa ikon
 * dan teks, dan `title` menjelaskan artinya dengan kalimat penuh.
 *
 * Pasangan kontras terendah adalah `text-aksen-ungu` di atas `bg-aksen-ungu/10`,
 * yaitu 6,4:1 di mode terang dan 8,1:1 di mode gelap. Sisanya di atas itu.
 */
const varian = computed(
    () =>
        ({
            tampil: {
                teks: 'Tampil',
                judul: 'Sudah dinilai relevan dan tampil di halaman Berita saya',
                ikon: CheckCircle2,
                kelas: 'bg-brand text-white ring-brand/20',
            },
            diproses: {
                teks: 'Sedang diproses',
                judul: 'Sudah masuk antrean penilaian, belum tampil di Berita saya sampai penilaiannya selesai',
                ikon: Clock,
                kelas: 'bg-aksen-ungu/10 text-aksen-ungu ring-aksen-ungu/25',
            },
            di_luar_pantauan: {
                teks: 'Di luar pantauan',
                judul: 'Dinilai bukan berita Pemerintah Kota Kendari, jadi tidak masuk Berita saya',
                ikon: MinusCircle,
                kelas: 'bg-muted text-muted-foreground ring-border',
            },
            gagal: {
                teks: 'Gagal dibaca',
                judul: 'Halamannya tidak bisa diunduh sistem, jadi penilaiannya tidak pernah berjalan',
                ikon: AlertTriangle,
                kelas: 'bg-destructive/10 text-destructive ring-destructive/25',
            },
        })[props.tahap],
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
        :class="varian.kelas"
        :title="varian.judul"
    >
        <component :is="varian.ikon" class="size-3.5 shrink-0" aria-hidden="true" />
        <span :class="ringkas ? 'sr-only' : ''">{{ varian.teks }}</span>
    </span>
</template>
