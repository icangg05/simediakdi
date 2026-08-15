<script setup lang="ts">
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import KopHalaman from '@/components/domain/KopHalaman.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { ChevronRight, CircleX, Gauge, Handshake, HelpCircle, Inbox, KeyRound, Loader2, ThumbsDown, ThumbsUp, TriangleAlert } from 'lucide-vue-next';
import { computed, ref, type Component } from 'vue';

interface Baris {
    id: number;
    artikel_id: number;
    judul: string;
    media: string | null;
    media_partner: boolean;
    prioritas: number;
    status: string;
    percobaan: number;
    galat: string | null;
    waktu: string | null;
    nada: 'relevan' | 'tidak_relevan' | 'perlu_review' | null;
    sentimen: 'positif' | 'netral' | 'negatif' | null;
}

type Keadaan = 'bekerja' | 'menunggu' | 'tertunda' | 'kosong' | 'macet';

const props = defineProps<{
    ringkasan: { menunggu: number; berjalan: number; selesai: number; gagal: number; total: number };
    aktivitas: {
        keadaan: Keadaan;
        terakhir_selesai_at: string | null;
        dilanjutkan_at: string | null;
        coba_lagi_at: string | null;
    };
    prioritas: { nilai: number; label: string; jumlah: number }[];
    laju: { jam: number; hari: number };
    kuota: {
        terkirim_hari_ini: number;
        kapasitas_harian: number;
        tersisa: number;
        jeda_detik: number;
        per_hari: number;
        perkiraan_hari: number | null;
    };
    terbaru: Baris[];
    diperbarui: string;
}>();

const { formatAngka } = useFormatAngka();

/**
 * Pembaruan otomatis lewat polling, bukan websocket.
 *
 * Proyek ini belum memasang Reverb maupun Echo, dan menambahkannya berarti satu
 * layanan baru yang harus hidup terus demi satu halaman. Antrean bergerak dalam
 * hitungan detik, bukan milidetik, jadi tarikan tiap lima detik sudah terbaca
 * sebagai langsung oleh mata.
 *
 * `only` wajib. Tanpanya setiap tarikan mengirim ulang seluruh prop halaman
 * termasuk menu dan data pengguna, dua belas kali semenit, selama halamannya
 * dibiarkan terbuka di layar monitor ruangan.
 *
 * Berjalan terus tanpa tombol jeda. Tombolnya dihapus karena penunjuk keadaan
 * di bawah ini hanya berarti kalau angkanya segar, dan penunjuk yang membeku
 * diam-diam karena seseorang menekan jeda kemarin adalah persis jenis
 * kebohongan yang ingin dihindari halaman ini.
 */
usePoll(5000, { only: ['ringkasan', 'aktivitas', 'prioritas', 'laju', 'kuota', 'terbaru', 'diperbarui'] }, { autoStart: true });

const persen = computed(() => (props.ringkasan.total === 0 ? 0 : Math.round((props.ringkasan.selesai / props.ringkasan.total) * 100)));

interface BarisGagal {
    id: number;
    artikel_id: number;
    judul: string;
    media: string | null;
    media_partner: boolean;
    prioritas: number;
    percobaan: number;
    galat: string | null;
    coba_lagi_at: string | null;
    waktu: string | null;
}

/**
 * Daftar berita yang sedang menunggu percobaan ulang, ditarik saat modal dibuka.
 *
 * Sengaja tidak ikut `usePoll` di atas. Angka kegagalan sementara cukup untuk
 * memberi tahu bahwa ada kendala, dan itu sudah ditarik tiap lima detik. Judul
 * beserta pesan galatnya baru berguna ketika ada yang benar-benar membukanya,
 * dan menitipkannya pada polling berarti mengirim ratusan baris dua belas kali
 * semenit untuk layar yang biasanya cuma dipandang sekilas.
 */
const modalGagal = ref(false);
const memuatGagal = ref(false);
const galatMuat = ref<string | null>(null);
const barisGagal = ref<BarisGagal[]>([]);
const kelompokGagal = ref<{ pesan: string; jumlah: number }[]>([]);
const totalGagal = ref(0);

async function bukaGagal() {
    modalGagal.value = true;
    memuatGagal.value = true;
    galatMuat.value = null;

    try {
        const respons = await fetch('/admin/antrean-ai/gagal', { headers: { Accept: 'application/json' } });

        if (!respons.ok) throw new Error(String(respons.status));

        const isi = (await respons.json()) as { baris: BarisGagal[]; kelompok: { pesan: string; jumlah: number }[]; total: number };

        barisGagal.value = isi.baris;
        kelompokGagal.value = isi.kelompok;
        totalGagal.value = isi.total;
    } catch {
        // Modalnya dibiarkan terbuka dengan pesan, bukan ditutup diam-diam.
        // Modal yang menutup sendiri sesaat setelah diklik terbaca sebagai
        // tombol yang rusak, bukan sebagai permintaan yang gagal.
        galatMuat.value = 'Daftarnya gagal diambil. Coba tutup lalu buka lagi.';
    } finally {
        memuatGagal.value = false;
    }
}

