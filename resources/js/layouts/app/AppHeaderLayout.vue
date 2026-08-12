<script setup lang="ts">
import AppFooter from '@/components/AppFooter.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppShell from '@/components/AppShell.vue';
</script>

<template>
    <!--
        `variant="header"` wajib disebut. Tanpa itu AppShell jatuh ke cabang
        SidebarProvider, dan pembungkusnya memasang `text-sidebar-foreground`
        ke seluruh isi halaman. Warna itu nyaris putih karena sidebar berlatar
        navy, jadi setiap teks yang tidak menyebut warnanya sendiri menjadi
        putih di atas latar terang panel eksekutif. Judul dan label di halaman
        pengaturan hilang persis karena ini.
    -->
    <AppShell variant="header" class="flex-col">
        <AppHeader />

        <!--
            `main` sengaja tidak memakai AppContent.

            AppContent membatasi lebarnya sendiri, sehingga latar bertinta ikut
            terpotong dan menyisakan pita putih di kiri dan kanan layar lebar.
            Latar halaman harus rata sampai tepi. Yang dibatasi lebarnya isi,
            bukan latarnya, dan itu dikerjakan LayoutEksekutif di dalam sini.
        -->
        <!-- `id` menjadi sasaran tautan "Lompat ke isi halaman" di AppHeader.
             Menghapusnya membuat tautan itu diam tanpa pesan galat apa pun. -->
        <main id="isi" class="latar-eksekutif flex w-full flex-1 flex-col">
            <slot />
        </main>

        <AppFooter />
    </AppShell>
</template>
