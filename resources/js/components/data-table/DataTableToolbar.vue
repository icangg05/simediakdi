<script setup lang="ts">
import DataTableFacetedFilter from '@/components/data-table/DataTableFacetedFilter.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { FilterDefinisi } from '@/types/tabel';
import { useDebounceFn } from '@vueuse/core';
import { Search, X } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const props = defineProps<{
    filter?: FilterDefinisi[];
    pencarian?: boolean;
    nilaiCari: string;
    nilaiFilter: (kunci: string) => string[];
    adaFilterAktif: boolean;
}>();

const emit = defineEmits<{
    cari: [kata: string];
    saring: [kunci: string, nilai: string[]];
    bersihkan: [];
}>();

const kata = ref(props.nilaiCari);

// Jangan tembak server tiap ketukan tombol.
const cariTertunda = useDebounceFn((nilai: string) => emit('cari', nilai), 350);

watch(kata, (nilai) => cariTertunda(nilai));
watch(
    () => props.nilaiCari,
    (nilai) => {
        if (nilai !== kata.value) kata.value = nilai;
    },
);
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <div v-if="pencarian" class="relative">
            <Search class="pointer-events-none absolute left-2 top-2.5 h-4 w-4 text-muted-foreground" />
            <!-- Pencarian aktif ikut menyala, sama seperti filter di sebelahnya. -->
            <Input
                v-model="kata"
                placeholder="Cari…"
                class="h-8 w-56 pl-8"
                :class="kata ? 'border-primary bg-primary/10' : ''"
                aria-label="Cari di tabel"
            />
        </div>

        <DataTableFacetedFilter
            v-for="f in filter ?? []"
            :key="f.kunci"
            :filter="f"
            :terpilih="nilaiFilter(f.kunci)"
            @ubah="(nilai) => emit('saring', f.kunci, nilai)"
        />

        <Button v-if="adaFilterAktif" variant="ghost" size="sm" class="h-8 px-2" @click="emit('bersihkan')">
            Atur ulang
            <X class="ml-2 h-4 w-4" />
        </Button>

        <slot name="aksi" />
    </div>
</template>
