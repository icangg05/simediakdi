<script setup lang="ts">
import { navPerPeran } from '@/nav';
import { hrefDenganPeriodeEksekutif, type PeriodeEksekutif } from '@/composables/usePeriodeEksekutif';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowUp, ShieldCheck, Sparkles } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Kaki halaman panel eksekutif.
 *
 * Warnanya navy merek, sama dengan kop di paling atas, sehingga isi halaman
 * terapit dua pita resmi dan latar bertinta di tengah punya batas yang jelas.
 * Ornamennya juga sengaja mengulang kop: busur sepusat dan sapuan cahaya, kali
 * ini dibalik ke sudut kiri bawah supaya keduanya terbaca sebagai satu bingkai
 * yang mengapit, bukan dua hiasan yang kebetulan mirip.
 *
 * Tiga hal yang wajib ada di sini: pemilik halaman, cara berita ini dinilai,
 * dan batas pemakaian angkanya. Keterangan itu tidak ditaruh di kop karena
 * tidak perlu dibaca sebelum angkanya, tapi harus selalu bisa ditemukan tanpa
 * mencari.
 */
type HalamanEksekutif = SharedData & {
    periode?: PeriodeEksekutif;
    bulan?: string;
};

const page = usePage<HalamanEksekutif>();

const tahun = new Date().getFullYear();

/**
 * Tautan cepat di bawah lambang, dibaca dari sumber yang sama dengan menu di
 * kop. Halaman ini panjang, dan setelah bergulir sampai bawah pengguna harus
 * naik lagi ke kop hanya untuk pindah halaman.
 */
const tautan = computed(() => navPerPeran[page.props.auth.user?.peran] ?? []);

const hrefDenganPeriode = (href: string) => hrefDenganPeriodeEksekutif(href, page.props.periode, page.props.bulan);

const BUSUR = [40, 66, 94, 124].map((jari, urutan) => ({
    jari,
    panjang: Math.ceil(2 * Math.PI * jari),
    jeda: urutan * 120,
}));

/**
 * Kembali ke puncak halaman.
 *
 * Dashboard eksekutif panjangnya lebih dari empat ribu piksel. Bilah menu
 * memang melekat di puncak layar, tapi angka pembuka halaman tidak, dan itu
 * yang biasanya ingin dilihat lagi setelah membaca daftar sampai habis.
 *
 * Menghormati `prefers-reduced-motion`. Gulir mulus sepanjang empat ribu piksel
 * adalah gerakan besar yang persis dihindari pengguna yang menyalakan setelan
 * itu, dan di situ lompatan seketika justru yang benar.
 */
function keAtas() {
    const halus = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    window.scrollTo({ top: 0, behavior: halus ? 'smooth' : 'auto' });
}
</script>

