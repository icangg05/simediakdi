<script setup lang="ts">
import { CircleAlert, CircleCheck, CircleX } from 'lucide-vue-next';
import { computed, type Component } from 'vue';

/**
 * Satu baris lampu kesehatan: subjek, keadaan, dan penjelasannya.
 *
 * Status warna tetap dibarengi ikon dan teks, warna saja tidak cukup dibaca
 * semua orang dan tidak akurat di proyektor ruang rapat. Di baris ini penanda
 * keduanya ada tiga sekaligus: rona tile, ikon keadaan, dan kata keadaan yang
 * benar-benar tercetak, bukan hanya tersembunyi untuk pembaca layar. Kata itu
 * dulu `sr-only`, dan akibatnya admin yang membedakan hijau dari kuning dengan
 * susah payah tidak punya satu pun penanda yang bisa dibaca dengan mata.
 *
 * Ronanya mengikuti kosakata warna panel admin: hijau berarti sehat, kuning
 * berarti menunggu atau perlu diperiksa, merah berarti rusak. Sama persis
 * dengan arti ketiganya di halaman Antrean AI dan di badge sentimen.
 */
const props = withDefaults(
    defineProps<{
        label: string;
        status: 'hijau' | 'kuning' | 'merah';
        keterangan: string;
        /** Ikon subjek yang dipantau, misalnya antena untuk crawler. */
        ikon?: Component;
        /** Baris terakhir tidak menggambar rel penghubung ke bawah. */
        terakhir?: boolean;
    }>(),
    { ikon: undefined, terakhir: false },
);

const varian = computed(
    () =>
        ({
            hijau: {
                ikon: CircleCheck,
                teks: 'Normal',
                tile: 'bg-sentimen-positif-lembut text-sentimen-positif ring-sentimen-positif/25',
                nada: 'text-sentimen-positif',
            },
            kuning: {
                ikon: CircleAlert,
                teks: 'Perlu diperiksa',
                tile: 'bg-sentimen-review-lembut text-sentimen-review ring-sentimen-review/30',
                nada: 'text-sentimen-review',
            },
            merah: {
                ikon: CircleX,
                teks: 'Bermasalah',
                tile: 'bg-sentimen-negatif-lembut text-sentimen-negatif ring-sentimen-negatif/30',
                nada: 'text-sentimen-negatif',
            },
        })[props.status],
);

/*
 * Denyut hanya untuk yang merah, dan itu batas yang sengaja ketat.
 *
 * Halaman ini dibuka sepanjang jam kerja. Gerak yang berjalan terus pada
 * keadaan yang normal akan dipelajari mata sebagai latar, dan begitu ia
 * dipelajari sebagai latar ia berhenti berfungsi saat keadaannya benar-benar
 * berubah. Kuning tidak ikut berdenyut karena kuning berarti tunggu, bukan
 * bertindak sekarang.
 */
const berdenyut = computed(() => props.status === 'merah');
</script>

<template>
    <div class="relative pb-3.5 pl-11 last:pb-0" :class="terakhir ? 'rel-akhir' : 'rel-status'">
        <!--
            Tile subjek, bukan sekadar titik warna. Ikon di dalamnya menyebut apa
            yang dipantau, dan rona bidangnya menyebut keadaannya, sehingga satu
            bentuk memikul dua keterangan tanpa menambah satu baris teks pun.
        -->
        <span class="absolute top-0 left-0 grid size-8 place-items-center rounded-lg ring-1 ring-inset" :class="varian.tile">
            <component :is="ikon ?? varian.ikon" class="size-4" aria-hidden="true" />

            <!-- Penanda merah menempel di sudut tile, cukup kecil untuk tidak
                 menutupi ikon subjeknya dan cukup terang untuk tertangkap dari
                 sudut mata. -->
            <span
                v-if="berdenyut"
                class="denyut absolute -top-0.5 -right-0.5 size-2 rounded-full bg-sentimen-negatif ring-2 ring-card"
                aria-hidden="true"
            ></span>
        </span>

        <div class="min-w-0 space-y-0.5">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                <p class="text-sm leading-tight font-medium">{{ label }}</p>

                <span class="inline-flex items-center gap-1 text-xs font-medium" :class="varian.nada">
                    <component :is="varian.ikon" class="size-3.5 shrink-0" aria-hidden="true" />
                    {{ varian.teks }}
                </span>
            </div>

            <p class="text-xs leading-relaxed text-pretty text-muted-foreground">{{ keterangan }}</p>
        </div>
    </div>
</template>

<style scoped>
/*
 * Rel yang menghubungkan tile satu ke tile berikutnya.
 *
 * Digambar sebagai pseudo elemen, bukan div, supaya daftar yang dibacakan
 * pembaca layar tidak berisi simpul kosong. Ia memudar ke bawah, jadi ujungnya
 * tidak pernah bertabrakan dengan tile di bawahnya.
 */
.rel-status::before {
    content: '';
    position: absolute;
    left: 0.9375rem;
    top: 2.25rem;
    bottom: 0.375rem;
    width: 1px;
    background: linear-gradient(180deg, hsl(var(--border)) 0%, hsl(var(--border) / 0.25) 100%);
    transform-origin: top;
    animation: rel-status-turun 700ms cubic-bezier(0.32, 0.72, 0, 1) both;
    animation-delay: 320ms;
}

@keyframes rel-status-turun {
    from {
        transform: scaleY(0);
    }

    to {
        transform: scaleY(1);
    }
}

@media (prefers-reduced-motion: reduce) {
    .rel-status::before {
        animation: none;
    }
}
</style>
