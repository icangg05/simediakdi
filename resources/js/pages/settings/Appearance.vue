<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { Card, CardContent } from '@/components/ui/card';
import { type BreadcrumbItem } from '@/types';
import { Palette } from 'lucide-vue-next';

import SettingsLayout from '@/layouts/settings/Layout.vue';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Tampilan',
        href: '/settings/appearance',
    },
];
</script>

<template>
    <SettingsLayout :breadcrumbs="breadcrumbItems">
        <Head title="Tampilan" />

        <Card class="muncul overflow-hidden" style="animation-delay: 80ms">
            <CardContent class="space-y-5 p-5 sm:p-6">
                <div class="flex items-center gap-2">
                    <Palette class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <h2 class="shrink-0 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Tema</h2>
                    <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                </div>

                <p class="text-sm text-muted-foreground">
                    Pilihan ini tersimpan di peramban ini saja, bukan di akun Anda, jadi ia tidak ikut berpindah ke komputer lain. Pilihan
                    <strong class="font-medium text-foreground">Sistem</strong> mengikuti setelan terang atau gelap pada perangkat Anda.
                </p>

                <AppearanceTabs />
            </CardContent>
        </Card>
    </SettingsLayout>
</template>
