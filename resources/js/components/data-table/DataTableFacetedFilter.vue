<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import type { FilterDefinisi } from '@/types/tabel';
import { Filter, PlusCircle } from 'lucide-vue-next';

const props = defineProps<{
    filter: FilterDefinisi;
    terpilih: string[];
}>();

const emit = defineEmits<{ ubah: [nilai: string[]] }>();

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
            <div class="max-h-64 overflow-y-auto">
                <DropdownMenuItem v-for="opsi in filter.opsi" :key="opsi.nilai" class="gap-2" @select.prevent="alihkan(opsi.nilai)">
                    <Checkbox :checked="terpilih.includes(opsi.nilai)" class="pointer-events-none" />
                    <span class="truncate">{{ opsi.label }}</span>
                </DropdownMenuItem>
            </div>

            <template v-if="terpilih.length">
                <DropdownMenuSeparator />
                <DropdownMenuItem class="justify-center" @select="emit('ubah', [])"> Hapus filter {{ filter.label.toLowerCase() }} </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