/** Tanggal lengkap, bukan cuma jam seperti daftar aktivitas. Kegagalan bisa berumur berhari-hari. */
const tanggal = (nilai: string | null) => (nilai ? format(new Date(nilai), "d MMM yyyy 'pukul' HH:mm", { locale: id }) : 'Waktu tidak tercatat');

const jam = (nilai: string | null) => (nilai ? format(new Date(nilai), 'HH:mm:ss', { locale: id }) : '-');

/*
 * Sistem warna halaman ini, lanjutan dari halaman Berita.
 *
 * Halaman ini dulu memakai palet Tailwind mentah, dan akibatnya satu rona
 * memikul terlalu banyak arti sekaligus. Hijau berarti empat hal: antrean
 * sehat, pekerjaan selesai, artikel relevan, dan nada positif. Merah berarti
 * lima hal: antrean macet, pekerjaan gagal, artikel tidak relevan, nada
 * negatif, dan pesan galat. Di satu baris "Pekerjaan terakhir" bisa berdiri dua
 * badge hijau bersebelahan yang artinya sama sekali berbeda.
 *
 * Pembagiannya sekarang mengikuti pembagian yang sama dengan halaman Berita:
 *
 * | Rona            | Arti                                    |
 * |-----------------|-----------------------------------------|
 * | Navy merek      | Pekerjaan yang tuntas                   |
 * | Aksen ungu      | Gemini sedang bekerja                   |
 * | Aksen toska     | Masuk lingkup pantauan                  |
 * | Abu redup       | Di luar lingkup pantauan, dan menunggu  |
 * | Hijau sentimen  | Nada positif, dan sistem sehat          |
 * | Abu sentimen    | Nada netral                             |
 * | Merah sentimen  | Nada negatif, dan pekerjaan gagal       |
 * | Kuning sentimen | Menunggu keputusan atau menunggu kuota  |
 *
 * Hijau tetap memikul dua hal, nada positif dan sistem sehat, tetapi keduanya
 * tidak pernah berdiri berdampingan: yang satu badge di dalam daftar, yang satu
 * penunjuk keadaan mesin di kop halaman. Kosakata sehatnya juga sama persis
 * dengan IndikatorKesehatan di Dashboard, jadi hijau, kuning, dan merah berarti
 * hal yang sama di kedua halaman.
 */

/**
 * Lima keadaan mesin, masing-masing dengan warna dan gerakannya sendiri.
 *
 * `menunggu` sengaja hijau dan tetap berdenyut, bukan abu-abu diam. Saat semua
 * kunci masih berada dalam jeda 15 detik, keadaan inilah yang terlihat pada
 * antrean yang justru sedang sehat. Menampilkannya
 * sebagai diam berarti melatih admin mengabaikan penunjuk yang benar.
 *
 * `bekerja` memakai ungu, bukan biru, karena yang sedang bekerja adalah Gemini
 * dan ungu berarti Gemini di seluruh panel admin.
 *
 * Nilainya diambil dari palet Tailwind, bukan token sentimen, dan itu satu
 * perkecualian yang perlu dijelaskan supaya tidak dibaca sebagai kembalinya
 * kesalahan lama. Token sentimen dirancang untuk latar terang: pada mode terang
 * `--color-sentimen-positif` berada di L 0,51, dan hijau setua itu di atas navy
 * kop hanya mencapai rasio 1,9. Rona yang dipakai di sini sama persis dengan
 * arti yang sudah ditetapkan tabel di atas, hanya lebih terang. Kalau suatu saat
 * arti sebuah rona berubah, keduanya harus diubah bersamaan.
 *
 * Setiap keadaan membawa tiga nilai: `titik` untuk bidang berisian penuh,
 * `teks` yang sudah dicerahkan sampai lolos 4,5:1 terhadap navy kop, dan
 * `bingkai` untuk tepi serta latar pilnya.
 *
 * Ronanya harus tetap sama dengan `PilKop`, yang mengurus perkecualian yang
 * sama untuk pil keterangan biasa. Penunjuk ini tidak memakai komponen itu
 * karena isinya dua baris dan ukurannya memang harus lebih besar.
 */
const INDIKATOR: Record<Keadaan, { label: string; titik: string; teks: string; bingkai: string }> = {
    bekerja: {
        label: 'Sedang menilai',
        titik: 'bg-violet-300',
        teks: 'text-violet-200',
        bingkai: 'border-violet-300/40 bg-violet-300/10',
    },
    menunggu: {
        label: 'Berjalan',
        titik: 'bg-emerald-300',
        teks: 'text-emerald-200',
        bingkai: 'border-emerald-300/40 bg-emerald-300/10',
    },
    tertunda: {
        label: 'Menunggu kuota',
        titik: 'bg-amber-300',
        teks: 'text-amber-200',
        bingkai: 'border-amber-300/40 bg-amber-300/10',
    },
    kosong: {
        label: 'Antrean kosong',
        titik: 'bg-white/50',
        teks: 'text-white/70',
        bingkai: 'border-white/25 bg-white/5',
    },
    macet: {
        label: 'Tidak bergerak',
        titik: 'bg-red-300',
        teks: 'text-red-200',
        bingkai: 'border-red-300/45 bg-red-300/10',
    },
};

