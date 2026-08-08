<script setup lang="ts">
import { useFormatAngka } from '@/composables/useFormatAngka';
import { computed } from 'vue';

const props = defineProps<{
    terverifikasi: number;
    menunggu: number;
    target: number | null;
    persen: number | null;
    sisaHari: number;
    tertinggal: boolean;
    besar?: boolean;
}>();

const { formatAngka, formatPersen } = useFormatAngka();

const lebar = computed(() => (props.persen === null ? '0%' : `${Math.min(100, props.persen).toFixed(2)}%`));

/**
 * "Tertinggal" dibandingkan terhadap waktu yang sudah berjalan, bukan sekadar
 * belum penuh, kontrak yang baru jalan seminggu dari tiga bulan wajar saja
 * masih jauh dari target.
 */
const warna = computed(() => {
    if (props.persen !== null && props.persen >= 100) return 'bg-sentimen-positif';

    return props.tertinggal ? 'bg-sentimen-negatif' : 'bg-primary';
});
</script>

<template>
    <div class="space-y-1">
        <p :class="besar ? 'text-sm' : 'text-xs'">
            <span class="angka font-semibold">{{ formatAngka(terverifikasi) }}</span>
            <span v-if="target" class="text-muted-foreground"> dari {{ formatAngka(target) }} pemuatan</span>
            <span v-else class="text-muted-foreground"> pemuatan, tanpa target</span>
            <span v-if="persen !== null" class="angka text-muted-foreground"> · {{ formatPersen(persen) }}</span>
        </p>

        <div v-if="target" class="h-1.5 overflow-hidden rounded-full bg-muted" :class="besar ? 'h-2.5' : ''">
            <div class="h-full transition-all" :class="warna" :style="{ width: lebar }" />
        </div>

        <p class="text-xs text-muted-foreground">
            <template v-if="sisaHari > 0">sisa {{ formatAngka(sisaHari) }} hari</template>
            <template v-else>periode berakhir</template>
            <template v-if="menunggu > 0"> · {{ formatAngka(menunggu) }} menunggu verifikasi </template>
            <span v-if="tertinggal" class="ml-1 font-medium text-sentimen-negatif">tertinggal dari laju</span>
        </p>
    </div>
</template>
