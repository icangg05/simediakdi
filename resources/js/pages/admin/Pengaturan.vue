<script setup lang="ts">
import IndikatorKesehatan from '@/components/domain/IndikatorKesehatan.vue';
import KartuSeksi from '@/components/domain/KartuSeksi.vue';
import KopHalaman from '@/components/domain/KopHalaman.vue';
import PilKop from '@/components/domain/PilKop.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { Activity, CircleCheck, Cpu, Gauge, Info, KeyRound, Loader2, Lock, Send, Server, Sparkles, Target, TriangleAlert } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';

interface Nilai {
    label: string;
    nilai: string | number | null;
    env: string;
    diukur: string | null;
}

interface PengaturanAi {
    model: string;
    penyedia_relevansi: 'gemini' | 'indobert';
    versi_prompt_relevansi: string;
    prompt_relevansi: string;
    versi_prompt_sentimen: string;
    prompt_sentimen: string;
}

interface Telegram {
    chat_id: string;
    token_terisi: boolean;
    siap: boolean;
}

interface Kunci {
    id: number;
    label: string;
    aktif: boolean;
    limit_sampai: string | null;
    alasan_limit: string | null;
    terakhir_dipakai_at: string | null;
    galat_terakhir: string | null;
    galat_at: string | null;
    rpd_terpakai: number;
    rpd_google: number | null;
    rpd_google_at: string | null;
    rpd_manual: number | null;
    rpd_berlaku: number;
}

const props = defineProps<{
    pengaturanAi: PengaturanAi;
    telegram: Telegram;
    kunci: Kunci[];
    resetHarian: string;
    kelompok: { judul: string; catatan: string | null; nilai: Nilai[] }[];
    layanan: { nama: string; sehat: boolean; url: string }[];
    evaluasi: { f1_macro: number; jumlah_sampel: number; dievaluasi_at: string } | null;
    modelRelevansiAktif: string | null;
}>();

const page = usePage<SharedData>();

const { formatAngka } = useFormatAngka();

const formAi = useForm({ ...props.pengaturanAi });
const formKunci = useForm({ label: '', kunci: '' });

/**
 * Token sengaja mulai kosong, betapapun sudah ada yang tersimpan.
 *
 * Token yang pernah muncul di layar admin harus dianggap bocor, jadi tidak ada
 * satu pun nilai token yang dikirim server ke halaman ini. Kosong di sini
 * berarti "biarkan yang tersimpan", dan itu ditulis di sebelah kotaknya supaya
 * tidak terbaca sebagai perintah menghapus.
 */
const formTelegram = useForm({
    telegram_token: '',
    telegram_chat_id: props.telegram.chat_id,
});

function simpanAi() {
    formAi.put('/admin/pengaturan/ai', { preserveScroll: true });
}

function simpanTelegram() {
    formTelegram.put('/admin/pengaturan/telegram', {
        preserveScroll: true,
        // Hanya tokennya yang dikosongkan kembali. Chat ID tetap terlihat
        // karena ia memang nilai yang sedang berlaku, bukan ketikan sekali
        // pakai, dan mengosongkannya akan membuat penyimpanan berikutnya
        // menghapus chat ID tanpa ada yang bermaksud begitu.
        onSuccess: () => formTelegram.reset('telegram_token'),
    });
}

/** Notifikasi uji memakai pengiriman sungguhan, jadi tombolnya terkunci selama berjalan. */
const sedangUjiTelegram = ref(false);

function ujiTelegram() {
    sedangUjiTelegram.value = true;

    router.post(
        '/admin/alert/uji-telegram',
        {},
        {
            preserveScroll: true,
            showProgress: false,
            onFinish: () => (sedangUjiTelegram.value = false),
        },
    );
}

/**
 * Dua penilai relevansi, dan hanya itu yang boleh tersimpan.
 *
 * Sentimen tidak ikut dipilih di sini karena memang tidak ada pilihannya.
 * IndoBERT hanya dilatih menilai relevan atau tidak, jadi nada berita selalu
 * dikerjakan Gemini betapapun opsi ini disetel.
 *
 * Warnanya membedakan biaya keduanya, bukan seleranya. Ungu berarti pekerjaan
 * yang memakai kuota Gemini, sama dengan seluruh panel admin. Toska berarti
 * penilaian relevansi yang dikerjakan di server sendiri, warna yang sudah
 * dipakai halaman Model Relevansi untuk hal yang sama.
 */
const penyediaRelevansi = [
    {
        nilai: 'gemini' as const,
        label: 'Gemini',
        keterangan: 'Menilai lewat prompt di bawah, menyertakan kutipan sebagai bukti, dan memakai kuota harian.',
        ikon: Sparkles,
        kelas: 'border-aksen-ungu/50 bg-aksen-ungu/5',
        ikonKelas: 'text-aksen-ungu',
        tileKelas: 'bg-aksen-ungu/10 text-aksen-ungu ring-aksen-ungu/25',
    },
    {
        nilai: 'indobert' as const,
        label: 'IndoBERT',
        keterangan: 'Menilai di server sendiri tanpa kuota. Artikel yang ditolaknya tidak pernah dikirim ke Gemini.',
        ikon: Cpu,
        kelas: 'border-aksen-toska/50 bg-aksen-toska/5',
        ikonKelas: 'text-aksen-toska',
        tileKelas: 'bg-aksen-toska/10 text-aksen-toska ring-aksen-toska/25',
    },
];

/**
 * IndoBERT tidak bisa dipilih selama belum ada model yang diaktifkan.
 *
 * Cermin dari penolakan di PengaturanAiController, bukan penegakannya.
 * Permintaannya tetap bisa dikirim langsung, dan server yang menolaknya.
 */
const bisaIndoBert = computed(() => props.modelRelevansiAktif !== null);

function tambahKunci() {
    formKunci.post('/admin/pengaturan/kunci', {
        preserveScroll: true,
        onSuccess: () => formKunci.reset(),
    });
}

function ubahAktif(k: Kunci) {
    router.put(`/admin/pengaturan/kunci/${k.id}`, { aktif: !k.aktif }, { preserveScroll: true });
}

/**
 * Batas harian yang sedang diketik, per kunci.
 *
 * Disimpan terpisah dari `props.kunci` supaya ketikan yang belum disimpan tidak
 * ikut tertimpa saat Inertia memuat ulang halaman setelah aksi lain, misalnya
 * menguji kunci di baris sebelahnya.
 */
const batasKetikan = ref<Record<number, string>>({});

function batasHarian(k: Kunci): string {
    return batasKetikan.value[k.id] ?? (k.rpd_manual === null ? '' : String(k.rpd_manual));
}

