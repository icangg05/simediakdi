<script setup lang="ts" generic="T extends Record<string, any>">
import DataTablePagination from '@/components/data-table/DataTablePagination.vue';
import DataTableToolbar from '@/components/data-table/DataTableToolbar.vue';
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
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
    /**
     * Kolom nomor urut di paling kiri.
     *
     * Nomornya dihitung dari `meta.from`, bukan dari indeks baris, supaya
     * penomoran berlanjut antar halaman. Baris pertama halaman kedua adalah
     * 26, bukan 1 lagi. Ini nomor urut tampilan, bukan id, jadi ia ikut
     * berubah setiap kali urutan atau filternya diganti.
     */
    nomor?: boolean;
    /** Dipakai router.get saat sort, filter, atau halaman berubah. */
    urlBasis: string;
    judulKosong?: string;
    keteranganKosong?: string;
}>();

const { urut, arah, cari, urutkan, cariDengan, saring, nilaiFilter, keHalaman, bersihkan, adaFilterAktif } = useFilterTabel(props.urlBasis);

function ikonUrut(kunci: string) {
    if (urut.value !== kunci) return ArrowUpDown;
    return arah.value === 'asc' ? ArrowUp : ArrowDown;
}

function labelUrut(kolom: KolomDefinisi): string {
    if (urut.value !== kolom.kunci) return `Urutkan menurut ${kolom.judul}`;
    return arah.value === 'asc' ? `${kolom.judul}, urutan naik. Klik untuk membalik` : `${kolom.judul}, urutan turun. Klik untuk membalik`;
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

        <!--
            Kartu, bukan sekadar kotak bergaris.

            Sebelumnya pembungkus ini hanya `rounded-md border` tanpa latar,
            sehingga isinya mewarisi latar halaman. Selama seluruh panel
            berlatar putih hal itu tidak terlihat, karena putih di dalam kotak
            sama dengan putih di luarnya. Sejak bidang isi panel admin bertinta
            abu, tabelnya ikut abu dan garis tepinya melingkari bidang yang
            warnanya sama dengan sekelilingnya. Panel eksekutif sudah lebih dulu
            punya gejala yang sama karena latarnya bergradien.

            Memakai komponen Card, bukan menyalin kelasnya, supaya radius,
            bayangan, dan warna permukaannya ikut satu sumber yang sama dengan
            seluruh kartu lain di aplikasi.

            Bilah alat dan penomoran halaman sengaja tetap di luar kartu.
            Keduanya kendali, bukan data, dan menariknya masuk membuat kartu ini
            berhenti berarti "ini tabelnya".
        -->
        <!--
            `overflow-hidden` menahan isi tabel di dalam sudut membulat kartu.
            Baris terakhir menyalakan latar saat kursor lewat, dan tanpa
            penahan ini latarnya menembus lengkungan sudut bawah dan tampak
            sebagai dua sudut siku yang mencuat.

            Aman terhadap gulir mendatar: yang menggulung adalah pembungkus
            `overflow-auto` milik Table.vue di dalam sini, bukan kartu ini. Aman
            juga terhadap menu aksi baris, karena DropdownMenuContent dirender
            lewat portal sehingga tidak pernah terpotong induknya.
        -->
        <Card class="overflow-hidden">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead v-if="nomor" class="w-12 text-right">No</TableHead>
                        <TableHead
                            v-for="kol in kolom"
                            :key="kol.kunci"
                            :class="[kol.lebar, kol.kelas]"
                            :aria-sort="urut === kol.kunci ? (arah === 'asc' ? 'ascending' : 'descending') : undefined"
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
                        <TableCell :colspan="kolom.length + (nomor ? 1 : 0) + (aksiBaris?.length ? 1 : 0)" class="p-0">
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
                        <!--
                            `meta.from` bernilai null hanya ketika hasilnya
                            kosong, dan baris ini tidak dirender dalam keadaan
                            itu. Nilai 1 dipakai sekadar supaya tipenya aman.
                        -->
                        <TableCell v-if="nomor" class="angka w-12 text-right text-muted-foreground">
                            {{ (meta.from ?? 1) + i }}
                        </TableCell>

                        <!--
                            `lebar` ikut dipasang di sel, bukan hanya di header.
                            Tabel auto-layout menawar lebar kolom dari isi
                            terlebar di seluruh kolom, jadi lebar yang hanya
                            dideklarasikan di th akan kalah oleh sel yang isinya
                            panjang.
                        -->
                        <TableCell v-for="kol in kolom" :key="kol.kunci" :class="[kol.lebar, kol.kelas]">
                            <slot :name="`sel-${kol.kunci}`" :baris="baris" :nilai="baris[kol.kunci]" :indeks="i">
                                {{ baris[kol.kunci] ?? '-' }}
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
        </Card>

        <DataTablePagination :meta="meta" @ke-halaman="keHalaman" />
    </div>
</template>
