<script setup lang="ts">
import { Inbox } from 'lucide-vue-next';

defineProps<{
    judul: string;
    /** Sebutkan tindakan yang bisa diambil, bukan sekadar "tidak ada data". */
    keterangan?: string;
}>();
</script>

<template>
    <div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center">
        <Inbox class="h-8 w-8 text-muted-foreground" aria-hidden="true" />
        <p class="text-sm font-medium">{{ judul }}</p>
        <p v-if="keterangan" class="max-w-sm text-sm text-muted-foreground">{{ keterangan }}</p>
        <slot />
    </div>
</template>
