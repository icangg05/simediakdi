<script setup lang="ts">
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import KartuEksekutif from '@/components/domain/KartuEksekutif.vue';
import KopEksekutif from '@/components/domain/KopEksekutif.vue';
import PilKop from '@/components/domain/PilKop.vue';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutEksekutif from '@/layouts/LayoutEksekutif.vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarDays,
    Check,
    ChevronDown,
    ChevronRight,
    Clock3,
    ExternalLink,
    FileText,
    Handshake,
    Newspaper,
    PenLine,
    Quote,
    Radar,
    TrendingUp,
    TriangleAlert,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type Label = 'negatif' | 'netral' | 'positif';

const props = defineProps<{
    artikel: {
        id: number;
        judul: string;
        url: string | null;
        isi: string | null;
        jumlah_kata: number | null;
        ringkasan: string | null;
        penulis: string | null;
        dipublikasikan_at: string | null;
        diambil_at: string;
        media: { nama: string; domain: string; partner: boolean } | null;
        analisis: {
            relevan: boolean;
            label: Label;
            perlu_review: boolean;
            alasan: string | null;
            bukti: string[];
            provider: string | null;
            model_versi: string | null;
            dianalisis_at: string | null;
        };
    };
    kembali: string;
}>();

const { formatAngka } = useFormatAngka();

const formatWita = new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'long',
    timeStyle: 'short',
    timeZone: 'Asia/Makassar',
});

const waktu = (nilai: string | null) => (nilai ? `${formatWita.format(new Date(nilai))} WITA` : 'Belum tersedia');

const BATAS_POTONGAN_RINGKASAN = 300;
const ringkasanTerbuka = ref(false);

const ringkasanDapatDiperluas = computed(() => (props.artikel.ringkasan?.length ?? 0) > BATAS_POTONGAN_RINGKASAN);

const ringkasanTampil = computed(() => {
    const ringkasan = props.artikel.ringkasan;

    if (!ringkasan || ringkasanTerbuka.value || !ringkasanDapatDiperluas.value) {
        return ringkasan;
    }

    const potongan = ringkasan.slice(0, BATAS_POTONGAN_RINGKASAN);
    const batasKata = potongan.lastIndexOf(' ');

    return `${potongan.slice(0, batasKata > 220 ? batasKata : BATAS_POTONGAN_RINGKASAN).trimEnd()}…`;
});

// Inertia dapat memakai ulang instance halaman ketika berpindah langsung antar
// artikel. Artikel baru selalu kembali ke keadaan ringkas agar perilakunya
// konsisten dengan kunjungan pertama.
watch(
    () => props.artikel.id,
    () => {
        ringkasanTerbuka.value = false;
    },
);

const metadata = computed(() => [
    { label: 'Penulis', nilai: props.artikel.penulis ?? 'Tidak dicantumkan sumber', ikon: PenLine },
    { label: 'Terdeteksi SIMEDIA', nilai: waktu(props.artikel.diambil_at), ikon: Clock3 },
]);

const namaLabel: Record<Label, string> = {
    negatif: 'Negatif',
    netral: 'Netral',
    positif: 'Positif',
};

const penilai = computed(() => [props.artikel.analisis.provider, props.artikel.analisis.model_versi].filter(Boolean).join(' · '));
</script>

