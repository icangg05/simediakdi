<script setup lang="ts">
import { Toaster } from '@/components/ui/sonner';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType, SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { toast } from 'vue-sonner';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage<SharedData>();

// Pesan hasil aksi ditampilkan di satu tempat, bukan diulang di tiap halaman.
// Sebelum ini seluruh `->with('sukses', ...)` di controller dibagikan ke
// frontend tapi tidak pernah dirender, jadi menyimpan kontrak atau
// memverifikasi laporan terasa seperti tidak terjadi apa-apa.
watch(
    () => [page.props.flash?.sukses, page.props.flash?.galat],
    ([sukses, galat]) => {
        if (sukses) toast.success(sukses);
        // Galat bertahan lebih lama: isinya menyebut tindakan yang perlu
        // diambil, dan itu tidak selalu terbaca dalam empat detik.
        if (galat) toast.error(galat, { duration: 8000 });
    },
    { immediate: true },
);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppLayout>
    <Toaster position="top-right" rich-colors />
</template>