/**
 * Kosong berarti kembali ke angka bawaan, bukan berarti nol.
 *
 * Nol akan diterima validasi sebagai angka lalu membuat penjaga kuota
 * menganggap kunci ini tidak punya jatah sama sekali.
 *
 * Dikirim sebagai string kosong, bukan null. Controller membedakan "kosongkan
 * batas" dari "ubah sakelar aktif" lewat ada tidaknya bidang `rpd_manual` di
 * badan permintaan, dan bidang bernilai null hilang seluruhnya saat badannya
 * dikodekan sebagai form. String kosong selalu sampai, dan middleware bawaan
 * Laravel mengubahnya menjadi null sebelum validasi membacanya.
 */
function simpanBatas(k: Kunci) {
    router.put(
        `/admin/pengaturan/kunci/${k.id}`,
        { rpd_manual: batasHarian(k).trim() },
        {
            preserveScroll: true,
            onSuccess: () => delete batasKetikan.value[k.id],
        },
    );
}

function hapusKunci(k: Kunci) {
    if (confirm(`Hapus kunci ${k.label}? Klasifikasi akan memakai kunci yang tersisa.`)) {
        router.delete(`/admin/pengaturan/kunci/${k.id}`, { preserveScroll: true });
    }
}

/** Kunci yang sedang diketuk, supaya tombolnya terkunci satu per satu. */
const sedangUji = ref<number | null>(null);

/**
 * Jeda 15 detik per kunci setelah diuji, dihitung mundur di layar.
 *
 * Penegakan yang sebenarnya ada di server, karena tombol yang diredupkan bukan
 * aturan dan permintaannya tetap bisa dikirim langsung. Yang di sini hanya
 * supaya admin melihat sisa waktunya alih-alih menekan tombol lalu menerima
 * penolakan yang terasa seperti kerusakan.
 */
const JEDA_UJI = 15;

const jedaUji = ref<Record<number, number>>({});

/*
 * Dipasang dan dilepas mengikuti umur komponen. Alasannya sama dengan pencacah
 * di halaman Artikel: badan setup ikut dijalankan di server pada setiap
 * permintaan, dan timer yang tidak pernah dihentikan menumpuk di proses Node
 * yang hidup terus.
 */
let pencacah: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    pencacah = setInterval(() => {
        for (const [id, sisa] of Object.entries(jedaUji.value)) {
            if (sisa <= 1) delete jedaUji.value[Number(id)];
            else jedaUji.value[Number(id)] = sisa - 1;
        }
    }, 1000);
});

onUnmounted(() => {
    if (pencacah) clearInterval(pencacah);
});

/**
 * Hasil uji dibaca dari flash session, bukan disimpan di komponen.
 *
 * Menyegarkan halaman membuangnya, dan itu memang benar: hasil uji adalah
 * potret sesaat, dan potret yang bertahan setelah reload akan terbaca sebagai
 * keadaan kunci sekarang padahal bisa saja sudah berubah.
 */
const uji = computed(() => page.props.flash?.ujiKunci ?? null);

function ujiKunci(k: Kunci) {
    sedangUji.value = k.id;
    jedaUji.value[k.id] = JEDA_UJI;

    router.post(
        `/admin/pengaturan/kunci/${k.id}/uji`,
        {},
        {
            preserveScroll: true,
            showProgress: false,
            onFinish: () => (sedangUji.value = null),
        },
    );
}

const bisaDiuji = (k: Kunci) => sedangUji.value === null && !jedaUji.value[k.id];

const waktu = (iso: string) => format(new Date(iso), 'd MMM yyyy HH:mm', { locale: id });

const alasan: Record<string, string> = {
    kuota_harian: 'kuota harian habis',
    kuota_menit: 'kuota per menit habis',
    retry_delay: 'diminta menunggu oleh Google',
    // Ditahan hitungan sendiri, sebelum Google sempat menolak. Dibedakan dari
    // dua yang di atas supaya admin tahu kuncinya belum benar-benar ditolak.
    kuota_harian_lokal: 'batas harian tercapai, ditahan sendiri',
    kuota_menit_lokal: 'batas per menit tercapai, ditahan sendiri',
};

const jumlahAktif = computed(() => props.kunci.filter((k) => k.aktif).length);

const layananSehat = computed(() => props.layanan.filter((l) => l.sehat).length);

/** Menyalakan selalu boleh. Mematikan hanya boleh selama masih ada kunci menyala lain. */
function bisaDimatikan(k: Kunci): boolean {
    return !k.aktif || jumlahAktif.value > 1;
}

/** Kunci yang sudah mati tidak mengurangi kunci menyala, jadi menghapusnya aman. */
function bisaDihapus(k: Kunci): boolean {
    return props.kunci.length > 1 && (!k.aktif || jumlahAktif.value > 1);
}

/**
 * Hasil uji menggantikan kotak galat terakhir untuk kunci yang sama.
 *
 * Uji yang gagal menulis galatnya ke kolom `galat_terakhir` kunci itu juga,
 * jadi tanpa penjaga ini kalimat galat yang sama muncul dua kali bertumpuk
 * persis setelah tombol Uji ditekan. Yang ditampilkan hasil ujinya, karena ia
 * yang baru saja diminta admin dan ia memuat lebih banyak keterangan.
 */
const adaHasilUji = (k: Kunci) => uji.value !== null && uji.value.id === k.id;

function status(k: Kunci): string {
    if (!k.aktif) return 'Dimatikan';
    if (k.limit_sampai) {
        return `Kena limit sampai ${waktu(k.limit_sampai)}` + (k.alasan_limit ? ` (${alasan[k.alasan_limit] ?? k.alasan_limit})` : '');
    }
    return 'Siap dipakai';
}

/**
 * Titik keadaan di rel kunci.
 *
 * Hijau siap, kuning kena limit tapi akan pulih sendiri, abu dimatikan tangan.
 * Merah sengaja tidak dipakai untuk kunci yang kena limit: kuota yang habis
 * adalah keadaan normal pada free tier, bukan kerusakan, dan mewarnainya merah
 * membuat admin mengganti kunci yang sebenarnya baik.
 */
function titikKunci(k: Kunci): string {
    if (!k.aktif) return 'bg-muted-foreground/40';
    if (k.limit_sampai) return 'bg-sentimen-review';

    return 'bg-sentimen-positif';
}

/**
 * Keadaan kanal, ditulis apa adanya.
 *
 * Tidak ada lagi keadaan "berasal dari .env". Kedua nilai hanya tersimpan di
 * database dan hanya bisa diisi dari form ini, jadi yang tersisa cuma terisi
 * atau belum.
 */