<template>
    <Head :title="artikel.judul" />

    <LayoutEksekutif>
        <Link
            :href="kembali"
            class="tekan group inline-flex min-h-10 w-fit items-center gap-1.5 rounded-md text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
        >
            <ArrowLeft
                class="size-4 transition-transform duration-200 group-hover:-translate-x-0.5 motion-reduce:transition-none"
                aria-hidden="true"
            />
            Kembali ke daftar berita
        </Link>

        <KopEksekutif :judul="artikel.judul" keterangan="Ringkasan sumber, isi hasil ekstraksi, dan dasar penilaian sistem dalam satu tampilan.">
            <template #kendali>
                <a
                    v-if="artikel.url"
                    :href="artikel.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="tekan group inline-flex min-h-11 items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-brand shadow-lg ring-1 shadow-brand/25 ring-white/70 transition-colors hover:bg-brand-lembut focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-brand focus-visible:outline-hidden"
                >
                    Lihat artikel asli
                    <span class="sr-only">(dibuka di tab baru)</span>
                    <ExternalLink
                        class="size-4 transition-transform duration-200 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 motion-reduce:transition-none"
                        aria-hidden="true"
                    />
                </a>
            </template>

            <template #pil>
                <PilKop :ikon="Newspaper">{{ artikel.media?.nama ?? 'Media belum ditautkan' }}</PilKop>
                <PilKop v-if="artikel.media?.partner" :ikon="Handshake">Media bekerja sama</PilKop>
                <PilKop :ikon="CalendarDays">{{ waktu(artikel.dipublikasikan_at ?? artikel.diambil_at) }}</PilKop>
            </template>

            <template #inti>
                <section aria-labelledby="ringkasan-berita">
                    <h2 id="ringkasan-berita" class="text-base font-semibold">Ringkasan berita</h2>
                    <div v-if="artikel.ringkasan" class="mt-2 max-w-[72ch]">
                        <p id="isi-ringkasan-berita" class="text-sm leading-relaxed text-foreground/75">
                            {{ ringkasanTampil }}
                        </p>
                        <button
                            v-if="ringkasanDapatDiperluas"
                            type="button"
                            class="tekan mt-2 inline-flex min-h-10 items-center gap-1 rounded-md text-sm font-semibold text-brand transition-colors hover:text-brand-terang focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
                            :aria-expanded="ringkasanTerbuka"
                            aria-controls="isi-ringkasan-berita"
                            @click="ringkasanTerbuka = !ringkasanTerbuka"
                        >
                            {{ ringkasanTerbuka ? 'Tampilkan lebih sedikit' : 'Selengkapnya' }}
                            <ChevronDown
                                class="size-4 transition-transform duration-200 motion-reduce:transition-none"
                                :class="ringkasanTerbuka ? 'rotate-180' : ''"
                                aria-hidden="true"
                            />
                        </button>
                    </div>
                    <p v-else class="mt-2 max-w-[65ch] text-sm leading-relaxed text-muted-foreground">
                        Ringkasan dari sumber belum tersedia. Isi hasil ekstraksi tetap dapat dibaca pada bagian di bawah.
                    </p>
                </section>

                <dl class="mt-5 grid gap-3 border-t pt-4 sm:grid-cols-2">
                    <div v-for="item in metadata" :key="item.label" class="flex min-w-0 items-start gap-3">
                        <component :is="item.ikon" class="mt-0.5 size-4 shrink-0 text-brand" aria-hidden="true" />
                        <div class="min-w-0">
                            <dt class="text-xs font-medium text-muted-foreground">{{ item.label }}</dt>
                            <dd class="mt-0.5 text-sm font-medium break-words">{{ item.nilai }}</dd>
                        </div>
                    </div>
                </dl>
            </template>
        </KopEksekutif>

        <div class="grid items-start gap-4 lg:grid-cols-5">
            <section class="muncul lg:col-span-3" style="animation-delay: 120ms" aria-label="Isi hasil ekstraksi artikel">
                <KartuEksekutif
                    judul="Isi hasil ekstraksi"
                    catatan="Teks yang berhasil dibaca dan digunakan oleh sistem untuk menilai berita."
                    :ikon="FileText"
                    rona="biru"
                    padat
                >
                    <template #aksi>
                        <span class="angka rounded-full bg-background px-2.5 py-1 text-[11px] text-muted-foreground ring-1 ring-border ring-inset">
                            {{ formatAngka(artikel.jumlah_kata) }} kata
                        </span>
                    </template>

                    <div v-if="artikel.isi" class="relative border-t">
                        <p class="max-h-[36rem] overflow-y-auto px-5 py-4 text-sm leading-[1.8] text-pretty whitespace-pre-line sm:px-6 sm:py-5">
                            {{ artikel.isi }}
                        </p>
                        <div
                            class="pointer-events-none absolute inset-x-0 bottom-0 h-12 bg-linear-to-t from-card to-transparent"
                            aria-hidden="true"
                        ></div>
                    </div>

                    <div v-else class="flex flex-col items-center gap-2 border-t px-5 py-12 text-center">
                        <div class="grid size-10 place-items-center rounded-full bg-muted text-muted-foreground">
                            <FileText class="size-5" aria-hidden="true" />
                        </div>
                        <p class="text-sm font-medium">Isi artikel belum terekstrak</p>
                        <p class="max-w-sm text-xs leading-relaxed text-muted-foreground">
                            Sistem sudah mencatat beritanya, tetapi teks lengkap dari halaman sumber belum berhasil dibaca.
                        </p>
                    </div>
                </KartuEksekutif>
            </section>

            <aside class="muncul lg:sticky lg:top-4 lg:col-span-2" style="animation-delay: 200ms">
                <KartuEksekutif
                    judul="Putusan sistem"
                    catatan="Urutan dasar penilaian yang digunakan untuk menampilkan berita ini."
                    :ikon="Radar"
                    rona="ungu"
                >
                    <ol class="space-y-0">
                        <li class="rel-putusan relative pb-6 pl-10">
                            <span
                                class="absolute top-0 left-0 grid size-8 place-items-center rounded-full bg-aksen-toska text-white dark:text-background"
                            >
                                <Check class="size-4" aria-hidden="true" />
                            </span>

                            <p class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Relevansi</p>
                            <p class="mt-0.5 text-sm font-semibold">
                                {{ artikel.analisis.relevan ? 'Relevan dengan Pemkot Kendari' : 'Di luar cakupan Pemkot Kendari' }}
                            </p>
                            <p class="mt-1 text-xs leading-relaxed text-muted-foreground">
                                {{ penilai ? `Dinilai ${penilai}.` : 'Dinilai oleh sistem otomatis.' }}
                            </p>
                        </li>

                        <li class="rel-putusan relative pb-6 pl-10">
                            <span
                                class="absolute top-0 left-0 grid size-8 place-items-center rounded-full bg-brand-lembut text-brand dark:text-white"
                            >
                                <TrendingUp class="size-4" aria-hidden="true" />
                            </span>

                            <p class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Sentimen</p>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <BadgeSentimen :label="artikel.analisis.label" :perlu-review="artikel.analisis.perlu_review" />
                            </div>
                            <p class="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                                Analisis otomatis cenderung {{ namaLabel[artikel.analisis.label].toLowerCase() }} terhadap konteks Pemerintah Kota
                                Kendari.
                            </p>
                        </li>

                        <li class="relative pl-10">
                            <span class="absolute top-0 left-0 grid size-8 place-items-center rounded-full bg-aksen-biru/10 text-aksen-biru">
                                <Quote class="size-4" aria-hidden="true" />
                            </span>

                            <p class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Alasan penilaian</p>
                            <p v-if="artikel.analisis.alasan" class="mt-1 text-sm leading-relaxed text-foreground/80">
                                {{ artikel.analisis.alasan }}
                            </p>
                            <p v-else class="mt-1 text-xs leading-relaxed text-muted-foreground">
                                Alasan ringkas dari sistem belum tersedia untuk artikel ini.
                            </p>

                            <details v-if="artikel.analisis.bukti.length" class="group mt-3">
                                <summary
                                    class="tekan inline-flex min-h-10 cursor-pointer list-none items-center gap-1 rounded-md text-sm font-semibold text-aksen-biru transition-colors hover:text-brand focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
                                >
                                    <ChevronRight
                                        class="size-4 transition-transform duration-200 group-open:rotate-90 motion-reduce:transition-none"
                                        aria-hidden="true"
                                    />
                                    Kutipan bukti ({{ artikel.analisis.bukti.length }})
                                </summary>

                                <ul class="space-y-2 border-l border-aksen-biru/30 pl-4">
                                    <li
                                        v-for="(kutipan, urutan) in artikel.analisis.bukti"
                                        :key="urutan"
                                        class="text-sm leading-relaxed text-muted-foreground italic"
                                    >
                                        &ldquo;{{ kutipan }}&rdquo;
                                    </li>
                                </ul>
                            </details>
                            <p v-else class="mt-3 text-xs leading-relaxed text-muted-foreground">Belum ada kutipan bukti yang tersimpan.</p>
                        </li>
                    </ol>

                    <div
                        v-if="artikel.analisis.perlu_review"
                        class="mt-5 flex items-start gap-2 rounded-xl bg-sentimen-review-lembut p-3 text-sentimen-review ring-1 ring-sentimen-review/20"
                    >
                        <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <p class="text-xs leading-relaxed font-medium">
                            Keyakinan model berada di bawah ambang. Hasil ini perlu dibaca dengan hati-hati sampai admin meninjaunya.
                        </p>
                    </div>

                    <p class="mt-5 border-t pt-4 text-xs leading-relaxed text-muted-foreground">
                        Halaman eksekutif menampilkan hasil sebagai informasi baca-saja. Koreksi label dan klasifikasi ulang tetap dilakukan oleh
                        admin.
                    </p>
                </KartuEksekutif>
            </aside>
        </div>
    </LayoutEksekutif>
</template>

<style scoped>
/* Rel menghubungkan urutan relevansi, sentimen, lalu alasan tanpa menambah
   elemen dekoratif yang ikut dibacakan pembaca layar. */
.rel-putusan::before {
    content: '';
    position: absolute;
    left: 0.96875rem;
    top: 2.125rem;
    bottom: 0.375rem;
    width: 1px;
    background: linear-gradient(180deg, hsl(var(--border)) 0%, hsl(var(--border) / 0.35) 100%);
    transform-origin: top;
    animation: rel-turun 700ms cubic-bezier(0.32, 0.72, 0, 1) both;
    animation-delay: 260ms;
}

@keyframes rel-turun {
    from {
        transform: scaleY(0);
    }

    to {
        transform: scaleY(1);
    }
}

@media (prefers-reduced-motion: reduce) {
    .rel-putusan::before {
        animation: none;
    }
}
</style>
