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
    <!--
        `text-foreground` wajib, dan ia pasangan dari `bg-background` di baris
        yang sama.

        SidebarProvider memasang `text-sidebar-foreground` pada pembungkus
        seluruh halaman, dan kotak isi ini adalah anaknya. Selama warna sidebar
        masih abu gelap bawaan starter kit, warisan itu tidak kelihatan
        salahnya. Sejak sidebar menjadi navy, `--sidebar-foreground` menjadi
        nyaris putih, dan setiap teks di dalam kartu putih ini yang tidak
        menyetel warnanya sendiri ikut menjadi nyaris putih di atas putih.

        Yang hilang persis yang mewarisi: judul halaman, judul berita, judul
        seksi, dan breadcrumb. Yang selamat hanya yang kebetulan menyetel
        warnanya sendiri, seperti `text-muted-foreground` dan lencana, dan
        itulah yang membuat kerusakannya terlihat acak alih-alih menyeluruh.
    -->
    <main
        :class="
            cn(
                'relative flex min-h-svh min-w-0 flex-1 flex-col bg-background text-foreground',
                'peer-data-[variant=inset]:min-h-[calc(100svh-theme(spacing.4))] md:peer-data-[variant=inset]:m-2 md:peer-data-[state=collapsed]:peer-data-[variant=inset]:ml-2 md:peer-data-[variant=inset]:ml-0 md:peer-data-[variant=inset]:rounded-xl md:peer-data-[variant=inset]:shadow',
                props.class,
            )
        "
    >
        <slot />
    </main>
</template>