/**
 * Kunci, model, dan prompt Gemini terpisah dari kanal Telegram.
 *
 * Keduanya tidak pernah disunting dalam satu duduk: mengganti prompt tidak ada
 * hubungannya dengan mengganti chat ID. Menumpuknya dalam satu kolom hanya
 * membuat form Telegram harus dilewati dengan menggulir dua textarea panjang.
 */
const tab = ref<'gemini' | 'telegram'>('gemini');

const tabs = [
    { nilai: 'gemini' as const, label: 'Gemini', ikon: Sparkles },
    { nilai: 'telegram' as const, label: 'Telegram', ikon: Send },
];

const asalTelegram = computed(() =>
    props.telegram.siap
        ? 'Kedua nilai tersimpan di database dan dipakai apa adanya oleh pengirim alert.'
        : 'Alert tidak akan terkirim sampai token bot dan chat ID keduanya terisi.',
);
</script>

<template>
    <Head title="Pengaturan sistem" />

    <LayoutAdmin :breadcrumbs="[{ title: 'Pengaturan', href: '/admin/pengaturan' }]">
        <KopHalaman
            judul="Pengaturan sistem"
            keterangan="Model Gemini, kedua prompt, daftar kunci API, dan kanal notifikasi Telegram disetel dari layar ini. Nilai lain hanya ditampilkan."
        >
            <PilKop :nada="layananSehat === layanan.length ? 'baik' : 'buruk'" :ikon="layananSehat === layanan.length ? Server : TriangleAlert">
                <span class="angka">{{ layananSehat }}</span> dari <span class="angka">{{ layanan.length }}</span> layanan sehat
            </PilKop>
            <PilKop :nada="jumlahAktif > 0 ? 'netral' : 'buruk'" :ikon="jumlahAktif > 0 ? KeyRound : TriangleAlert">
                <span class="angka">{{ jumlahAktif }}</span> dari <span class="angka">{{ kunci.length }}</span> kunci menyala
            </PilKop>
            <PilKop
                :nada="pengaturanAi.penyedia_relevansi === 'gemini' ? 'kerja' : 'netral'"
                :ikon="pengaturanAi.penyedia_relevansi === 'gemini' ? Sparkles : Cpu"
            >
                Relevansi dinilai {{ pengaturanAi.penyedia_relevansi === 'gemini' ? 'Gemini' : 'IndoBERT' }}
            </PilKop>
            <PilKop :nada="telegram.siap ? 'baik' : 'buruk'" :ikon="telegram.siap ? Send : TriangleAlert">
                Telegram {{ telegram.siap ? 'siap' : 'belum terkonfigurasi' }}
            </PilKop>
        </KopHalaman>

        <!--
            Biru berarti keterangan, bukan peringatan. Kotak ini menjelaskan
            batas kewenangan halaman, dan mewarnainya kuning akan membuatnya
            terbaca sebagai sesuatu yang perlu diperbaiki.

            Kabutnya digambar, bukan pola berulang, dan memudar sebelum
            menyentuh teks. Yang dicari adalah penanda yang tertangkap dari
            sudut mata, bukan bidang kedua yang harus dibaca.
        -->
        <div
            class="muncul relative overflow-hidden rounded-xl bg-aksen-biru/5 p-4 ring-1 ring-aksen-biru/20 ring-inset"
            style="animation-delay: 60ms"
        >
            <div class="kabut-keterangan pointer-events-none absolute inset-0" aria-hidden="true"></div>

            <div class="relative flex items-start gap-3 text-sm">
                <span
                    class="grid size-8 shrink-0 place-items-center rounded-lg bg-aksen-biru/10 text-aksen-biru ring-1 ring-aksen-biru/20 ring-inset"
                >
                    <Info class="size-4" aria-hidden="true" />
                </span>

                <div class="min-w-0 space-y-1.5">
                    <p class="font-medium">Empat hal yang bisa disunting di sini, sisanya hanya ditampilkan.</p>
                    <p class="max-w-[80ch] leading-relaxed text-pretty text-muted-foreground">
                        Model Gemini, kedua prompt, daftar kunci API, dan kredensial Telegram disetel dari layar ini karena semuanya perlu diperbaiki
                        saat itu juga. Nilai lain di bawahnya hanya ditampilkan. Ambang keyakinan mengubah setiap angka dashboard secara surut,
                        termasuk untuk periode yang sudah dilaporkan ke pimpinan, jadi perubahannya lewat
                        <code class="rounded bg-muted px-1 py-0.5 text-xs">.env</code> dan deploy supaya tercatat di git bersama alasannya. Kolom
                        "diukur dari" ada supaya angka-angka ini tidak terbaca sebagai selera.
                    </p>
                </div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <KartuSeksi
                class="muncul"
                style="animation-delay: 100ms"
                judul="Layanan"
                catatan="Tiga proses yang harus hidup supaya klasifikasi dan pengarsipan berjalan. Yang mati tidak menghentikan crawler, hanya menumpuk pekerjaannya di antrean."
                rona="biru"
                :ikon="Server"
            >
                <div class="space-y-3 text-sm">
                    <IndikatorKesehatan
                        v-for="(l, i) in props.layanan"
                        :key="l.nama"
                        :label="l.nama"
                        :status="l.sehat ? 'hijau' : 'merah'"
                        :terakhir="i === props.layanan.length - 1"
                        :keterangan="
                            l.sehat
                                ? l.url
                                : `Tidak menjawab di ${l.url}. Job yang membutuhkannya menumpuk di antrean dan jalan lagi setelah layanan hidup.`
                        "
                    />
                </div>
            </KartuSeksi>

            <KartuSeksi
                v-if="props.evaluasi"
                class="muncul"
                style="animation-delay: 140ms"
                judul="Evaluasi model terakhir"
                catatan="F1 macro model relevansi terhadap gold set berlabel manusia. Angka ini yang menentukan apakah IndoBERT layak dipakai menggantikan Gemini."
                rona="toska"
                :ikon="Target"
            >
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div class="min-w-0">
                        <p class="angka text-4xl leading-none font-semibold tracking-tight">{{ props.evaluasi.f1_macro.toFixed(4) }}</p>
                        <p class="mt-2 text-xs text-muted-foreground">
                            Dari <span class="angka">{{ props.evaluasi.jumlah_sampel }}</span> sampel gold set,
                            {{ format(new Date(props.evaluasi.dievaluasi_at), 'd MMMM yyyy', { locale: id }) }}.
                        </p>
                    </div>

                    <!--
                        Batang perbandingan terhadap 1,0, bukan terhadap ambang
                        pilihan sendiri. Penyebutnya diketahui pasti karena F1
                        memang bernilai 0 sampai 1, jadi batang ini tidak
                        menggambar tebakan sebagai penyebut seperti yang
                        sengaja dihindari di daftar kunci.
                    -->
                    <div class="w-full max-w-[16rem] space-y-1.5">
                        <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                            <div
                                class="tumbuh h-full rounded-full bg-aksen-toska"
                                :style="{ width: `${Math.min(props.evaluasi.f1_macro, 1) * 100}%`, animationDelay: '320ms' }"
                            ></div>
                        </div>
                        <p class="flex justify-between text-[11px] text-muted-foreground">
                            <span class="angka">0</span>
                            <span>sempurna <span class="angka">1,0000</span></span>
                        </p>
                    </div>
                </div>
            </KartuSeksi>
        </div>

        <!--
            Sakelar tab. Bukan komponen baru: dua tombol dan satu ref sudah
            cukup, dan menambah pembungkus Tabs berarti empat berkas yang tidak
            pernah dipakai halaman lain.
        -->
        <div role="tablist" aria-label="Kelompok pengaturan" class="muncul inline-flex gap-1 rounded-lg bg-muted p-1" style="animation-delay: 160ms">
            <button
                v-for="t in tabs"
                :key="t.nilai"
                type="button"
                role="tab"
                :aria-selected="tab === t.nilai"
                :aria-controls="`panel-${t.nilai}`"
                class="tekan inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
                :class="tab === t.nilai ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                @click="tab = t.nilai"
            >
                <component :is="t.ikon" class="size-4" aria-hidden="true" />
                {{ t.label }}
            </button>
        </div>

        <div v-show="tab === 'gemini'" id="panel-gemini" role="tabpanel" class="space-y-4">
            <KartuSeksi
                class="muncul"
                style="animation-delay: 180ms"
                judul="Kunci API Gemini"
                catatan="Satu kunci berarti satu kuota harian. Kunci yang kena limit ditandai beserta waktu pulihnya dan dilewati sampai waktu itu, jadi kuota tidak habis untuk permintaan yang sudah pasti ditolak."
                rona="ungu"
                :ikon="KeyRound"
                :bekerja="sedangUji !== null"
            >
                <template #aksi>
                    <span class="hidden rounded-full bg-muted px-2.5 py-1 text-[11px] font-medium text-muted-foreground sm:inline-flex">
                        <span class="angka">{{ jumlahAktif }}</span>
                        <span class="px-1">dari</span>
                        <span class="angka">{{ kunci.length }}</span>
                        <span class="pl-1">menyala</span>
                    </span>
                </template>

                <div class="space-y-4">
                    <!-- Waktu reset ditulis sekali di sini, bukan diulang di tiap
                     kunci. Google memulangkan jatah harian pada pergantian hari
                     kalender waktu Pasifik, jadi seluruh kunci pulih pada detik
                     yang sama betapapun berbedanya jam mereka kehabisan. Yang
                     memang berbeda per kunci adalah limit per menit, dan itu
                     ditempelkan pada kuncinya masing-masing di bawah. -->
                    <div v-if="props.kunci.length > 0" class="flex items-start gap-2.5 rounded-lg bg-muted/50 px-3 py-2.5 text-xs">
                        <Gauge class="mt-0.5 size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <div class="min-w-0">
                            <p>
                                <span class="text-muted-foreground">Kuota harian seluruh kunci reset bersamaan:</span>
                                <span class="angka font-medium">{{ waktu(props.resetHarian) }}</span>
                            </p>
                            <p class="mt-0.5 text-muted-foreground">
                                Tengah malam waktu Pasifik, jam kalender Google, bukan 24 jam setelah kunci mulai dipakai. Limit per menit pulih
                                sendiri jauh lebih cepat dan waktunya berbeda tiap kunci, jadi ditampilkan pada kuncinya masing-masing.
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="props.kunci.length === 0"
                        class="flex items-start gap-2 rounded-lg bg-sentimen-negatif-lembut p-3 text-sm text-sentimen-negatif ring-1 ring-sentimen-negatif/25 ring-inset"
                    >
                        <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <p>Belum ada kunci di database, jadi klasifikasi tidak bisa dijalankan sampai ada satu kunci.</p>
                    </div>

                    <!-- Rel bertitik. Titiknya menjawab keadaan kunci sebelum
                     kalimat statusnya dibaca, dan itu yang dicari saat halaman
                     ini dibuka karena klasifikasi mendadak berhenti. Relnya
                     menyambungkan titik satu ke titik berikutnya, sehingga
                     daftar tiga kunci terbaca sebagai satu rangkaian giliran,
                     bukan tiga kotak yang kebetulan bertumpuk. -->
                    <ul v-else class="overflow-hidden rounded-lg border">
                        <li
                            v-for="(k, i) in props.kunci"
                            :key="k.id"
                            class="relative space-y-2 py-3.5 pr-3 pl-9 transition-colors hover:bg-muted/30"
                            :class="[i > 0 ? 'border-t' : '', i < props.kunci.length - 1 ? 'rel-kunci' : '']"
                        >
                            <span
                                class="absolute top-[1.15rem] left-3.75 size-2.5 rounded-full ring-2 ring-card"
                                :class="titikKunci(k)"
                                aria-hidden="true"
                            />

                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0 space-y-0.5">
                                    <p class="text-sm font-medium">{{ k.label }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ status(k) }}
                                        <template v-if="k.terakhir_dipakai_at">
                                            &middot; terakhir dipakai {{ waktu(k.terakhir_dipakai_at) }}
                                        </template>
                                    </p>
                                    <!-- Sisa kuota sengaja tidak ada di sini.
                                     Menghitungnya menuntut batas yang benar,
                                     dan batas yang benar hanya diketahui untuk
                                     kunci yang pernah kehabisan sampai Google
                                     menyebut angkanya di badan galat 429. Untuk
                                     kunci lain sisanya adalah pengurangan
                                     terhadap tebakan config, dan angka tebakan
                                     yang ditulis setegas angka fakta pernah
                                     berbunyi "497 sisa" untuk kunci yang detik
                                     itu juga ditolak karena kuotanya habis.

                                     Karena alasan yang sama tidak ada batang
                                     kemajuan di baris ini. Batang menuntut
                                     penyebut, dan menggambar tebakan sebagai
                                     penyebut adalah cara paling meyakinkan
                                     untuk menulis tebakan sebagai fakta. -->
                                    <!-- Batasnya selalu disebut beserta asalnya.
                                     Tiga sumber yang mungkin punya derajat yang
                                     berbeda jauh: angka Google adalah fakta,
                                     angka admin adalah keterangan yang bisa
                                     salah ketik, dan angka bawaan adalah
                                     salinan dokumentasi free tier yang meleset
                                     untuk kunci berbayar. Menuliskan ketiganya
                                     dengan kalimat yang sama membuat yang
                                     terakhir terbaca sepasti yang pertama. -->
                                    <p class="text-xs text-muted-foreground">
                                        <span class="angka text-foreground">{{ formatAngka(k.rpd_terpakai) }}</span>
                                        permintaan terkirim hari ini &middot; batas
                                        <span class="angka text-foreground">{{ formatAngka(k.rpd_berlaku) }}</span>
                                        <template v-if="k.rpd_google !== null">
                                            dari Google<template v-if="k.rpd_google_at">, {{ waktu(k.rpd_google_at) }}</template>
                                        </template>
                                        <template v-else-if="k.rpd_manual !== null"> yang Anda isi sendiri </template>
                                        <template v-else> bawaan free tier, Google belum pernah menyebut batas kunci ini </template>
                                    </p>
                                </div>

                                <!-- Satu kunci harus selalu tersisa menyala. Tombol yang akan mematikan
                                 kunci terakhir tidak ditampilkan, karena menghentikan klasifikasi
                                 seluruh sistem bukan yang dimaksud siapa pun yang menekannya. -->
                                <div class="flex shrink-0 gap-2">
                                    <!-- Menguji memakai kuota sungguhan, jadi tombolnya
                                     terkunci selama permintaannya berjalan supaya
                                     klik kedua tidak mengirim ketukan kedua. -->
                                    <!-- Ketiganya diwarnai berbeda karena akibatnya
                                     berbeda jauh. Uji hanya memakai satu
                                     permintaan, Matikan menghentikan kunci ini
                                     dipakai, dan Hapus tidak bisa dibatalkan
                                     karena kuncinya tersimpan terenkripsi dan
                                     tidak pernah bisa dibaca kembali. Tiga
                                     tombol abu-abu berjajar membuat ketiganya
                                     terlihat sama beratnya.

                                     Ronanya sekarang diambil dari token domain,
                                     bukan palet Tailwind mentah. Biru berarti
                                     memeriksa, kuning berarti menghentikan
                                     sementara, hijau berarti memberlakukan,
                                     merah berarti tidak bisa dibatalkan. -->
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        class="tekan h-8 border-aksen-biru/40 text-xs text-aksen-biru hover:bg-aksen-biru/10 hover:text-aksen-biru"
                                        :disabled="!bisaDiuji(k)"
                                        @click="ujiKunci(k)"
                                    >
                                        <Loader2
                                            v-if="sedangUji === k.id"
                                            class="size-3.5 animate-spin motion-reduce:animate-none"
                                            aria-hidden="true"
                                        />
                                        <Activity v-else class="size-3.5" aria-hidden="true" />
                                        <template v-if="sedangUji === k.id">Menguji</template>
                                        <template v-else-if="jedaUji[k.id]"
                                            >Tunggu <span class="angka">{{ jedaUji[k.id] }}</span
                                            >s</template
                                        >
                                        <template v-else>Uji</template>
                                    </Button>

                                    <Button
                                        v-if="bisaDimatikan(k)"
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        class="tekan h-8 text-xs"
                                        :class="
                                            k.aktif
                                                ? 'border-sentimen-review/40 text-sentimen-review hover:bg-sentimen-review-lembut hover:text-sentimen-review'
                                                : 'border-sentimen-positif/40 text-sentimen-positif hover:bg-sentimen-positif-lembut hover:text-sentimen-positif'
                                        "
                                        @click="ubahAktif(k)"
                                    >
                                        {{ k.aktif ? 'Matikan' : 'Nyalakan' }}
                                    </Button>

                                    <Button
                                        v-if="bisaDihapus(k)"
                                        type="button"
                                        variant="destructive"
                                        size="sm"
                                        class="tekan h-8 text-xs"
                                        @click="hapusKunci(k)"
                                    >
                                        Hapus
                                    </Button>
                                </div>
                            </div>

                            <!-- Kotak batas harian.
                             Hanya ditampilkan selama Google belum menyebut
                             angkanya sendiri. Begitu 429 harian pernah terjadi,
                             angka resminya masuk dan mengambil alih, dan kotak
                             yang tetap terbuka setelah itu hanya menerima
                             ketikan yang tidak akan pernah dipakai.

                             Ini bukan kenyamanan. Penjaga kuota menahan kunci
                             sebelum permintaan yang memicu 429 sempat dikirim,
                             jadi kunci berbayar tidak punya jalan lain untuk
                             memberitahukan jatahnya, dan tanpa kotak ini ia
                             terkunci selamanya di 500 dengan satu-satunya
                             gejala berupa antrean yang berjalan pelan. -->
                            <form v-if="k.rpd_google === null" class="flex flex-wrap items-center gap-2" @submit.prevent="simpanBatas(k)">
                                <Label :for="`rpd-${k.id}`" class="text-xs font-normal text-muted-foreground">Batas harian kunci ini</Label>
                                <Input
                                    :id="`rpd-${k.id}`"
                                    :model-value="batasHarian(k)"
                                    type="number"
                                    min="1"
                                    max="100000"
                                    inputmode="numeric"
                                    class="angka h-8 w-28 text-sm"
                                    placeholder="500"
                                    @update:model-value="batasKetikan[k.id] = String($event)"
                                />
                                <Button type="submit" variant="outline" size="sm" class="tekan h-8 text-xs">Simpan</Button>
                                <span class="text-xs text-muted-foreground">
                                    Isi kalau tier kunci ini bukan free. Kosongkan untuk memakai angka bawaan.
                                </span>
                            </form>

                            <!-- Galat terakhir yang belum tercabut oleh pemakaian
                             yang berhasil. Menempel pada kuncinya sendiri, bukan
                             di log: dengan tiga kunci, satu kunci yang salah
                             ketik hanya terbaca sebagai "klasifikasi kadang
                             gagal" kalau galatnya tidak ditunjukkan di sini.
                             Hilang sendiri begitu kunci ini berhasil dipakai
                             lagi, baik oleh antrean maupun oleh tombol Uji.

                             Disembunyikan saat ada hasil uji untuk kunci ini,
                             karena uji yang gagal menulis galatnya ke kolom yang
                             sama. Tanpa penjaga itu kalimat galat yang sama
                             muncul dua kali bertumpuk persis setelah tombol Uji
                             ditekan. -->
                            <div
                                v-if="k.galat_terakhir && !adaHasilUji(k)"
                                class="rounded-md bg-sentimen-negatif-lembut p-2 text-xs ring-1 ring-sentimen-negatif/25 ring-inset"
                            >
                                <p class="font-medium text-sentimen-negatif">
                                    Galat terakhir
                                    <span v-if="k.galat_at" class="font-normal opacity-70">&middot; {{ waktu(k.galat_at) }}</span>
                                </p>
                                <p class="mt-1 wrap-break-word text-sentimen-negatif">{{ k.galat_terakhir }}</p>
                                <p class="mt-1 text-muted-foreground">Peringatan ini hilang sendiri begitu kunci berhasil dipakai lagi.</p>
                            </div>

                            <!-- Hasil uji ditempel di bawah kuncinya sendiri, bukan di
                             toast. Jawaban model dan kalimat galat dari Google
                             adalah isi yang ingin dibaca dan dibandingkan, bukan
                             pemberitahuan yang lewat lalu hilang. -->
                            <div
                                v-if="uji && uji.id === k.id"
                                class="rounded-md p-2 text-xs ring-1 ring-inset"
                                :class="
                                    uji.berhasil
                                        ? 'bg-sentimen-positif-lembut ring-sentimen-positif/25'
                                        : 'bg-sentimen-negatif-lembut ring-sentimen-negatif/25'
                                "
                            >
                                <p class="font-medium" :class="uji.berhasil ? 'text-sentimen-positif' : 'text-sentimen-negatif'">
                                    <!-- Jawaban yang kembali tetapi tidak memuat label
                                     kunci bukan kegagalan kunci. Paketnya sampai,
                                     yang meleset instruksinya, dan menyebutnya
                                     "gagal dipakai" akan membuat admin mengganti
                                     kunci yang sebenarnya baik. -->
                                    {{ uji.berhasil ? 'Kunci berfungsi' : uji.jawaban ? 'Jawaban tidak sesuai' : 'Kunci gagal dipakai' }}
                                    <span class="angka font-normal opacity-70">({{ formatAngka(uji.ms) }} ms)</span>
                                </p>
                                <p v-if="uji.jawaban" class="mt-1 text-muted-foreground">
                                    Jawaban Gemini: <span class="text-foreground">{{ uji.jawaban }}</span>
                                </p>
                                <p v-if="uji.galat" class="mt-1 wrap-break-word text-sentimen-negatif">
                                    {{ uji.galat }}
                                </p>
                            </div>
                        </li>
                    </ul>

                    <form class="flex flex-wrap items-end gap-2 border-t pt-4" @submit.prevent="tambahKunci">
                        <div class="grid flex-1 gap-1.5">
                            <Label for="label-kunci">Label</Label>
                            <Input id="label-kunci" v-model="formKunci.label" placeholder="Akun cadangan 1" />
                            <InputError :message="formKunci.errors.label" />
                        </div>
                        <div class="grid flex-2 gap-1.5">
                            <Label for="isi-kunci">Kunci API</Label>
                            <Input id="isi-kunci" v-model="formKunci.kunci" type="password" autocomplete="off" placeholder="AIza..." />
                            <InputError :message="formKunci.errors.kunci" />
                        </div>
                        <Button type="submit" class="tekan" :disabled="formKunci.processing">
                            <Loader2 v-if="formKunci.processing" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                            Tambah kunci
                        </Button>
                    </form>

                    <p class="flex items-start gap-1.5 text-xs text-muted-foreground">
                        <Lock class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                        Kunci disimpan terenkripsi dan tidak pernah ditampilkan kembali. Kunci yang salah tempel harus dihapus lalu ditambahkan ulang.
                    </p>
                </div>
            </KartuSeksi>

            <KartuSeksi
                class="muncul"
                style="animation-delay: 220ms"
                judul="Klasifikasi"
                catatan="Menyimpan pengaturan di sini tidak mengubah artikel yang sudah dinilai. Setiap hasil menyimpan nama penilai dan versinya sendiri, jadi hasil lama dan hasil baru tetap bisa dibedakan di halaman Berita."
                rona="toska"
                :ikon="Sparkles"
            >
                <form class="space-y-5" @submit.prevent="simpanAi">
                    <div class="grid gap-2">
                        <Label>Penilai relevansi</Label>
                        <div role="radiogroup" aria-label="Penilai relevansi" class="grid gap-2 sm:grid-cols-2">
                            <button
                                v-for="p in penyediaRelevansi"
                                :key="p.nilai"
                                type="button"
                                role="radio"
                                :aria-checked="formAi.penyedia_relevansi === p.nilai"
                                :disabled="p.nilai === 'indobert' && !bisaIndoBert"
                                class="tekan relative flex flex-col gap-1.5 rounded-lg border p-3 pr-9 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50"
                                :class="formAi.penyedia_relevansi === p.nilai ? p.kelas : 'hover:bg-muted/50'"
                                @click="formAi.penyedia_relevansi = p.nilai"
                            >
                                <!-- Penanda kedua di sudut, supaya pilihan yang
                                 sedang berlaku tidak hanya dinyatakan oleh
                                 rona latarnya. Warna saja tidak terbaca semua
                                 orang, dan pada proyektor ruang rapat selisih
                                 lima persen latar hilang sama sekali. -->
                                <CircleCheck
                                    v-if="formAi.penyedia_relevansi === p.nilai"
                                    class="absolute top-3 right-3 size-4"
                                    :class="p.ikonKelas"
                                    aria-hidden="true"
                                />

                                <span class="grid size-7 place-items-center rounded-md ring-1 ring-inset" :class="p.tileKelas">
                                    <component :is="p.ikon" class="size-4 shrink-0" aria-hidden="true" />
                                </span>
                                <span class="text-sm font-medium">{{ p.label }}</span>
                                <span class="text-xs leading-relaxed text-pretty text-muted-foreground">{{ p.keterangan }}</span>
                            </button>
                        </div>

                        <!-- Nama modelnya disebut, bukan sekadar "aktif".
                         Halaman Model Relevansi bisa berisi belasan
                         pelatihan dengan metrik yang berbeda jauh, dan yang
                         perlu diketahui sebelum menyerahkan keputusan buang
                         atau simpan adalah yang mana di antara mereka. -->
                        <p v-if="props.modelRelevansiAktif" class="text-xs text-muted-foreground">
                            Model yang akan bekerja: <span class="font-medium text-foreground">{{ props.modelRelevansiAktif }}</span
                            >. Ganti lewat halaman Model Relevansi. Sentimen tetap dinilai Gemini.
                        </p>
                        <p v-else class="text-xs text-muted-foreground">
                            IndoBERT belum bisa dipilih. Latih lalu aktifkan satu model di halaman Model Relevansi terlebih dahulu.
                        </p>

                        <!-- Antrean tidak perlu dihentikan saat opsi ini
                         diganti. Job hanya membawa id barisnya, dan
                         penyedianya dibaca ulang saat job benar-benar
                         dieksekusi. Ditulis di layar karena inilah
                         pertanyaan pertama yang muncul sebelum menekan
                         tombol simpan. -->
                        <p class="text-xs text-muted-foreground">
                            Pergantian berlaku untuk artikel yang belum dinilai, termasuk yang sedang mengantre. Artikel yang sudah dinilai tidak
                            berubah dan tetap bertanda penilai lamanya.
                        </p>
                        <InputError :message="formAi.errors.penyedia_relevansi" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="model">Model Gemini</Label>
                        <Input id="model" v-model="formAi.model" placeholder="gemini-3.5-flash-lite" />
                        <InputError :message="formAi.errors.model" />
                    </div>

                    <section class="space-y-3">
                        <div class="flex items-center gap-2">
                            <h3 class="shrink-0 text-xs font-semibold tracking-wide text-muted-foreground uppercase">Prompt relevansi</h3>
                            <span class="tumbuh h-px flex-1 bg-linear-to-r from-border to-transparent" aria-hidden="true"></span>
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="versi-relevansi">Label versi</Label>
                            <Input id="versi-relevansi" v-model="formAi.versi_prompt_relevansi" placeholder="relevance-v2" />
                            <p class="text-xs text-muted-foreground">
                                Sidik isi prompt ditambahkan otomatis, jadi lupa menaikkan label tidak membuat dua prompt berbeda tercatat dengan
                                versi yang sama.
                            </p>
                            <InputError :message="formAi.errors.versi_prompt_relevansi" />
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="prompt-relevansi" class="sr-only">Isi prompt relevansi</Label>
                            <!-- Monospace di sini bukan kostum. Prompt dibaca
                             dan disunting sebagai kode: barisnya bernomor
                             dalam kepala penulisnya, dan spasi di dalam
                             contoh keluaran JSON-nya berarti. -->
                            <textarea
                                id="prompt-relevansi"
                                v-model="formAi.prompt_relevansi"
                                rows="16"
                                class="rounded-md border border-input bg-muted/30 px-3 py-2 font-mono text-xs leading-relaxed outline-hidden transition-colors focus-visible:border-ring focus-visible:bg-background focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                            <InputError :message="formAi.errors.prompt_relevansi" />
                        </div>
                    </section>

                    <section class="space-y-3">
                        <div class="flex items-center gap-2">
                            <h3 class="shrink-0 text-xs font-semibold tracking-wide text-muted-foreground uppercase">Prompt sentimen</h3>
                            <span class="tumbuh h-px flex-1 bg-linear-to-r from-border to-transparent" aria-hidden="true"></span>
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="versi-sentimen">Label versi</Label>
                            <Input id="versi-sentimen" v-model="formAi.versi_prompt_sentimen" placeholder="sentiment-v2" />
                            <InputError :message="formAi.errors.versi_prompt_sentimen" />
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="prompt-sentimen" class="sr-only">Isi prompt sentimen</Label>
                            <textarea
                                id="prompt-sentimen"
                                v-model="formAi.prompt_sentimen"
                                rows="16"
                                class="rounded-md border border-input bg-muted/30 px-3 py-2 font-mono text-xs leading-relaxed outline-hidden transition-colors focus-visible:border-ring focus-visible:bg-background focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                            <InputError :message="formAi.errors.prompt_sentimen" />
                        </div>
                    </section>

                    <div class="border-t pt-4">
                        <Button type="submit" class="tekan" :disabled="formAi.processing">
                            <Loader2 v-if="formAi.processing" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                            Simpan pengaturan
                        </Button>
                    </div>
                </form>
            </KartuSeksi>
        </div>

        <!--
            Kanal alert. Navy merek karena inilah satu-satunya kartu di halaman
            ini yang mengirim sesuatu keluar atas nama Pemkot, bukan menyetel
            sesuatu di dalam sistem.
        -->
        <div v-show="tab === 'telegram'" id="panel-telegram" role="tabpanel" class="space-y-4">
            <KartuSeksi
                class="muncul"
                style="animation-delay: 260ms"
                judul="Notifikasi Telegram"
                catatan="Telegram satu-satunya kanal alert. Aturan yang terpicu dikirim ke grup ini, dan selama kredensialnya belum lengkap aturan tetap berjalan tanpa satu pun pesan yang sampai."
                rona="brand"
                :ikon="Send"
                :bekerja="sedangUjiTelegram"
            >
                <div class="space-y-5">
                    <!--
                    Keadaan kanal, lengkap dengan asal nilainya.

                    Ditaruh di atas formnya, bukan di bawah, karena inilah yang
                    dicari saat halaman ini dibuka: apakah alert sedang bisa
                    terkirim atau tidak. Formnya baru dibaca setelah jawabannya
                    ternyata tidak.
                -->
                    <div
                        class="flex items-start gap-3 rounded-lg p-3 text-sm ring-1 ring-inset"
                        :class="
                            telegram.siap
                                ? 'bg-sentimen-positif-lembut ring-sentimen-positif/25'
                                : 'bg-sentimen-negatif-lembut ring-sentimen-negatif/25'
                        "
                    >
                        <span class="relative mt-0.5 shrink-0">
                            <component
                                :is="telegram.siap ? CircleCheck : TriangleAlert"
                                class="size-4"
                                :class="telegram.siap ? 'text-sentimen-positif' : 'text-sentimen-negatif'"
                                aria-hidden="true"
                            />
                            <!-- Denyut hanya untuk keadaan yang benar-benar rusak,
                             sama dengan aturan di IndikatorKesehatan. Kanal
                             yang siap tidak berdenyut, karena gerak yang
                             berjalan pada keadaan normal akan dipelajari mata
                             sebagai latar lalu berhenti berfungsi saat
                             keadaannya benar-benar berubah. -->
                            <span
                                v-if="!telegram.siap"
                                class="denyut absolute -top-1 -right-1 size-2 rounded-full bg-sentimen-negatif"
                                aria-hidden="true"
                            ></span>
                        </span>

                        <div class="min-w-0 space-y-1">
                            <p class="font-medium" :class="telegram.siap ? 'text-sentimen-positif' : 'text-sentimen-negatif'">
                                {{ telegram.siap ? 'Kanal siap dipakai' : 'Kanal belum terkonfigurasi' }}
                            </p>
                            <p class="text-xs leading-relaxed text-pretty text-muted-foreground">{{ asalTelegram }}</p>
                            <p class="text-xs text-muted-foreground">
                                Token bot:
                                <span class="font-medium text-foreground">{{ telegram.token_terisi ? 'terisi' : 'belum diisi' }}</span>
                                &middot; Chat ID:
                                <span class="angka font-medium text-foreground">{{ telegram.chat_id || 'belum diisi' }}</span>
                            </p>
                        </div>
                    </div>

                    <form class="space-y-4" @submit.prevent="simpanTelegram">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="telegram-token">Token bot</Label>
                                <Input
                                    id="telegram-token"
                                    v-model="formTelegram.telegram_token"
                                    type="password"
                                    autocomplete="off"
                                    placeholder="123456789:AAH..."
                                />
                                <p class="text-xs leading-relaxed text-muted-foreground">
                                    Dibuat lewat @BotFather di Telegram. Disimpan terenkripsi dan tidak pernah ditampilkan kembali, jadi
                                    <template v-if="telegram.token_terisi">
                                        biarkan kosong kalau Anda hanya ingin mengganti chat ID. Token yang tersimpan tetap dipakai.
                                    </template>
                                    <template v-else> token yang salah tempel harus diisi ulang, bukan disunting. </template>
                                </p>
                                <InputError :message="formTelegram.errors.telegram_token" />
                            </div>

                            <div class="grid gap-1.5">
                                <Label for="telegram-chat-id">Chat ID tujuan</Label>
                                <Input id="telegram-chat-id" v-model="formTelegram.telegram_chat_id" class="angka" placeholder="-1001234567890" />
                                <p class="text-xs leading-relaxed text-muted-foreground">
                                    Undang bot ke grup Diskominfo lebih dulu, lalu ambil chat ID-nya. Grup memakai angka negatif panjang, kanal publik
                                    memakai @namakanal. Mengosongkannya menghentikan alert sampai diisi lagi.
                                </p>
                                <InputError :message="formTelegram.errors.telegram_chat_id" />
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 border-t pt-4">
                            <Button type="submit" class="tekan" :disabled="formTelegram.processing">
                                <Loader2 v-if="formTelegram.processing" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                                Simpan pengaturan Telegram
                            </Button>

                            <!-- Mengirim notifikasi sungguhan ke grup, berisi
                             berita negatif terakhir di arsip. Kalimat tetap
                             hanya membuktikan token dan chat ID benar, sedangkan
                             yang perlu dilihat sebelum alert menyala adalah
                             bentuk pesannya di layar ponsel. Karena itu ia tidak
                             mendapat bidang penuh yang berarti aksi utama. -->
                            <!-- Navy penuh sebagai teks di atas kartu gelap
                             tenggelam sama sekali, jadi mode gelap memakai
                             putih. Keadaan hover ikut disebut sendiri: urutan
                             varian Tailwind tidak menjamin `dark` mengalahkan
                             `hover`, dan tanpa baris itu tombolnya berbalik
                             jadi navy di atas navy tepat saat kursor
                             menyentuhnya. -->
                            <Button
                                type="button"
                                variant="outline"
                                class="tekan border-brand/40 text-brand hover:bg-brand-lembut hover:text-brand dark:text-white dark:hover:text-white"
                                :disabled="!telegram.siap || sedangUjiTelegram"
                                @click="ujiTelegram"
                            >
                                <Loader2 v-if="sedangUjiTelegram" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                                <Send v-else class="size-4" aria-hidden="true" />
                                Kirim uji ke grup
                            </Button>

                            <p class="text-xs text-muted-foreground">
                                <template v-if="telegram.siap"
                                    >Uji memakai pengiriman sungguhan, jadi grup akan benar-benar menerima pesannya.</template
                                >
                                <template v-else>Uji baru bisa ditekan setelah token dan chat ID keduanya terisi.</template>
                            </p>
                        </div>
                    </form>
                </div>
            </KartuSeksi>
        </div>

        <!--
            Nilai yang hanya ditampilkan. Gemboknya bukan hiasan: ia yang
            membedakan kartu-kartu ini dari kartu di atasnya yang bisa disunting,
            dan tanpa penanda itu admin akan mencoba mengetik di angka yang
            memang tidak punya kotak isian.
        -->
        <KartuSeksi
            v-for="(k, i) in props.kelompok"
            :key="k.judul"
            class="muncul"
            :style="{ animationDelay: `${300 + i * 40}ms` }"
            :judul="k.judul"
            :catatan="k.catatan ?? undefined"
            rona="netral"
            :ikon="Lock"
            padat
        >
            <template #aksi>
                <span class="rounded-full bg-muted px-2.5 py-1 text-[11px] font-medium text-muted-foreground"> Hanya lewat .env </span>
            </template>

            <ul class="divide-y">
                <li v-for="n in k.nilai" :key="n.env" class="space-y-1 px-4 py-3 transition-colors hover:bg-muted/40">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-sm">{{ n.label }}</p>
                        <span class="angka text-sm font-semibold">{{ n.nilai }}</span>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        <code class="rounded bg-muted px-1 py-0.5 text-[11px]">{{ n.env }}</code>
                        <template v-if="n.diukur"> &middot; {{ n.diukur }}</template>
                    </p>
                </li>
            </ul>
        </KartuSeksi>
    </LayoutAdmin>
