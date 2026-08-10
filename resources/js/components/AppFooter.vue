<script setup lang="ts">
import { navPerPeran } from '@/nav';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Sparkles } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Kaki halaman panel eksekutif.
 *
 * Warnanya navy merek, sama dengan kop di paling atas, sehingga isi halaman
 * terapit dua pita resmi dan latar bertinta di tengah punya batas yang jelas.
 *
 * Dua hal yang wajib ada di sini: pemilik halaman dan cara berita ini dinilai.
 * Keterangan AI tidak ditaruh di kop karena tidak perlu dibaca sebelum
 * angkanya, tapi harus selalu bisa ditemukan tanpa mencari.
 */
const page = usePage<SharedData>();

const tahun = new Date().getFullYear();

/**
 * Tautan cepat di bawah lambang, dibaca dari sumber yang sama dengan menu di
 * kop. Halaman ini panjang, dan setelah bergulir sampai bawah pengguna harus
 * naik lagi ke kop hanya untuk pindah halaman.
 */
const tautan = computed(() => navPerPeran[page.props.auth.user?.peran] ?? []);
</script>

<template>
    <footer class="relative overflow-hidden bg-brand text-white">
        <!--
            Dua sapuan cahaya sangat samar di sudut, senada dengan hiasan latar
            halaman. Bidang navy polos selebar layar terbaca berat, sapuan ini
            memberinya kedalaman tanpa menambah satu pun elemen yang dibaca.
        -->
        <div class="pointer-events-none absolute -left-24 -top-28 h-72 w-72 rounded-full bg-white/[0.07] blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-32 -right-16 h-80 w-80 rounded-full bg-aksen-toska/20 blur-3xl" aria-hidden="true"></div>

        <div class="relative mx-auto w-full max-w-[1400px] px-4 py-10 md:px-6">
            <div class="flex flex-col gap-8 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="flex items-start gap-3">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-white/10">
                            <img
                                src="/img/Lambang_Kota_Kendari.webp"
                                alt="Lambang Kota Kendari"
                                class="size-8 object-contain"
                                width="32"
                                height="32"
                            />
                        </span>
                        <div>
                            <p class="text-sm font-semibold leading-tight">SIMEDIA Pemerintah Kota Kendari</p>
                            <p class="text-xs leading-relaxed text-white/70">Sistem Pemantauan dan Analisis Pemberitaan Media</p>
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
                            :href="item.href"
                            class="tekan inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1.5 text-xs font-medium text-white/85 hover:bg-white/20 hover:text-white"
                        >
                            <component :is="item.icon" v-if="item.icon" class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ item.title }}
                        </Link>
                    </nav>
                </div>

                <!--
                    Keterangan AI ditulis sebagai pernyataan metode, bukan
                    sebagai permintaan maaf. Tiga kalimat pendek: siapa yang
                    menilai, apakah manusia ikut memeriksa, dan untuk apa
                    angkanya boleh dipakai.
                -->
                <div class="max-w-[34rem] rounded-xl border border-white/15 bg-white/[0.06] p-4">
                    <p class="mb-1.5 flex items-center gap-2 text-xs font-semibold text-white">
                        <Sparkles class="h-4 w-4 shrink-0" aria-hidden="true" />
                        Cara data ini dinilai
                    </p>
                    <p class="text-xs leading-relaxed text-white/75">
                        Nada pemberitaan diklasifikasikan otomatis oleh kecerdasan buatan, lalu dapat dikoreksi administrator. Sumbernya portal media
                        daring, bukan opini masyarakat. Hasilnya untuk bahan pemantauan internal, bukan data statistik resmi.
                    </p>
                </div>
            </div>

            <div class="mt-8 flex flex-col gap-2 border-t border-white/15 pt-5 text-xs text-white/65 sm:flex-row sm:items-center sm:justify-between">
                <p class="angka">Hak cipta {{ tahun }} Pemerintah Kota Kendari. Seluruh hak dilindungi undang-undang.</p>
                <p>Dikelola oleh Dinas Komunikasi dan Informatika Kota Kendari.</p>
            </div>
        </div>
    </footer>
</template>
