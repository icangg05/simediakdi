<script setup lang="ts">
import KopHalaman from '@/components/domain/KopHalaman.vue';
import PilKop from '@/components/domain/PilKop.vue';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, usePoll } from '@inertiajs/vue3';
import { Check, CircleAlert, Database, FlaskConical, Loader2, Play, Star } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
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
    { id: 'snapshot', judul: 'Snapshot', ikon: Database, keterangan: 'Siapkan dataset' },
    { id: 'pelatihan', judul: 'Pelatihan', ikon: Play, keterangan: 'Latih model' },
    { id: 'pengujian', judul: 'Pengujian Model', ikon: FlaskConical, keterangan: 'Coba dengan teks' },
] as const;

type IdTab = (typeof TAB)[number]['id'];

/**
 * Tab yang terbuka disimpan di hash URL.
 *
 * Setiap aksi di halaman ini adalah kunjungan Inertia yang mengembalikan
 * seluruh prop. Tanpa hash, membuat snapshot lalu kembali akan melempar
 * pengguna ke tab pertama, dan membatalkan pelatihan melemparnya keluar dari
 * daftar yang barusan ia tatap.
 *
 * Dibaca setelah dipasang, bukan saat setup. Halaman ini ikut dirender di
 * server, tempat `window` tidak ada. Hash juga memang tidak pernah dikirim ke
 * server dalam permintaan HTTP, jadi tidak ada nilai yang bisa dirender lebih
 * awal: tab pertama selalu jadi tebakan server, lalu peramban membetulkannya.
 */
const tab = ref<IdTab>('snapshot');

onMounted(() => {
    const awal = window.location.hash.replace('#', '') as IdTab;

    if (TAB.some((t) => t.id === awal)) tab.value = awal;
});

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
 * Tiga tab ini bukan tiga pilihan sejajar, ia satu alur yang harus dilalui
 * berurutan.
 *
 * Pelatihan tidak bisa dijalankan tanpa snapshot, dan pengujian tidak bisa
 * dijalankan tanpa model yang selesai dilatih. Sebelumnya ketiganya digambar
 * sebagai tiga tombol identik di dalam satu jalur abu, dan susunan itu tidak
 * menyampaikan urutan apa pun, sehingga admin baru menemukan urutannya lewat
 * pesan galat di tab yang salah.
 *
 * Sekarang tiap langkah membawa penanda apakah ia sudah terisi. Penandanya
 * bukan sekadar hiasan: ia menjawab "aku sudah sampai mana" tanpa perlu membuka
 * ketiga tab satu per satu.
 */
const tuntas = computed<Record<IdTab, boolean>>(() => ({
    snapshot: props.snapshot.length > 0,
    pelatihan: props.pelatihan.some((p) => p.status === 'berhasil'),
    pengujian: props.riwayatUji.length > 0,
}));

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
        :breadcrumbs="[
            { title: 'Admin', href: '/admin' },
            { title: 'Model Relevansi', href: '/admin/model-relevansi' },
        ]"
    >
        <KopHalaman
            judul="Model Relevansi"
            keterangan="Menyiapkan dataset dari artikel yang sudah dinilai Gemini, melatih model relevansi di atasnya, lalu mengujinya dengan teks yang diketik sendiri."
        >
            <!-- Model aktif adalah keterangan terpenting di halaman ini: ia yang
                 benar-benar menilai artikel di produksi. Hijau berarti berhasil
                 dan berlaku, sama dengan lencana Berhasil di daftar pelatihan. -->
            <PilKop v-if="modelAktif" nada="baik" :ikon="Star">Model aktif: {{ modelAktif.nama }}</PilKop>
            <PilKop v-else nada="tunggu" :ikon="CircleAlert">Belum ada model aktif</PilKop>

            <PilKop v-if="berjalan > 0" nada="kerja" :ikon="Loader2" berputar>
                <span class="angka">{{ berjalan }}</span> pelatihan berjalan
            </PilKop>

            <!-- Layanan model dilaporkan di kop, bukan hanya di dalam tab
                 Pelatihan. Container yang mati membuat dua dari tiga tab tidak
                 berguna, dan itu perlu diketahui sebelum admin mengisi form
                 panjang di tab yang salah. -->
            <PilKop v-if="!layanan" nada="buruk" :ikon="CircleAlert">Layanan model mati</PilKop>
            <PilKop v-else>Perangkat {{ layanan.perangkat }}</PilKop>
        </KopHalaman>

        <!--
            Tab digambar sebagai tiga langkah berurutan, bukan tiga tombol
            sejajar. Panah di antaranya menyatakan bahwa yang kanan bergantung
            pada yang kiri, dan centang kecil menyatakan langkah itu sudah
            pernah terisi.

            Tetap `role="tablist"` dengan tombol ber-`role="tab"`, jadi pembaca
            layar membacanya sebagai tab seperti sebelumnya. Yang berubah hanya
            gambarnya, bukan semantiknya.
        -->
        <div
            role="tablist"
            class="muncul flex w-full items-stretch gap-1 overflow-x-auto rounded-xl border bg-card p-1.5"
            style="animation-delay: 80ms"
        >
            <template v-for="(t, i) in TAB" :key="t.id">
                <button
                    role="tab"
                    type="button"
                    :aria-selected="tab === t.id"
                    :class="[
                        'tekan group relative flex min-w-0 flex-1 items-center gap-2.5 rounded-lg px-3 py-2.5 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden',
                        tab === t.id ? 'bg-brand text-white shadow-xs' : 'hover:bg-muted',
                    ]"
                    @click="buka(t.id)"
                >
                    <span
                        class="grid size-8 shrink-0 place-items-center rounded-lg transition-colors"
                        :class="tab === t.id ? 'bg-white/15 text-white' : 'bg-muted text-muted-foreground group-hover:bg-background'"
                    >
                        <component :is="t.ikon" class="size-4" aria-hidden="true" />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5">
                            <span class="truncate text-sm font-medium" :class="tab === t.id ? 'text-white' : 'text-foreground'">{{ t.judul }}</span>
                            <!-- Centang hanya muncul kalau langkahnya memang
                                 sudah terisi, jadi ia penanda, bukan hiasan
                                 tetap. -->
                            <Check
                                v-if="tuntas[t.id]"
                                class="size-3.5 shrink-0"
                                :class="tab === t.id ? 'text-emerald-200' : 'text-sentimen-positif'"
                                aria-hidden="true"
                            />
                        </span>
                        <span class="hidden truncate text-xs sm:block" :class="tab === t.id ? 'text-white/70' : 'text-muted-foreground'">
                            {{ t.keterangan }}
                        </span>
                    </span>
                </button>

                <!-- Panah penghubung, hanya di layar yang cukup lebar. Di ponsel
                     jalur tabnya sudah bisa digeser ke samping dan panah cuma
                     memakan tempat yang dibutuhkan labelnya. -->
                <span v-if="i < TAB.length - 1" class="hidden shrink-0 items-center px-0.5 text-muted-foreground/40 sm:flex" aria-hidden="true">
                    <svg viewBox="0 0 12 24" class="h-5 w-3" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M3 6l5 6-5 6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
            </template>
        </div>

        <TabSnapshot v-show="tab === 'snapshot'" :kandidat="kandidat" :snapshot="snapshot" />
        <TabPelatihan v-show="tab === 'pelatihan'" :snapshot="snapshot" :pelatihan="pelatihan" :opsi="opsi" :layanan="layanan" />
        <TabPengujian v-show="tab === 'pengujian'" :pelatihan="pelatihan" :riwayat="riwayatUji" />
    </LayoutAdmin>
</template>
