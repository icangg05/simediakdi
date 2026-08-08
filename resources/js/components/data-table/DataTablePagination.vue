<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useFormatAngka } from '@/composables/useFormatAngka';
import type { PaginasiMeta } from '@/types/tabel';
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from 'lucide-vue-next';

defineProps<{ meta: PaginasiMeta }>();
const emit = defineEmits<{ keHalaman: [halaman: number] }>();

const { formatAngka } = useFormatAngka();
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-2 px-1 py-2">
        <p class="text-sm text-muted-foreground">
            <template v-if="meta.total > 0">
                Menampilkan {{ formatAngka(meta.from) }}-{{ formatAngka(meta.to) }} dari {{ formatAngka(meta.total) }} baris
            </template>
            <template v-else>Tidak ada baris</template>
        </p>

        <div class="flex items-center gap-2">
            <span class="text-sm text-muted-foreground"> Halaman {{ formatAngka(meta.current_page) }} dari {{ formatAngka(meta.last_page) }} </span>

            <Button
                variant="outline"
                size="icon"
                class="h-8 w-8"
                :disabled="meta.current_page <= 1"
                aria-label="Halaman pertama"
                @click="emit('keHalaman', 1)"
            >
                <ChevronsLeft class="h-4 w-4" />
            </Button>
            <Button
                variant="outline"
                size="icon"
                class="h-8 w-8"
                :disabled="meta.current_page <= 1"
                aria-label="Halaman sebelumnya"
                @click="emit('keHalaman', meta.current_page - 1)"
            >
                <ChevronLeft class="h-4 w-4" />
            </Button>
            <Button
                variant="outline"
                size="icon"
                class="h-8 w-8"
                :disabled="meta.current_page >= meta.last_page"
                aria-label="Halaman berikutnya"
                @click="emit('keHalaman', meta.current_page + 1)"
            >
                <ChevronRight class="h-4 w-4" />
            </Button>
            <Button
                variant="outline"
                size="icon"
                class="h-8 w-8"
                :disabled="meta.current_page >= meta.last_page"
                aria-label="Halaman terakhir"
                @click="emit('keHalaman', meta.last_page)"
            >
                <ChevronsRight class="h-4 w-4" />
            </Button>
        </div>
    </div>
</template>