const indikator = computed(() => INDIKATOR[props.aktivitas.keadaan]);

/**
 * Garis aliran di kop hanya bergerak kalau antreannya memang bergerak.
 *
 * Ini satu-satunya ornamen di halaman yang membawa arti, bukan hiasan. Cahaya
 * yang menyusuri garis berarti pekerjaan mengalir, garis yang diam berarti
 * tidak ada yang mengalir. Kalau ia tetap bergerak saat antrean macet, ia
 * berbohong lebih keras daripada teks apa pun yang bisa ditulis di sebelahnya.
 */
const mengalir = computed(() => props.aktivitas.keadaan === 'bekerja' || props.aktivitas.keadaan === 'menunggu');

const keterangan = computed(() => {
    const terakhir = props.aktivitas.terakhir_selesai_at;

    switch (props.aktivitas.keadaan) {
        case 'bekerja':
            return `${formatAngka(props.ringkasan.berjalan)} artikel sedang dikirim ke Gemini`;
        case 'menunggu':
            if (props.ringkasan.gagal > 0 && props.ringkasan.menunggu === 0 && props.ringkasan.berjalan === 0) {
                const jadwal = props.aktivitas.coba_lagi_at ? ` pada ${tanggal(props.aktivitas.coba_lagi_at)}` : '';

                return `${formatAngka(props.ringkasan.gagal)} artikel akan dicoba kembali otomatis${jadwal}`;
            }

            return `Kunci dipakai bergiliran tiap ${formatAngka(props.kuota.jeda_detik)} detik, ${formatAngka(props.ringkasan.menunggu)} artikel menunggu`;
        case 'tertunda':
            // Pekerjaannya hidup, hanya tidur. Menyebut jam bangunnya membuat
            // bedanya dengan macet terbaca tanpa perlu menjelaskan apa pun.
            return `Kuota Gemini habis. Dilanjutkan pukul ${jam(props.aktivitas.dilanjutkan_at)}.`;
        case 'kosong':
            return 'Tidak ada artikel yang menunggu dinilai';
        default:
            // Yang ditunjuk worker dan scheduler, bukan kuota. Kuota yang habis
            // punya penunjuknya sendiri di kartu Kuota Gemini, dan menyebut dua
            // penyebab sekaligus di sini membuat keduanya tidak dipercaya.
            return terakhir
                ? `Tidak ada yang selesai sejak ${jam(terakhir)}. Periksa worker dan scheduler.`
                : 'Belum ada satu pun pekerjaan selesai. Periksa worker dan scheduler.';
    }
});

/**
 * Empat angka antrean, dan bilah yang menyusunnya kembali jadi satu bidang.
 *
 * Sebelumnya keempatnya berdiri sebagai empat kartu putih berukuran sama, dan
 * susunan itu tidak menyampaikan apa pun selain bahwa jumlahnya ada empat.
 * Sekarang keempatnya berbagi satu bidang, dan di bawahnya bilah tunggal
 * menunjukkan proporsi masing-masing terhadap total. Titik warna di sebelah
 * label adalah keterangan bilahnya, jadi tidak perlu legenda terpisah.
 *
 * Urutan di bilah bukan urutan di baris angka. Bilah dibaca sebagai kemajuan
 * dan karenanya diisi dari kiri oleh yang sudah tuntas, sama dengan bilah
 * ekstraksi di Dashboard.
 */
const segmen = computed(() => [
    { kunci: 'menunggu', label: 'Menunggu', jumlah: props.ringkasan.menunggu, titik: 'bg-muted-foreground/30', angka: '' },
    {
        kunci: 'berjalan',
        label: 'Sedang dinilai',
        jumlah: props.ringkasan.berjalan,
        titik: 'bg-aksen-ungu',
        // Hanya dua dari empat angka yang bisa berwarna, dan keduanya punya
        // alasan. Ungu menandai pekerjaan yang sedang dipegang Gemini, merah
        // menandai percobaan ulang. Menunggu dan Selesai
        // dibiarkan netral: keduanya keadaan normal, dan mewarnai keempatnya
        // membuat tidak ada satu pun yang menonjol.
        angka: props.ringkasan.berjalan > 0 ? 'text-aksen-ungu' : '',
    },
    { kunci: 'selesai', label: 'Selesai', jumlah: props.ringkasan.selesai, titik: 'bg-brand dark:bg-brand-terang', angka: '' },
    {
        kunci: 'gagal',
        label: 'Menunggu coba ulang',
        jumlah: props.ringkasan.gagal,
        titik: 'bg-sentimen-negatif',
        angka: props.ringkasan.gagal > 0 ? 'text-sentimen-negatif' : '',
        // Satu-satunya angka di baris ini yang bisa ditelusuri lebih jauh, dan
        // hanya ketika ada isinya. Kartu yang bisa diklik tapi membuka daftar
        // kosong mengajari orang bahwa mengkliknya tidak ada gunanya, dan
        // pelajaran itu bertahan sampai angkanya benar-benar naik.
        bisaDibuka: props.ringkasan.gagal > 0,
    },
]);

