<script setup lang="ts" generic="T extends Record<string, any>">
import DataTablePagination from '@/components/data-table/DataTablePagination.vue';
import DataTableToolbar from '@/components/data-table/DataTableToolbar.vue';
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useFilterTabel } from '@/composables/useFilterTabel';
import type { AksiBaris, FilterDefinisi, KolomDefinisi, PaginasiMeta } from '@/types/tabel';
import { Link } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ArrowUpDown, MoreHorizontal } from 'lucide-vue-next';

const props = defineProps<{
    kolom: KolomDefinisi[];
    data: T[];
    meta: PaginasiMeta;
    filter?: FilterDefinisi[];
    pencarian?: boolean;
    aksiBaris?: AksiBaris<T>[];
    /** Dipakai router.get saat sort, filter, atau halaman berubah. */
    urlBasis: string;
    judulKosong?: string;
    keteranganKosong?: string;
}>();

const { urut, arah, cari, urutkan, cariDengan, saring, nilaiFilter, keHalaman, bersihkan, adaFilterAktif } =
    useFilterTabel(props.urlBasis);

function ikonUrut(kunci: string) {
    if (urut.value !== kunci) return ArrowUpDown;
    return arah.value === 'asc' ? ArrowUp : ArrowDown;
}

function labelUrut(kolom: KolomDefinisi): string {
    if (urut.value !== kolom.kunci) return `Urutkan menurut ${kolom.judul}`;
    return arah.value === 'asc'
        ? `${kolom.judul}, urutan naik. Klik untuk membalik`
        : `${kolom.judul}, urutan turun. Klik untuk membalik`;
}
</script>

<template>
    <div class="space-y-3">
        <DataTableToolbar
            :filter="filter"
            :pencarian="pencarian"
            :nilai-cari="cari"
            :nilai-filter="nilaiFilter"
            :ada-filter-aktif="adaFilterAktif"
            @cari="cariDengan"
            @saring="saring"
            @bersihkan="bersihkan"
        >
            <template #aksi><slot name="aksi" /></template>
        </DataTableToolbar>

        <div class="rounded-md border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead
                            v-for="kol in kolom"
                            :key="kol.kunci"
                            :class="[kol.lebar, kol.kelas]"
                            :aria-sort="
                                urut === kol.kunci ? (arah === 'asc' ? 'ascending' : 'descending') : undefined
                            "
                        >
                            <button
                                v-if="kol.bisaDiurutkan"
                                type="button"
                                class="-ml-2 inline-flex h-8 items-center gap-1 rounded px-2 hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                :aria-label="labelUrut(kol)"
                                @click="urutkan(kol.kunci)"
                            >
                                {{ kol.judul }}
                                <component :is="ikonUrut(kol.kunci)" class="h-3.5 w-3.5" aria-hidden="true" />
                            </button>
                            <span v-else>{{ kol.judul }}</span>
                        </TableHead>
                        <TableHead v-if="aksiBaris?.length" class="w-12">
                            <span class="sr-only">Aksi</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>

                <TableBody>
                    <TableRow v-if="!data.length">
                        <TableCell :colspan="kolom.length + (aksiBaris?.length ? 1 : 0)" class="p-0">
                            <KeadaanKosong
                                :judul="judulKosong ?? 'Belum ada data'"
                                :keterangan="
                                    adaFilterAktif
                                        ? 'Tidak ada baris yang cocok dengan filter. Coba longgarkan atau atur ulang filternya.'
                                        : keteranganKosong
                                "
                            />
                        </TableCell>
                    </TableRow>

                    <TableRow v-for="(baris, i) in data" :key="baris.id ?? i" class="h-10">
                        <TableCell v-for="kol in kolom" :key="kol.kunci" :class="kol.kelas">
                            <slot :name="`sel-${kol.kunci}`" :baris="baris" :nilai="baris[kol.kunci]">
                                {{ baris[kol.kunci] ?? '–' }}
                            </slot>
                        </TableCell>

                        <TableCell v-if="aksiBaris?.length">
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button variant="ghost" size="icon" class="h-8 w-8" aria-label="Aksi baris">
                                        <MoreHorizontal class="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        v-for="aksi in aksiBaris"
                                        :key="aksi.label"
                                        :class="aksi.merusak ? 'text-destructive focus:text-destructive' : ''"
                                        @select="aksi.onKlik?.(baris)"
                                    >
                                        <Link v-if="aksi.href" :href="aksi.href(baris)" class="w-full">
                                            {{ aksi.label }}
                                        </Link>
                                        <span v-else>{{ aksi.label }}</span>
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <DataTablePagination :meta="meta" @ke-halaman="keHalaman" />
    </div>
</template>
