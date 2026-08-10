<script setup lang="ts">
import AppFooter from '@/components/AppFooter.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppShell from '@/components/AppShell.vue';
</script>

<template>
    <AppShell class="flex-col">
        <AppHeader />

        <!--
            `main` sengaja tidak memakai AppContent.

            AppContent membatasi lebarnya sendiri, sehingga latar bertinta ikut
            terpotong dan menyisakan pita putih di kiri dan kanan layar lebar.
            Latar halaman harus rata sampai tepi. Yang dibatasi lebarnya isi,
            bukan latarnya, dan itu dikerjakan LayoutEksekutif di dalam sini.
        -->
        <main class="latar-eksekutif flex w-full flex-1 flex-col">
            <slot />
        </main>

        <AppFooter />
    </AppShell>
</template>