const bilah = computed(() => {
    const total = props.ringkasan.total;

    if (total === 0) {
        return [];
    }

    return [
        { kunci: 'selesai', kelas: 'bg-brand dark:bg-brand-terang', jumlah: props.ringkasan.selesai },
        { kunci: 'gagal', kelas: 'bg-sentimen-negatif', jumlah: props.ringkasan.gagal },
        { kunci: 'berjalan', kelas: 'bg-aksen-ungu', jumlah: props.ringkasan.berjalan },
        { kunci: 'menunggu', kelas: 'bg-muted-foreground/30', jumlah: props.ringkasan.menunggu },
    ]
        .filter((s) => s.jumlah > 0)
        .map((s) => ({ ...s, lebar: `${(s.jumlah / total) * 100}%` }));
});

/** Kini hanya ada satu jenis pekerjaan otomatis, jadi cukup tampilkan jumlah keseluruhannya. */
const jumlahDalamAntrean = computed(() => props.prioritas.reduce((jumlah, item) => jumlah + item.jumlah, 0));

/**
 * Status pekerjaan, dan hanya yang layak diberitakan.
 *
 * `selesai` sengaja tidak punya entri, sehingga badgenya tidak dirender sama
 * sekali. Daftar ini berisi pekerjaan yang baru saja bergerak, jadi hampir
 * seluruh barisnya berstatus selesai, dan penanda yang muncul di hampir setiap
 * baris berhenti menjadi penanda. Alasan yang sama sudah dipakai halaman Berita
 * saat memutuskan tidak memberi badge pada penilai Gemini.
 *
 * Yang tersisa justru yang perlu terlihat sekali lihat: baris yang masih
 * dikerjakan, dan baris yang gagal. Selesainya sebuah pekerjaan tetap terbaca,
 * karena barisnya membawa hasil relevansi dan nada di sebelahnya.
 */
const STATUS_TAMPIL: Record<string, { label: string; kelas: string; ikon: Component; berputar: boolean }> = {
    berjalan: {
        label: 'Sedang dinilai',
        kelas: 'bg-aksen-ungu/10 text-aksen-ungu',
        ikon: Loader2,
        berputar: true,
    },
    gagal: {
        label: 'Gagal sementara',
        kelas: 'bg-sentimen-negatif-lembut text-sentimen-negatif',
        ikon: CircleX,
        berputar: false,
    },
};

/** Titik pada rel waktu memakai warna status barisnya, jadi baris bermasalah terlihat tanpa membaca badge. */
const titikBaris = (status: string) =>
    ({
        berjalan: 'bg-aksen-ungu ring-aksen-ungu/25',
        gagal: 'bg-sentimen-negatif ring-sentimen-negatif/25',
    })[status] ?? 'bg-border ring-transparent';

/**
 * Relevansi diwarnai menurut lingkup, sama persis dengan halaman Berita.
 *
 * Toska berarti terhitung, abu redup berarti dikesampingkan, kuning berarti
 * menunggu keputusan manusia. Merah sengaja tidak dipakai untuk tidak relevan:
 * berita di luar cakupan Pemkot bukan kabar buruk, ia sekadar bukan urusan
 * sistem ini, dan merah di halaman ini sudah berarti pekerjaan yang gagal.
 */
const NADA: Record<string, { label: string; kelas: string; ikon: Component }> = {
    relevan: { label: 'Relevan', kelas: 'bg-aksen-toska/10 text-aksen-toska', ikon: ThumbsUp },
    tidak_relevan: { label: 'Tidak relevan', kelas: 'bg-muted text-muted-foreground', ikon: ThumbsDown },
    perlu_review: { label: 'Perlu review', kelas: 'bg-sentimen-review-lembut text-sentimen-review', ikon: HelpCircle },
};
</script>

