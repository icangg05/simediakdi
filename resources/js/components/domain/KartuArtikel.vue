<script setup lang="ts">
import BadgeSentimen from '@/components/domain/BadgeSentimen.vue';
import { formatDistanceToNow } from 'date-fns';
import { id } from 'date-fns/locale';
import { ExternalLink } from 'lucide-vue-next';

defineProps<{
    judul: string;
    url: string;
    media: string | null;
    diambilAt: string;
    label?: 'negatif' | 'netral' | 'positif' | null;
    perluReview?: boolean;
    /** Panel eksekutif menampilkan sentimen; portal media tidak boleh. */
    tampilkanSentimen?: boolean;
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
            <p class="text-xs text-muted-foreground">
                {{ media ?? 'Media belum ditautkan' }} ·
                {{ formatDistanceToNow(new Date(diambilAt), { addSuffix: true, locale: id }) }}
            </p>
        </div>

        <BadgeSentimen
            v-if="tampilkanSentimen"
            :label="label ?? null"
            :perlu-review="perluReview"
            class="mt-0.5 shrink-0"
        />
    </article>
</template>