<template>
    <footer class="relative overflow-hidden bg-brand text-white print:hidden">
        <!--
            Sapuan cahaya sangat samar di dua sudut, senada dengan hiasan latar
            halaman. Bidang navy polos selebar layar terbaca berat, sapuan ini
            memberinya kedalaman tanpa menambah satu pun elemen yang dibaca.

            Cahaya toska di sudut kanan bawah dulunya lingkaran tersendiri dengan
            `blur-3xl`. Bentuk itu dilepas: bidang 320 piksel dengan radius kabur
            64 piksel yang terklip `overflow-hidden` memaksa browser menyiapkan
            buffer di luar layar lalu mengaburkannya, dan itu operasi raster yang
            mahal di GPU ponsel. Kaki halaman ini dipakai seluruh halaman panel
            eksekutif, jadi biayanya dibayar di mana-mana. Sebagai gradien
            biayanya nol, rupanya nyaris tidak bisa dibedakan pada kepekatan
            serendah ini, dan satu simpul DOM ikut hilang.

            Toska ditulis paling depan karena lapisan pertama yang tergambar
            paling atas, sama seperti urutan elemennya dulu.
        -->
        <div
            class="pointer-events-none absolute inset-0"
            aria-hidden="true"
            style="
                background:
                    radial-gradient(
                        15rem 15rem at right 6rem bottom 2rem,
                        rgb(from var(--color-aksen-toska) r g b / 0.22),
                        rgb(from var(--color-aksen-toska) r g b / 0.1) 45%,
                        transparent 72%
                    ),
                    radial-gradient(40rem 22rem at 6% 130%, rgb(255 255 255 / 0.12), transparent 70%),
                    radial-gradient(34rem 20rem at 96% -20%, rgb(255 255 255 / 0.08), transparent 70%);
            "
        ></div>

        <!-- Busur sepusat, cerminan ornamen kop. Di kop ia membuka dari sudut
             kanan atas, di sini menutup dari sudut kiri bawah. -->
        <svg class="pointer-events-none absolute -bottom-40 -left-28 size-96 text-white/20" viewBox="0 0 200 200" fill="none" aria-hidden="true">
            <circle
                v-for="busur in BUSUR"
                :key="busur.jari"
                class="gambar"
                cx="60"
                cy="150"
                :r="busur.jari"
                stroke="currentColor"
                stroke-width="0.75"
                :style="{ '--panjang': busur.panjang, animationDelay: `${busur.jeda}ms` }"
            />
        </svg>

        <!-- Rel merek di tepi atas, pasangan rel yang ada di kaki bilah menu. -->
        <div class="tumbuh absolute inset-x-0 top-0 h-px bg-linear-to-r from-transparent via-white/25 to-white/50" aria-hidden="true"></div>

        <div class="relative mx-auto w-full max-w-[1400px] px-4 py-10 md:px-6">
            <div class="flex flex-col gap-8 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <!--
                        Dua lambang berdampingan, dipisah satu garis tipis.

                        Kiri lambang resmi Pemerintah Kota, kanan lambang
                        SIMAK. Keduanya menyatakan hal yang berbeda: yang
                        pertama siapa pemilik halaman, yang kedua sistem apa
                        yang sedang dibuka. Menumpuk keduanya jadi satu tile
                        akan menghapus perbedaan itu, dan menaruh salah satunya
                        saja membuat kaki halaman menjanjikan hal yang tidak
                        lengkap.
                    -->
                    <div class="flex items-start gap-3">
                        <span class="flex shrink-0 items-center gap-2.5 rounded-xl bg-white/10 p-2 ring-1 ring-white/15">
                            <img
                                src="/img/Lambang_Kota_Kendari.webp"
                                alt="Lambang Kota Kendari"
                                class="size-7 object-contain"
                                width="28"
                                height="28"
                            />
                            <span class="h-7 w-px bg-white/20" aria-hidden="true"></span>
                            <img src="/img/logo-simak.webp" alt="Lambang SIMAK" class="size-7 object-contain" width="28" height="28" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm leading-tight font-semibold">SIMAK Pemerintah Kota Kendari</p>
                            <p class="text-xs leading-relaxed text-white/70">Sistem Monitoring dan Analisis Kendari</p>
                            <p class="mt-1 text-xs leading-relaxed font-medium text-white/85">Membaca Pemberitaan, Mengawal Kendari Semakin Maju.</p>
                        </div>
                    </div>

                    <!--
                        Pil, bukan daftar bertitik. Bentuknya mengulang menu di
                        kop halaman, sehingga terbaca sebagai navigasi yang sama
                        dan bukan daftar tautan baru yang perlu dipelajari.
                    -->
                    <nav v-if="tautan.length" class="mt-5 flex flex-wrap gap-1.5" aria-label="Tautan cepat">
                        <Link
                            v-for="item in tautan"
                            :key="item.href"
                            :href="hrefDenganPeriode(item.href)"
                            class="tekan inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1.5 text-xs font-medium text-white/85 ring-1 ring-white/10 transition-colors duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] ring-inset hover:bg-white/20 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white focus-visible:outline-solid"
                        >
                            <component :is="item.icon" v-if="item.icon" class="size-3.5" aria-hidden="true" />
                            {{ item.title }}
                        </Link>

                        <button
                            type="button"
                            class="tekan group inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1.5 text-xs font-medium text-white/85 ring-1 ring-white/10 transition-colors duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] ring-inset hover:bg-white/20 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white focus-visible:outline-solid"
                            @click="keAtas"
                        >
                            <ArrowUp
                                class="size-3.5 transition-transform duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] group-hover:-translate-y-0.5"
                                aria-hidden="true"
                            />
                            Kembali ke atas
                        </button>
                    </nav>
                </div>

                <!--
                    Keterangan metode, ditulis sebagai pernyataan dan bukan
                    sebagai permintaan maaf. Dipecah jadi dua kartu karena
                    isinya dua janji yang berbeda: bagaimana angkanya dibuat, dan
                    untuk apa angkanya boleh dipakai. Satu paragraf yang memuat
                    keduanya membuat kalimat terakhir, yang justru paling
                    mengikat, terbaca sebagai ekor keterangan teknis.

                    Bentuknya dua lapis seperti kartu di isi halaman: cangkang
                    bertepi tipis, inti bertinta di dalamnya.
                -->
                <div class="grid max-w-xl gap-3 sm:grid-cols-2">
                    <div class="rounded-xl border border-white/15 bg-white/6 p-4">
                        <p class="mb-1.5 flex items-center gap-2 text-xs font-semibold text-white">
                            <span class="grid size-6 shrink-0 place-items-center rounded-lg bg-white/10">
                                <Sparkles class="size-3.5" aria-hidden="true" />
                            </span>
                            Cara data ini dinilai
                        </p>
                        <p class="text-xs leading-relaxed text-white/75">
                            Sentimen pemberitaan diklasifikasikan otomatis oleh kecerdasan buatan, lalu dapat dikoreksi administrator. Sumbernya
                            portal media daring, bukan opini masyarakat.
                        </p>
                    </div>

                    <div class="rounded-xl border border-white/15 bg-white/6 p-4">
                        <p class="mb-1.5 flex items-center gap-2 text-xs font-semibold text-white">
                            <span class="grid size-6 shrink-0 place-items-center rounded-lg bg-white/10">
                                <ShieldCheck class="size-3.5" aria-hidden="true" />
                            </span>
                            Batas pemakaian
                        </p>
                        <p class="text-xs leading-relaxed text-white/75">
                            Angka di panel ini mengukur sentimen dan volume pemberitaan, bukan kinerja pemerintah. Hasilnya untuk pemantauan internal,
                            bukan data statistik resmi.
                        </p>
                    </div>
                </div>
            </div>

            <div class="mt-8 flex flex-col gap-2 border-t border-white/15 pt-5 text-xs text-white/65 sm:flex-row sm:items-center sm:justify-between">
                <p class="angka">Hak cipta {{ tahun }} Pemerintah Kota Kendari. Seluruh hak dilindungi undang-undang.</p>
                <p>Dikelola oleh Dinas Komunikasi dan Informatika Kota Kendari.</p>
            </div>
        </div>
    </footer>
</template>
