<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import { useFormatAngka } from '@/composables/useFormatAngka';
import { computed } from 'vue';

const props = defineProps<{
    label: string;
    nilai: number;
    /** Selisih terhadap periode pembanding. Angka disajikan bersama pembandingnya. */
    selisih?: number;
    satuanPembanding?: string;
    keterangan?: string;
}>();

const { formatAngka } = useFormatAngka();

const arah = computed(() => {
    if (props.selisih === undefined || props.selisih === 0) return null;
    return props.selisih > 0 ? 'naik' : 'turun';
});
</script>

<template>
    <Card>
        <CardContent class="p-4">
            <p class="text-[13px] font-medium text-muted-foreground">{{ label }}</p>
            <p class="angka mt-1 text-3xl font-semibold">{{ formatAngka(nilai) }}</p>
            <p v-if="arah" class="mt-1 text-xs text-muted-foreground">
                {{ arah }} {{ formatAngka(Math.abs(selisih!)) }} {{ satuanPembanding ?? 'dari periode sebelumnya' }}
            </p>
            <p v-else-if="keterangan" class="mt-1 text-xs text-muted-foreground">{{ keterangan }}</p>
        </CardContent>
    </Card>
</template>
