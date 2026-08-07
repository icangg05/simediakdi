<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, usePoll } from '@inertiajs/vue3';
import { Database, FlaskConical, Play } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import TabPelatihan from './TabPelatihan.vue';
import TabPengujian from './TabPengujian.vue';
import TabSnapshot from './TabSnapshot.vue';
import type { Kandidat, Layanan, Opsi, Pelatihan, RiwayatUji, Snapshot } from './tipe';

const props = defineProps<{
    kandidat: Kandidat;
    snapshot: Snapshot[];
    pelatihan: Pelatihan[];
    riwayatUji: RiwayatUji[];
    opsi: Opsi;
    layanan: Layanan | null;
    diperbarui: string;
}>();

const TAB = [
    { id: 'snapshot', judul: 'Snapshot', ikon: Database },
    { id: 'pelatihan', judul: 'Pelatihan', ikon: Play },
    { id: 'pengujian', judul: 'Pengujian Model', ikon: FlaskConical },
] as const;

type IdTab = (typeof TAB)[number]['id'];

/**
 * Tab yang terbuka disimpan di hash URL.
 *
 * Setiap aksi di halaman ini adalah kunjungan Inertia yang mengembalikan
 * seluruh prop. Tanpa hash, membuat snapshot lalu kembali akan melempar
 * pengguna ke tab pertama, dan membatalkan pelatihan melemparnya keluar dari
 * daftar yang barusan ia tatap.
 */
const awal = window.location.hash.replace('#', '') as IdTab;
const tab = ref<IdTab>(TAB.some((t) => t.id === awal) ? awal : 'snapshot');

/**
 * Hash ditukar tanpa menyentuh isi history state.
 *
 * Argumen pertama wajib `history.state`, bukan `null`. Inertia menyimpan
 * seluruh objek halaman di sana, dan menimpanya dengan null membuang objek itu.
 * Akibatnya bukan sekadar tab yang lupa diri: Inertia menaruh tiap tulisan ke
 * history di satu antrean serial, dan `saveDocumentScrollPosition` membaca
 * `window.history.state.page` tanpa tanda tanya. Pada state yang null ia
 * melempar TypeError, antreannya berhenti selamanya, dan callback yang
 * seharusnya memicu penggantian komponen tidak pernah dipanggil lagi. Sejak
 * gulir pertama sesudah tab ditukar, seluruh kunjungan Inertia di halaman ini
 * menerima data baru dari server lalu membuangnya, jadi snapshot yang barusan
 * dibuat maupun dihapus tidak pernah muncul sampai halaman dimuat ulang.
 */
function buka(id: IdTab) {
    tab.value = id;
    history.replaceState(history.state, '', `#${id}`);
}

const berjalan = computed(() => props.pelatihan.filter((p) => p.status === 'menunggu' || p.status === 'berjalan').length);

const modelAktif = computed(() => props.pelatihan.find((p) => p.aktif) ?? null);

/**
 * Polling hanya selama ada pelatihan yang belum selesai.
 *
 * Halaman ini juga dibuka untuk sekadar membaca hasil lama, dan menariknya tiap
 * tiga detik selamanya berarti dua puluh permintaan semenit yang seluruhnya
 * mengembalikan angka yang sama. `only` menjaga tarikan tetap tipis: menu, data
 * pengguna, opsi, dan keadaan layanan tidak berubah dan tidak perlu ikut
 * dikirim.
 *
 * `keepAlive` wajib menyala di halaman ini. Tanpanya Inertia menurunkan laju
 * tarikan menjadi sepersepuluh begitu tab browser tidak lagi di depan, jadi
 * tiga detik menjadi tiga puluh detik. Itu setelan yang benar untuk halaman
 * pemantauan yang ditatap terus, dan salah untuk pekerjaan yang berjalan dua
 * puluh menit: tidak ada yang menunggui pelatihan sambil menatap layar, dan
 * kembali ke tab ini setelah menyeduh kopi seharusnya tidak memperlihatkan
 * angka setengah menit yang lalu.
 */
const poll = usePoll(3000, { only: ['snapshot', 'pelatihan', 'riwayatUji', 'kandidat', 'diperbarui'] }, { autoStart: false, keepAlive: true });

/**
 * Satu tempat yang memutuskan tarikan menyala atau mati.
 *
 * `immediate` menggantikan `autoStart`. Sebelumnya keadaan awal ditentukan
 * opsi `autoStart` dan perubahannya ditentukan watch ini, dua jalur yang harus
 * sepakat dan tidak selalu sepakat: pelatihan yang dikirim dari halaman ini
 * membuat Inertia menyusun ulang komponennya, dan tarikan yang menyala lewat
 * watch ikut mati bersama komponen lama sementara komponen baru membaca
 * `autoStart` dari keadaan yang sudah berubah.
 *
 * `start()` aman dipanggil berulang, ia menghentikan interval lamanya lebih
 * dulu.
 */
watch(berjalan, (jumlah) => (jumlah > 0 ? poll.start() : poll.stop()), { immediate: true });
</script>

<template>
    <Head title="Model Relevansi" />

    <LayoutAdmin
        judul="Model Relevansi"
        :breadcrumbs="[
            { title: 'Admin', href: '/admin' },
            { title: 'Model Relevansi', href: '/admin/model-relevansi' },
        ]"
    >
        <p class="-mt-2 max-w-3xl text-muted-foreground">
            Menyiapkan dataset dari artikel yang sudah dinilai Gemini, melatih model relevansi di atasnya, lalu mengujinya dengan teks yang diketik
            sendiri.
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <Badge v-if="modelAktif" class="bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">
                Model aktif: {{ modelAktif.nama }}
            </Badge>
            <Badge v-else variant="outline">Belum ada model aktif</Badge>

            <Badge v-if="berjalan > 0" class="bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200"> {{ berjalan }} pelatihan berjalan </Badge>
        </div>

        <!-- Tab sebagai daftar tombol, bukan komponen tersendiri. Yang dibutuhkan
             halaman ini hanya tiga tombol yang menukar isi, dan role tablist di
             sini sudah membuat pembaca layar membacanya sebagai tab. -->
        <div role="tablist" class="flex w-full gap-1 overflow-x-auto rounded-lg border bg-muted/40 p-1">
            <button
                v-for="t in TAB"
                :key="t.id"
                role="tab"
                type="button"
                :aria-selected="tab === t.id"
                :class="[
                    'flex flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium transition',
                    tab === t.id ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                ]"
                @click="buka(t.id)"
            >
                <component :is="t.ikon" class="h-4 w-4" aria-hidden="true" />
                {{ t.judul }}
            </button>
        </div>

        <TabSnapshot v-show="tab === 'snapshot'" :kandidat="kandidat" :snapshot="snapshot" />
        <TabPelatihan v-show="tab === 'pelatihan'" :snapshot="snapshot" :pelatihan="pelatihan" :opsi="opsi" :layanan="layanan" />
        <TabPengujian v-show="tab === 'pengujian'" :pelatihan="pelatihan" :riwayat="riwayatUji" />
    </LayoutAdmin>
</template>
