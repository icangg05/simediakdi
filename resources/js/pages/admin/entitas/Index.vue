<script setup lang="ts">
import DataTable from '@/components/data-table/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { FilterDefinisi, KolomDefinisi, OpsiFilter, PaginasiMeta } from '@/types/tabel';
import { Head, Link, router } from '@inertiajs/vue3';
import { Merge, Plus } from 'lucide-vue-next';
import { ref } from 'vue';

interface Baris {
    id: number;
    nama: string;
    jenis: string;
    alias: string[];
    artikel_count: number;
}

const props = defineProps<{
    entitas: { data: Baris[] } & PaginasiMeta;
    opsi: Record<string, OpsiFilter[]>;
}>();

const kolom: KolomDefinisi[] = [
    { kunci: 'nama', judul: 'Entitas', bisaDiurutkan: true },
    { kunci: 'jenis', judul: 'Jenis', bisaDiurutkan: true, lebar: 'w-28' },
    { kunci: 'artikel_count', judul: 'Artikel', bisaDiurutkan: true, kelas: 'angka text-right', lebar: 'w-24' },
    { kunci: 'aksi', judul: '', lebar: 'w-64' },
];

const filter: FilterDefinisi[] = [{ kunci: 'jenis', label: 'Jenis', opsi: props.opsi.jenis }];

// Penggabungan diketik sebagai id entitas induk, bukan dipilih dari dropdown
// berisi ribuan nama. Admin sudah melihat id-nya di baris yang mau digabung.
const menggabung = ref<number | null>(null);
const indukId = ref('');

function gabungkan(baris: Baris) {
    if (menggabung.value !== baris.id) {
        menggabung.value = baris.id;
        indukId.value = '';

        return;
    }

    router.post(
        `/admin/entitas/${baris.id}/gabungkan`,
        { induk_id: Number(indukId.value) },
        { preserveScroll: true, onSuccess: () => (menggabung.value = null) },
    );
}
</script>

<template>
    <Head title="Entitas" />

    <LayoutAdmin judul="Entitas" :breadcrumbs="[{ title: 'Entitas', href: '/admin/entitas' }]">
        <p class="text-sm text-muted-foreground">
            Pencocokan entitas memakai kamus, bukan model. Daftar yang dipantau Pemkot adalah daftar tertutup, dan salah
            ejaannya bisa langsung diperbaiki di sini lewat alias. Jalankan
            <code class="text-xs">hitung:entitas</code> setelah mengubah kamus agar artikel lama ikut tercocokkan.
        </p>

        <DataTable
            :kolom="kolom"
            :data="props.entitas.data"
            :meta="props.entitas"
            :filter="filter"
            pencarian
            url-basis="/admin/entitas"
            judul-kosong="Belum ada entitas"
            keterangan-kosong="Mulai dari nama wali kota, OPD utama, dan kelurahan. Entitas yang belum terdaftar memang belum dipantau siapa pun."
        >
            <template #aksi>
                <Button as-child size="sm" class="ml-auto h-8">
                    <Link href="/admin/entitas/create">
                        <Plus class="mr-1.5 h-4 w-4" />
                        Tambah entitas
                    </Link>
                </Button>
            </template>

            <template #sel-nama="{ baris }">
                <div class="min-w-0">
                    <p class="font-medium">{{ baris.nama }}</p>
                    <p v-if="baris.alias.length" class="truncate text-xs text-muted-foreground">
                        alias: {{ baris.alias.join(', ') }}
                    </p>
                </div>
            </template>

            <template #sel-jenis="{ baris }">
                <Badge variant="secondary" class="uppercase">{{ baris.jenis }}</Badge>
            </template>

            <template #sel-aksi="{ baris }">
                <div class="space-y-1.5">
                    <div class="flex justify-end gap-1.5">
                        <Button size="sm" variant="outline" class="h-7" @click="gabungkan(baris)">
                            <Merge class="mr-1 h-3.5 w-3.5" />
                            Gabungkan
                        </Button>
                        <Button as-child size="sm" variant="ghost" class="h-7">
                            <Link :href="`/admin/entitas/${baris.id}/edit`">Ubah</Link>
                        </Button>
                    </div>
                    <div v-if="menggabung === baris.id" class="space-y-1">
                        <Input
                            v-model="indukId"
                            placeholder="id entitas induk"
                            class="h-7 text-xs"
                            @keyup.enter="gabungkan(baris)"
                        />
                        <p class="text-right text-[11px] text-muted-foreground">
                            #{{ baris.id }} akan dilebur ke entitas induk. Ejaannya tersimpan sebagai alias.
                        </p>
                    </div>
                </div>
            </template>
        </DataTable>
    </LayoutAdmin>
</template>
