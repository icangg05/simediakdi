<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <!--
        min-w-0 wajib, dan itu satu-satunya tambahan terhadap komponen bawaan.
        Inset ini flex item di dalam baris flex milik SidebarProvider, dan
        min-width bawaan flex item adalah auto, artinya ia boleh melar melebihi
        lebar layar begitu isinya lebar. Satu tabel dengan pesan error panjang
        cukup untuk mendorong seluruh halaman, sidebar dan header sekalian,
        melewati tepi layar. Dengan min-w-0 tabelnya menggulung di dalam
        kotaknya sendiri, seperti yang sudah diatur Table.vue.
    -->
    <main
        :class="
            cn(
                'relative flex min-h-svh min-w-0 flex-1 flex-col bg-background',
                'peer-data-[variant=inset]:min-h-[calc(100svh-theme(spacing.4))] md:peer-data-[variant=inset]:m-2 md:peer-data-[state=collapsed]:peer-data-[variant=inset]:ml-2 md:peer-data-[variant=inset]:ml-0 md:peer-data-[variant=inset]:rounded-xl md:peer-data-[variant=inset]:shadow',
                props.class,
            )
        "
    >
        <slot />
    </main>
</template>
