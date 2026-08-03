<script setup lang="ts">
import { CircleAlert, CircleCheck, CircleX } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Status warna tetap dibarengi ikon dan teks, warna saja tidak cukup dibaca
 * semua orang dan tidak akurat di proyektor ruang rapat.
 */
const props = defineProps<{
    label: string;
    status: 'hijau' | 'kuning' | 'merah';
    keterangan: string;
}>();

const varian = computed(
    () =>
        ({
            hijau: { ikon: CircleCheck, kelas: 'text-sentimen-positif', teks: 'Normal' },
            kuning: { ikon: CircleAlert, kelas: 'text-sentimen-review', teks: 'Perlu diperiksa' },
            merah: { ikon: CircleX, kelas: 'text-sentimen-negatif', teks: 'Bermasalah' },
        })[props.status],
);
</script>

<template>
    <div class="flex items-start gap-2.5">
        <component :is="varian.ikon" class="mt-0.5 h-4 w-4 shrink-0" :class="varian.kelas" aria-hidden="true" />
        <div class="min-w-0">
            <p class="font-medium">
                {{ label }}
                <span class="sr-only">: {{ varian.teks }}</span>
            </p>
            <p class="text-xs text-muted-foreground">{{ keterangan }}</p>
        </div>
    </div>
</template>
