<script setup lang="ts">
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import SpandukGerbang from '@/pages/admin/model-relevansi/components/SpandukGerbang.vue';
import DatasetTab from '@/pages/admin/model-relevansi/tabs/DatasetTab.vue';
import PelatihanTab from '@/pages/admin/model-relevansi/tabs/PelatihanTab.vue';
import RingkasanTab from '@/pages/admin/model-relevansi/tabs/RingkasanTab.vue';
import SnapshotTab from '@/pages/admin/model-relevansi/tabs/SnapshotTab.vue';
import type { OpsiFilter } from '@/types/tabel';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    tab: string;
    gerbang: { status: string; label: string; alasan: string | null };
    ringkasan: Record<string, any> | null;
    dataset: Record<string, any> | null;
    sampel: Record<string, unknown> | null;
    snapshot: Record<string, any>[] | null;
    pelatihan: Record<string, any> | null;
    opsi: (Record<string, OpsiFilter[]> & { alasan: { relevan: OpsiFilter[]; tidak_relevan: OpsiFilter[] } }) | null;
}>();

/**
 * Empat tab, bukan delapan. Empat sisanya menunggu model yang lolos gerbang,
 * dan tab kosong yang dipasang lebih dulu membuat halaman terlihat lengkap
 * padahal tidak.
 */
const tabTersedia = [
    { kunci: 'ringkasan', label: 'Ringkasan' },
    { kunci: 'dataset', label: 'Dataset' },
    { kunci: 'snapshot', label: 'Snapshot' },
    { kunci: 'pelatihan', label: 'Pelatihan' },
];
</script>

<template>
    <Head title="Laboratorium Model Relevansi" />

    <LayoutAdmin :breadcrumbs="[{ title: 'Laboratorium relevansi', href: '/admin/model-relevansi' }]">
        <div class="space-y-4">
            <div>
                <h1 class="text-xl font-semibold">Laboratorium Model Relevansi</h1>
                <p class="text-sm text-muted-foreground">
                    Melatih dan memastikan artikel yang masuk benar-benar berkaitan dengan Pemerintah Kota Kendari.
                </p>
            </div>

            <SpandukGerbang :gerbang="gerbang" />

            <nav class="flex gap-1 border-b" aria-label="Tab laboratorium">
                <Link
                    v-for="t in tabTersedia"
                    :key="t.kunci"
                    :href="`/admin/model-relevansi?tab=${t.kunci}`"
                    class="-mb-px border-b-2 px-3 py-2 text-sm"
                    :class="tab === t.kunci ? 'border-primary font-medium' : 'border-transparent text-muted-foreground hover:text-foreground'"
                    :aria-current="tab === t.kunci ? 'page' : undefined"
                >
                    {{ t.label }}
                </Link>
            </nav>

            <RingkasanTab v-if="tab === 'ringkasan' && ringkasan" :ringkasan="ringkasan as any" />

            <DatasetTab v-else-if="tab === 'dataset' && dataset && opsi" :dataset="dataset as any" :opsi="opsi" :sampel="sampel" />

            <SnapshotTab v-else-if="tab === 'snapshot' && snapshot" :snapshot="snapshot as any" />

            <PelatihanTab v-else-if="tab === 'pelatihan' && pelatihan" :pelatihan="pelatihan as any" />
        </div>
    </LayoutAdmin>
</template>
