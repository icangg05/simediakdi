<script setup lang="ts">
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItemType } from '@/types';

defineProps<{
    breadcrumbs?: BreadcrumbItemType[];
}>();
</script>

<template>
    <!--
        Dua skema warna untuk dua keadaan yang benar-benar berbeda.

        Di layar lebar kop ini berdiri di dalam kartu isi yang putih, dengan
        sidebar navy terpampang di sebelahnya. Warnanya ikut kartu, dan garis
        bawahnya memakai `border-border`, bukan `border-sidebar-border`: sejak
        sidebar berwarna navy token itu ikut menjadi navy, dan garis navy di
        atas bidang putih terbaca sebagai batas paling gelap di layar padahal
        tugasnya hanya memisahkan kop dari isi.

        Di bawah `md` sidebarnya berubah menjadi sheet yang tertutup, jadi navy
        merek tidak terlihat sama sekali dan kop ini menjadi satu-satunya chrome
        di layar. Kop putih di atas isi putih membuat aplikasi kehilangan
        seluruh identitas warnanya persis di perangkat yang paling sering
        dipakai membukanya. Di sana kop mengambil alih peran itu dan menjadi
        navy, sekaligus menyambung mulus dengan sheet yang ditariknya keluar.

        Warnanya diambil dari token sidebar, bukan ditulis ulang. Itu yang
        membuat kop mobile dan sheet yang muncul dari sisinya selalu sewarna,
        di mode terang maupun gelap, tanpa ada nilai kedua yang perlu diingat
        untuk ikut diubah.

        Ketiga komponen breadcrumb menyetel warnanya sendiri dan tidak mewarisi,
        jadi masing-masing diberi kelas `max-md:` sendiri. Tanpa itu teks
        breadcrumb tetap abu gelap dan nyaris hilang di atas navy.
    -->
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-border px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 max-md:border-sidebar-border max-md:bg-sidebar max-md:text-sidebar-foreground md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1 max-md:hover:bg-sidebar-accent max-md:hover:text-sidebar-accent-foreground" />
            <template v-if="breadcrumbs.length > 0">
                <Breadcrumb>
                    <BreadcrumbList class="max-md:text-sidebar-foreground/75">
                        <template v-for="(item, index) in breadcrumbs" :key="index">
                            <BreadcrumbItem>
                                <template v-if="index === breadcrumbs.length - 1">
                                    <BreadcrumbPage class="max-md:text-sidebar-foreground">{{ item.title }}</BreadcrumbPage>
                                </template>
                                <template v-else>
                                    <BreadcrumbLink :href="item.href" class="max-md:hover:text-sidebar-foreground">
                                        {{ item.title }}
                                    </BreadcrumbLink>
                                </template>
                            </BreadcrumbItem>
                            <BreadcrumbSeparator v-if="index !== breadcrumbs.length - 1" />
                        </template>
                    </BreadcrumbList>
                </Breadcrumb>
            </template>
        </div>
    </header>
</template>