</template>

<style scoped>
/*
 * Kabut biru di kotak keterangan.
 *
 * Digambar, bukan pola berulang. Pola yang mengulang di belakang teks menjadi
 * unsur kedua yang harus dibaca mata, sedangkan sapuan yang memudar sebelum
 * menyentuh huruf hanya memberi arah tanpa pernah menuntut perhatian.
 */
.kabut-keterangan {
    background:
        radial-gradient(24rem 12rem at 2% -40%, color-mix(in oklab, var(--color-aksen-biru) 18%, transparent), transparent 70%),
        radial-gradient(20rem 10rem at 98% 140%, color-mix(in oklab, var(--color-aksen-toska) 12%, transparent), transparent 70%);
}

/*
 * Rel yang menyambungkan titik keadaan satu kunci ke kunci berikutnya.
 *
 * Digambar sebagai pseudo elemen, bukan div, supaya daftar yang dibacakan
 * pembaca layar tidak berisi simpul kosong. Posisi kirinya harus tepat di
 * pusat titiknya: titik berdiameter 0,625rem dipasang pada left 0,9375rem,
 * jadi pusatnya 1,25rem. Menggeser salah satunya berarti menggeser keduanya.
 *
 * Ia memudar ke bawah, jadi ujungnya tidak pernah bertabrakan dengan titik di
 * bawahnya, dan tidak pernah terbaca sebagai garis pembatas baris.
 */
.rel-kunci::before {
    content: '';
    position: absolute;
    left: 1.25rem;
    top: 2rem;
    bottom: 0.25rem;
    width: 1px;
    background: linear-gradient(180deg, hsl(var(--border)) 0%, hsl(var(--border) / 0.2) 100%);
    transform-origin: top;
    animation: rel-kunci-turun 700ms cubic-bezier(0.32, 0.72, 0, 1) both;
    animation-delay: 340ms;
}

@keyframes rel-kunci-turun {
    from {
        transform: scaleY(0);
    }

    to {
        transform: scaleY(1);
    }
}

@media (prefers-reduced-motion: reduce) {
    .rel-kunci::before {
        animation: none;
    }
}
</style>
