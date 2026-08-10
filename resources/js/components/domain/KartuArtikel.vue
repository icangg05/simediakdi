<script setup lang="ts">
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import { formatDistanceToNow } from 'date-fns';
import { id } from 'date-fns/locale';
import { ExternalLink, Newspaper } from 'lucide-vue-next';

defineProps<{
    judul: string;
    url: string;
    media: string | null;
    diambilAt: string;
    label?: 'negatif' | 'netral' | 'positif' | null;
    perluReview?: boolean;
    /** Panel eksekutif menampilkan sentimen; portal media tidak boleh. */
    tampilkanSentimen?: boolean;
    /**
     * Alasan model memberi label itu, satu atau dua kalimat.
     *
     * Opsional, karena tidak setiap pemanggil mengirimnya dan baris analisis
     * lama bisa saja kosong. Judul berita sering tidak cukup untuk menilai
     * apakah labelnya masuk akal, dan kalimat ini yang membuat lompatan dari
     * judul ke label bisa diperiksa tanpa membuka artikelnya.
     */
    ringkasanAi?: string | null;
}>();
</script>

<template>
    <article class="flex items-start justify-between gap-3 py-2.5">
        <div class="min-w-0 space-y-1">
            <a
                :href="url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-start gap-1 text-sm font-medium leading-snug hover:underline"
            >
                <span class="line-clamp-2">{{ judul }}</span>
                <ExternalLink class="mt-0.5 h-3 w-3 shrink-0 text-muted-foreground" aria-hidden="true" />
            </a>
            <p v-if="ringkasanAi" class="line-clamp-2 text-xs leading-relaxed text-foreground/70">{{ ringkasanAi }}</p>

            <!--
                Nama media jadi lencana, bukan teks abu-abu yang menyatu dengan
                keterangan waktu di sebelahnya. Nama media adalah hal yang
                paling sering dicari mata setelah judul, dan sebagai teks polos
                ia justru bagian paling tidak terlihat di baris ini.

                Warnanya netral, bukan warna nada. Di halaman ini warna berarti
                nada pemberitaan, dan lencana media berwarna akan terbaca
                seolah medianya sendiri yang bernada.
            -->
            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                <span class="inline-flex max-w-full items-center gap-1.5 rounded-md bg-muted px-2 py-0.5 font-medium text-foreground/75">
                    <Newspaper class="h-3 w-3 shrink-0" aria-hidden="true" />
                    <span class="truncate">{{ media ?? 'Media belum ditautkan' }}</span>
                </span>
                {{ formatDistanceToNow(new Date(diambilAt), { addSuffix: true, locale: id }) }}
            </p>
        </div>

        <BadgeSentimen v-if="tampilkanSentimen" :label="label ?? null" :perlu-review="perluReview" class="mt-0.5 shrink-0" />
    </article>
</template>
