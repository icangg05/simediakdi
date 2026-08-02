<script setup lang="ts">
import { HelpCircle, Minus, TrendingDown, TrendingUp } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Satu-satunya tempat di aplikasi yang boleh merender indikator sentimen.
 *
 * Sekitar 8% pria kesulitan membedakan merah dan hijau, dan sistem ini akan
 * diproyeksikan di layar rapat yang warnanya tidak akurat. Karena itu setiap
 * indikator membawa tiga hal sekaligus: warna, ikon, dan teks.
 */
const props = defineProps<{
    label: 'negatif' | 'netral' | 'positif' | null;
    /** Keyakinan di bawah ambang: hasilnya belum boleh dianggap fakta. */
    perluReview?: boolean;
    ringkas?: boolean;
}>();

const varian = computed(() => {
    if (props.perluReview || props.label === null) {
        return { teks: 'Perlu review', ikon: HelpCircle, kelas: 'bg-sentimen-review-lembut text-sentimen-review' };
    }

    return {
        negatif: { teks: 'Negatif', ikon: TrendingDown, kelas: 'bg-sentimen-negatif-lembut text-sentimen-negatif' },
        netral: { teks: 'Netral', ikon: Minus, kelas: 'bg-sentimen-netral-lembut text-sentimen-netral' },
        positif: { teks: 'Positif', ikon: TrendingUp, kelas: 'bg-sentimen-positif-lembut text-sentimen-positif' },
    }[props.label];
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium"
        :class="varian.kelas"
        :title="perluReview ? 'Keyakinan model di bawah ambang, hasil ini belum dapat dianggap pasti' : undefined"
    >
        <component :is="varian.ikon" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        <span :class="ringkas ? 'sr-only' : ''">{{ varian.teks }}</span>
    </span>
</template>
