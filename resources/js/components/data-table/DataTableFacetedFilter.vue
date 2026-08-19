<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import type { FilterDefinisi } from '@/types/tabel';
import { Filter, PlusCircle, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    filter: FilterDefinisi;
    terpilih: string[];
}>();

const emit = defineEmits<{ ubah: [nilai: string[]] }>();

/**
 * Kotak cari di dalam menu, muncul begitu daftarnya cukup panjang untuk
 * digulir.
 *
 * Filter media memuat tiga puluh nama, dan menemukan satu di antaranya berarti
 * menggulir daftar sambil membaca setiap baris. Ambangnya delapan, sekitar
 * setinggi menu sebelum ia mulai memotong isinya: di bawah itu seluruh pilihan
 * sudah terlihat sekaligus dan kotak cari cuma menambah satu benda yang harus
 * dilewati.
 */
const AMBANG_CARI = 8;

const kata = ref('');
const pakaiCari = computed(() => props.filter.opsi.length > AMBANG_CARI);

const opsiTersaring = computed(() => {
    const cari = kata.value.trim().toLowerCase();

    if (cari === '') return props.filter.opsi;

    return props.filter.opsi.filter((o) => o.label.toLowerCase().includes(cari));
});

function alihkan(nilai: string) {
    const berikutnya = props.terpilih.includes(nilai) ? props.terpilih.filter((n) => n !== nilai) : [...props.terpilih, nilai];

    emit('ubah', berikutnya);
}

function labelTerpilih(): string {
    return props.filter.opsi
        .filter((o) => props.terpilih.includes(o.nilai))
        .map((o) => o.label)
        .join(', ');
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <!--
                Garis putus-putus berarti belum dipakai, garis penuh berwarna
                berarti sedang menyaring. Badge nilai terpilih saja tidak cukup:
                di deretan enam filter, yang dicari mata lebih dulu adalah mana
                yang menyala, bukan membaca isi tiap tombol satu per satu.
            -->
            <Button
                variant="outline"
                size="sm"
                class="h-8"
                :class="terpilih.length ? 'border-primary bg-primary/10 text-foreground hover:bg-primary/15' : 'border-dashed'"
            >
                <component :is="terpilih.length ? Filter : PlusCircle" class="mr-2 h-4 w-4" />
                {{ filter.label }}
                <template v-if="terpilih.length">
                    <span class="mx-2 h-4 w-px bg-border" />
                    <Badge v-if="terpilih.length <= 2" variant="secondary" class="rounded-sm px-1 font-normal">
                        {{ labelTerpilih() }}
                    </Badge>
                    <Badge v-else variant="secondary" class="rounded-sm px-1 font-normal"> {{ terpilih.length }} dipilih </Badge>
                </template>
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="start" class="w-56">
            <!--
                Tingginya dibatasi dan sisanya digulir. Filter media memuat tiga
                puluh pilihan, dan tanpa batas ini daftarnya tumbuh melewati tepi
                layar sehingga pilihan terbawah tidak bisa dijangkau sama sekali.
                Batasnya dipasang di sini, bukan di halaman pemanggil, karena
                setiap filter berdaftar panjang punya masalah yang sama.

                Yang digulir hanya daftar pilihannya. Tombol hapus filter tetap
                menempel di kaki menu, supaya jalan keluarnya tidak ikut hilang
                ke bawah bersama dua puluh baris lainnya.
            -->
            <!--
                `keydown.stop` wajib. DropdownMenu menyulap tiap huruf menjadi
                lompatan ke pilihan yang berawalan huruf itu, dan tanpa penahan
                ini mengetik nama media memindahkan fokus keluar dari kotak
                cari pada ketukan pertama.
            -->
            <div v-if="pakaiCari" class="relative px-2 pt-1 pb-2">
                <Search class="pointer-events-none absolute top-1/2 left-4 size-3.5 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <Input
                    v-model="kata"
                    type="search"
                    class="h-8 pl-7 text-sm"
                    :placeholder="`Cari ${filter.label.toLowerCase()}`"
                    :aria-label="`Cari ${filter.label.toLowerCase()}`"
                    @keydown.stop
                />
            </div>

            <div class="max-h-64 overflow-y-auto">
                <DropdownMenuItem v-for="opsi in opsiTersaring" :key="opsi.nilai" class="gap-2" @select.prevent="alihkan(opsi.nilai)">
                    <Checkbox :checked="terpilih.includes(opsi.nilai)" class="pointer-events-none" />
                    <span class="truncate">{{ opsi.label }}</span>
                </DropdownMenuItem>

                <!--
                    Menu yang tiba tiba kosong terbaca sebagai menu yang rusak.
                    Sebutkan bahwa yang habis adalah hasil pencariannya.
                -->
                <p v-if="opsiTersaring.length === 0" class="px-2 py-3 text-center text-sm text-muted-foreground">Tidak ada yang cocok.</p>
            </div>

            <template v-if="terpilih.length">
                <DropdownMenuSeparator />
                <DropdownMenuItem class="justify-center" @select="emit('ubah', [])"> Hapus filter {{ filter.label.toLowerCase() }} </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