<template>
    <Head title="Antrean AI" />

    <LayoutAdmin :breadcrumbs="[{ title: 'Antrean AI', href: '/admin/antrean-ai' }]">
        <!--
            Kop navy bersama, sama dengan seluruh panel admin. Halaman ini adalah
            panel mesin, dan yang dicari admin saat membukanya selalu satu
            pertanyaan: masih jalan tidak. Bidang gelap di puncak halaman memberi
            pertanyaan itu tempatnya sendiri, terpisah dari angka-angka yang
            menjelaskannya di bawah.
        -->
        <KopHalaman
            judul="Antrean AI"
            keterangan="Klasifikasi Gemini berjalan sendiri di latar belakang. Halaman ini memperbarui dirinya tiap lima detik."
        >
            <!--
                Penunjuk, bukan tombol. Dua tombol yang dulu berdiri di sini
                sudah dihapus: yang satu hanya menjeda tarikan halaman dan tidak
                pernah menyentuh antrean, yang satu lagi selalu menjawab 500.

                Ia tidak memakai PilKop karena isinya dua baris dan ukurannya
                memang harus lebih besar daripada pil keterangan biasa: inilah
                satu-satunya hal di halaman ini yang dibaca dari jauh.

                `aria-live` polite supaya pembaca layar mengabarkan perubahan
                keadaan tanpa memotong bacaan yang sedang berjalan. Denyutnya
                sendiri tidak terbaca pembaca layar, jadi labelnya yang harus
                membawa seluruh maknanya.
            -->
            <template #aksi>
                <div
                    class="flex items-center gap-2.5 rounded-lg border px-3 py-2 backdrop-blur-xs"
                    :class="indikator.bingkai"
                    role="status"
                    aria-live="polite"
                >
                    <TriangleAlert v-if="aktivitas.keadaan === 'macet'" class="size-4 shrink-0 text-red-300" aria-hidden="true" />
                    <span v-else class="relative flex size-2.5 shrink-0" aria-hidden="true">
                        <!-- Denyutnya dimatikan untuk pengguna yang meminta gerak
                             dikurangi. Maknanya tidak ikut hilang, keadaan yang
                             sama sudah ditulis lengkap sebagai teks di sebelahnya. -->
                        <span
                            v-if="aktivitas.keadaan !== 'kosong'"
                            class="absolute inline-flex size-full animate-ping rounded-full opacity-75 motion-reduce:animate-none"
                            :class="indikator.titik"
                        />
                        <span class="relative inline-flex size-2.5 rounded-full" :class="indikator.titik" />
                    </span>

                    <div class="text-sm leading-tight">
                        <p class="font-medium" :class="indikator.teks">{{ indikator.label }}</p>
                        <p class="text-xs text-white/70">{{ keterangan }}</p>
                    </div>
                </div>
            </template>

            <template #bawah>
                <!--
                    Garis aliran. Cahayanya menyusuri garis selama antrean
                    bergerak dan berhenti begitu antrean berhenti, jadi keadaan
                    mesin terbaca dari ujung ruangan tanpa membaca satu kata pun.
                -->
                <div class="relative h-px w-full overflow-hidden rounded-full bg-white/15" :class="mengalir ? 'aliran' : ''" aria-hidden="true"></div>

                <div class="mt-4 flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
                    <p class="text-xs text-white/60">
                        <span class="angka text-3xl font-semibold tracking-tight text-white">{{ formatAngka(persen) }}%</span>
                        <span class="ml-2"
                            >tuntas dari <span class="angka">{{ formatAngka(ringkasan.total) }}</span> artikel</span
                        >
                    </p>
                    <p class="angka text-xs text-white/50">Diperbarui {{ jam(diperbarui) }}</p>
                </div>
            </template>
        </KopHalaman>

        <Card class="muncul overflow-hidden" style="animation-delay: 80ms">
            <CardContent class="p-0">
                <!-- Garis pemisah dibuat dari celah satu piksel di atas latar
                     bergaris, bukan `divide-x`. Utilitas divide memasang tepi
                     pada setiap anak kecuali yang pertama, dan pada kisi dua
                     kolom itu berarti sel pertama baris kedua ikut bergaris di
                     kiri padahal ia berada di tepi kartu. -->
                <div class="grid grid-cols-2 gap-px bg-border sm:grid-cols-4">
                    <!-- Kartu percobaan ulang menjadi tombol, sisanya tetap div.
                         Memakai `component :is` alih-alih memasang @click pada
                         div: yang bisa ditekan harus benar-benar sebuah button,
                         supaya ia punya fokus keyboard dan dibacakan sebagai
                         tombol, bukan sebagai teks yang kebetulan menanggapi
                         tetikus. -->
                    <component
                        :is="s.bisaDibuka ? 'button' : 'div'"
                        v-for="s in segmen"
                        :key="s.kunci"
                        :type="s.bisaDibuka ? 'button' : undefined"
                        class="space-y-1 bg-card p-4 text-left"
                        :class="
                            s.bisaDibuka
                                ? 'tekan group cursor-pointer transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden focus-visible:ring-inset'
                                : ''
                        "
                        @click="s.bisaDibuka ? bukaGagal() : undefined"
                    >
                        <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <span class="size-2 shrink-0 rounded-full" :class="s.titik" aria-hidden="true"></span>
                            {{ s.label }}
                        </p>
                        <p class="flex items-center gap-1 text-2xl font-semibold" :class="s.angka">
                            <span class="angka">{{ formatAngka(s.jumlah) }}</span>
                            <ChevronRight
                                v-if="s.bisaDibuka"
                                class="size-4 transition-transform duration-200 group-hover:translate-x-0.5 motion-reduce:transition-none"
                                aria-hidden="true"
                            />
                        </p>
                        <p v-if="s.bisaDibuka" class="text-xs text-muted-foreground">Lihat kendalanya</p>
                    </component>
                </div>

                <!-- Bilah komposisi menggantikan bilah kemajuan satu warna.
                     Keduanya membawa persentase yang sama, tapi yang ini
                     sekaligus menjawab apa isi sisanya. -->
                <div class="flex h-1.5 w-full overflow-hidden border-t bg-muted">
                    <div
                        v-for="(s, i) in bilah"
                        :key="s.kunci"
                        class="tumbuh h-full transition-[width] duration-500 ease-out"
                        :class="s.kelas"
                        :style="{ width: s.lebar, animationDelay: `${160 + i * 90}ms` }"
                    />
                </div>
            </CardContent>
        </Card>

        <div class="grid gap-3 lg:grid-cols-3">
            <Card class="muncul" style="animation-delay: 140ms">
                <CardHeader class="space-y-2 border-b py-3">
                    <div class="flex items-center gap-2">
                        <div class="grid size-7 place-items-center rounded-md bg-brand-lembut text-brand dark:text-white">
                            <Inbox class="size-4" aria-hidden="true" />
                        </div>
                        <CardTitle class="text-sm font-semibold">Artikel dalam antrean</CardTitle>
                    </div>
                    <p class="text-xs leading-relaxed text-muted-foreground">Artikel baru yang belum pernah dinilai akan diklasifikasi otomatis.</p>
                </CardHeader>

                <CardContent class="pt-4">
                    <div class="flex items-end justify-between gap-4">
                        <p class="text-sm text-muted-foreground">Belum diklasifikasi</p>
                        <p class="angka text-2xl font-semibold tracking-tight">{{ formatAngka(jumlahDalamAntrean) }}</p>
                    </div>
                </CardContent>
            </Card>

            <Card class="muncul" style="animation-delay: 200ms">
                <CardHeader class="flex-row items-center gap-2 space-y-0 border-b py-3">
                    <div class="grid size-7 place-items-center rounded-md bg-aksen-toska/10 text-aksen-toska">
                        <Gauge class="size-4" aria-hidden="true" />
                    </div>
                    <CardTitle class="text-sm font-semibold">Laju</CardTitle>
                </CardHeader>

                <CardContent class="grid grid-cols-2 gap-4 pt-4">
                    <div>
                        <p class="angka text-2xl font-semibold">{{ formatAngka(laju.jam) }}</p>
                        <p class="text-xs text-muted-foreground">artikel sejam terakhir</p>
                    </div>
                    <div>
                        <p class="angka text-2xl font-semibold">{{ formatAngka(laju.hari) }}</p>
                        <p class="text-xs text-muted-foreground">artikel sehari terakhir</p>
                    </div>
                </CardContent>
            </Card>

            <!-- Ungu, karena seluruh kartu ini berbicara tentang Gemini. -->
            <Card class="muncul" style="animation-delay: 260ms">
                <CardHeader class="flex-row items-center gap-2 space-y-0 border-b py-3">
                    <div class="grid size-7 place-items-center rounded-md bg-aksen-ungu/10 text-aksen-ungu">
                        <KeyRound class="size-4" aria-hidden="true" />
                    </div>
                    <CardTitle class="text-sm font-semibold">Kuota Gemini</CardTitle>
                </CardHeader>

                <CardContent class="space-y-2 pt-4 text-sm">
                    <!-- Yang disebut pemakaian, bukan sisa. Sisa menuntut batas
                         yang benar, dan Google tidak menyediakan cara membaca
                         batas maupun pemakaian lewat kunci API biasa. Angka sisa
                         yang dihitung terhadap tebakan config pernah berbunyi
                         "497 sisa" untuk kunci yang detik itu juga ditolak
                         Google karena kuotanya sudah habis. Karena itu kartu ini
                         juga tidak punya bilah: bilah menuntut penyebut, dan
                         penyebut yang benar memang tidak ada. -->
                    <p>
                        <span class="angka text-2xl font-semibold text-aksen-ungu">{{ formatAngka(kuota.terkirim_hari_ini) }}</span>
                        <span class="ml-1.5 text-xs text-muted-foreground">permintaan terkirim hari ini</span>
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Jeda pemakaian ulang kunci
                        <span class="angka font-medium text-foreground">{{ formatAngka(kuota.jeda_detik) }}</span> detik, kira-kira
                        <span class="angka">{{ formatAngka(kuota.per_hari) }}</span> artikel sehari
                    </p>
                    <!-- Perkiraan dihitung dari kapasitas dan jeda, bukan dari
                         laju sejam terakhir. Laju sejam terakhir jatuh ke nol
                         setiap kali kuota habis, dan perkiraan yang berbunyi
                         "tidak akan pernah selesai" setiap sore tidak menolong
                         siapa pun. -->
                    <p v-if="kuota.perkiraan_hari !== null && kuota.tersisa > 0" class="text-xs text-muted-foreground">
                        Sisa antrean kira-kira <span class="angka font-medium text-foreground">{{ formatAngka(kuota.perkiraan_hari) }}</span> hari
                        lagi
                    </p>
                    <p
                        v-else-if="kuota.kapasitas_harian === 0"
                        class="flex items-start gap-1.5 rounded-md bg-sentimen-negatif-lembut p-2 text-xs text-sentimen-negatif"
                    >
                        <TriangleAlert class="mt-px size-3.5 shrink-0" aria-hidden="true" />
                        Tidak ada kunci Gemini yang menyala. Antrean tidak akan bergerak.
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card class="muncul" style="animation-delay: 320ms">
            <CardHeader class="flex-row items-center justify-between gap-2 space-y-0 border-b py-3">
                <CardTitle class="text-sm font-semibold">Pekerjaan terakhir</CardTitle>
                <span class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span class="size-1.5 rounded-full bg-sentimen-positif" :class="mengalir ? 'denyut' : ''" aria-hidden="true"></span>
                    Langsung
                </span>
            </CardHeader>

            <CardContent class="p-0">
                <p v-if="terbaru.length === 0" class="p-6 text-center text-sm text-muted-foreground">
                    Belum ada pekerjaan yang bergerak. Artikel masuk antrean sendiri begitu isinya selesai diekstrak, jadi daftar ini terisi setelah
                    crawl berikutnya berjalan.
                </p>

                <!--
                    Rel waktu, bukan sekadar kolom jam.

                    Daftar ini selalu terurut menurut waktu, dan garis yang
                    menghubungkan titik-titiknya menyatakan keterurutan itu
                    sebagai bentuk. Titiknya berwarna menurut status barisnya,
                    jadi baris gagal terlihat sebelum badge-nya dibaca.
                -->
                <ol v-else class="divide-y">
                    <li v-for="b in terbaru" :key="b.id" class="relative flex gap-3 px-4 py-3 text-sm transition-colors hover:bg-muted/40">
                        <div class="flex w-16 shrink-0 items-start gap-2">
                            <span class="angka pt-px text-xs text-muted-foreground">{{ jam(b.waktu) }}</span>
                        </div>

                        <span
                            class="mt-1.5 size-2 shrink-0 rounded-full ring-4"
                            :class="[titikBaris(b.status), b.status === 'berjalan' ? 'denyut' : '']"
                            aria-hidden="true"
                        ></span>

                        <div class="min-w-0 flex-1 space-y-1">
                            <Link
                                :href="`/admin/artikel/${b.artikel_id}`"
                                class="line-clamp-2 rounded font-medium decoration-brand/40 underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
                            >
                                {{ b.judul }}
                            </Link>

                            <!-- Seluruh penanda memakai bentuk yang sama,
                                 `rounded-md` bobot medium, mengikuti bentuk
                                 BadgeSentimen. Masing-masing membawa ikon, jadi
                                 tidak ada penanda yang hanya bisa dibedakan
                                 lewat warna. -->
                            <div class="flex flex-wrap items-center gap-1.5">
                                <Badge
                                    v-if="STATUS_TAMPIL[b.status]"
                                    variant="outline"
                                    class="rounded-md font-medium"
                                    :class="STATUS_TAMPIL[b.status].kelas"
                                >
                                    <component
                                        :is="STATUS_TAMPIL[b.status].ikon"
                                        class="size-3 shrink-0"
                                        :class="STATUS_TAMPIL[b.status].berputar ? 'animate-spin motion-reduce:animate-none' : ''"
                                        aria-hidden="true"
                                    />
                                    {{ STATUS_TAMPIL[b.status].label }}
                                </Badge>

                                <Badge v-if="b.nada" variant="outline" class="rounded-md font-medium" :class="NADA[b.nada].kelas">
                                    <component :is="NADA[b.nada].ikon" class="size-3 shrink-0" aria-hidden="true" />
                                    {{ NADA[b.nada].label }}
                                </Badge>

                                <!-- Dirender BadgeSentimen, komponen yang menyatakan
                                     dirinya satu-satunya tempat yang boleh merender
                                     indikator sentimen. Halaman ini dulu melanggarnya
                                     dengan palet emerald dan rose buatan sendiri,
                                     sehingga hijau "Positif" di sini berbeda dari
                                     hijau "Positif" di halaman Berita. -->
                                <BadgeSentimen v-if="b.sentimen" :label="b.sentimen" />

                                <span class="text-xs text-muted-foreground">{{ b.media ?? '-' }}</span>
                                <span
                                    v-if="b.media_partner"
                                    class="inline-flex items-center gap-1 rounded-md bg-aksen-toska/10 px-1.5 py-0.5 text-[11px] font-medium whitespace-nowrap text-aksen-toska"
                                >
                                    <Handshake class="size-3 shrink-0" aria-hidden="true" />
                                    Bekerja sama
                                </span>
                            </div>

                            <p v-if="b.galat" class="text-xs text-sentimen-negatif">Kegagalan ke-{{ b.percobaan }}: {{ b.galat }}</p>
                        </div>
                    </li>
                </ol>
            </CardContent>
        </Card>

        <Dialog :open="modalGagal" @update:open="(buka) => (modalGagal = buka)">
            <DialogContent class="max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Klasifikasi yang akan dicoba ulang</DialogTitle>
                    <DialogDescription>
                        Sistem mencoba setiap berita lagi secara otomatis dengan jeda bertahap. Berita akan hilang dari daftar setelah berhasil.
                    </DialogDescription>
                </DialogHeader>

                <p v-if="memuatGagal" class="flex items-center gap-2 py-8 text-sm text-muted-foreground">
                    <Loader2 class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                    Mengambil daftarnya...
                </p>

                <p v-else-if="galatMuat" class="rounded-md bg-sentimen-negatif/10 p-3 text-sm text-sentimen-negatif">{{ galatMuat }}</p>

                <template v-else>
                    <!-- Pengelompokan sebab di atas daftarnya, bukan di bawah.
                         Pertanyaan pertama saat melihat angka merah adalah
                         "rusaknya satu macam atau macam-macam", dan menjawabnya
                         lebih dulu menentukan apakah daftar di bawah perlu
                         dibaca satu per satu sama sekali. -->
                    <div v-if="kelompokGagal.length > 0" class="space-y-1.5 rounded-md border bg-muted/30 p-3">
                        <p class="text-xs font-medium text-muted-foreground">Dikelompokkan menurut sebabnya</p>
                        <div v-for="k in kelompokGagal" :key="k.pesan" class="flex items-start justify-between gap-3 text-xs">
                            <span class="min-w-0 flex-1 wrap-break-word text-foreground">{{ k.pesan }}</span>
                            <span class="angka shrink-0 font-semibold text-sentimen-negatif">{{ formatAngka(k.jumlah) }}</span>
                        </div>
                    </div>

                    <p v-if="totalGagal > barisGagal.length" class="text-xs text-muted-foreground">
                        Menampilkan <span class="angka">{{ formatAngka(barisGagal.length) }}</span> teratas dari
                        <span class="angka">{{ formatAngka(totalGagal) }}</span> kegagalan, terbaru dulu.
                    </p>

                    <ol class="max-h-96 divide-y overflow-y-auto rounded-md border">
                        <li v-for="b in barisGagal" :key="b.id" class="transition-colors hover:bg-muted/40">
                            <Link
                                :href="`/admin/artikel/${b.artikel_id}`"
                                class="block space-y-1 px-3 py-2.5 focus-visible:bg-muted/60 focus-visible:outline-hidden"
                            >
                                <p class="line-clamp-2 text-sm font-medium">{{ b.judul }}</p>
                                <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                                    <span v-if="b.media">{{ b.media }}</span>
                                    <span
                                        v-if="b.media_partner"
                                        class="inline-flex items-center gap-1 rounded-md bg-aksen-toska/10 px-1.5 py-0.5 text-[11px] font-medium whitespace-nowrap text-aksen-toska"
                                    >
                                        <Handshake class="size-3 shrink-0" aria-hidden="true" />
                                        Bekerja sama
                                    </span>
                                    <span v-if="b.media" aria-hidden="true">/</span>
                                    <span class="angka">{{ tanggal(b.waktu) }}</span>
                                    <span aria-hidden="true">/</span>
                                    <span class="angka">{{ b.percobaan }}x gagal</span>
                                    <template v-if="b.coba_lagi_at">
                                        <span aria-hidden="true">/</span>
                                        <span class="angka">Dicoba lagi {{ tanggal(b.coba_lagi_at) }}</span>
                                    </template>
                                </p>
                                <p class="text-xs wrap-break-word text-sentimen-negatif">{{ b.galat ?? 'Tanpa keterangan' }}</p>
                            </Link>
                        </li>
                    </ol>

                    <p v-if="barisGagal.length === 0" class="py-8 text-center text-sm text-muted-foreground">
                        Tidak ada klasifikasi yang sedang menunggu percobaan ulang.
                    </p>
                </template>
            </DialogContent>
        </Dialog>
    </LayoutAdmin>
</template>

<style scoped>
/*
 * `.aliran` tidak lagi didefinisikan di sini. Ia pindah ke
 * resources/css/app.css setelah pemakaiannya menyentuh empat halaman, dan
 * artinya tetap sama persis: kelasnya hanya menempel saat antrean memang
 * bergerak, jadi garis yang diam adalah keterangan, bukan animasi yang kebetulan
 * mati.
 */
</style>
