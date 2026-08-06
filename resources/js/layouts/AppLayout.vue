<script setup lang="ts">
import { Toaster } from '@/components/ui/sonner';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType, SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
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
// `nonce` ikut diamati, bukan hiasan: dua pesan yang isinya sama persis
// menghasilkan nilai watch yang identik, dan toast kedua tidak pernah muncul.
watch(
    () => [page.props.flash?.nonce, page.props.flash?.sukses, page.props.flash?.galat],
    ([, sukses, galat]) => {
        // Tautan opsional dari controller, dirender sebagai tombol di dalam
        // toast. Dipakai aksi yang memindahkan barisnya keluar dari layar:
        // tanpa tombol ini admin tahu apa yang terjadi tetapi tidak punya cara
        // membuka datanya lagi selain mencarinya kembali.
        const tautan = page.props.flash?.tautan;
        const aksi = tautan
            ? { action: { label: tautan.label, onClick: () => router.visit(tautan.url) } }
            : {};

        if (sukses) toast.success(sukses, aksi);
        // Galat bertahan lebih lama: isinya menyebut tindakan yang perlu
        // diambil, dan itu tidak selalu terbaca dalam empat detik.
        if (galat) toast.error(galat, { duration: 8000, ...aksi });
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
