<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { CalendarDays } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Tombol yang membuka sheet dari bawah, bukan dropdown kecil (dokumen 04 C.1).
 * Halaman ini dipakai di ponsel sungguhan, dan dropdown tanggal berukuran kecil
 * sulit disentuh.
 */
const props = defineProps<{ dari: string; sampai: string }>();
const emit = defineEmits<{ ubah: [dari: string, sampai: string] }>();

const terbuka = ref(false);
const dariLokal = ref(props.dari);
const sampaiLokal = ref(props.sampai);

const pintasan = [
    { label: '7 hari terakhir', hari: 6 },
    { label: '14 hari terakhir', hari: 13 },
    { label: '30 hari terakhir', hari: 29 },
    { label: '90 hari terakhir', hari: 89 },
];

function tanggalMundur(hari: number): string {
    const d = new Date();
    d.setDate(d.getDate() - hari);

    return d.toISOString().slice(0, 10);
}

function pakaiPintasan(hari: number) {
    dariLokal.value = tanggalMundur(hari);
    sampaiLokal.value = new Date().toISOString().slice(0, 10);
    terapkan();
}

function terapkan() {
    emit('ubah', dariLokal.value, sampaiLokal.value);
    terbuka.value = false;
}

const ringkas = computed(() => {
    const f = (t: string) => format(new Date(t), 'd MMM yyyy', { locale: id });

    return `${f(props.dari)} - ${f(props.sampai)}`;
});
</script>

<template>
    <Sheet v-model:open="terbuka">
        <SheetTrigger as-child>
            <Button variant="outline" class="gap-2">
                <CalendarDays class="h-4 w-4" aria-hidden="true" />
                <span class="text-sm">{{ ringkas }}</span>
            </Button>
        </SheetTrigger>

        <SheetContent side="bottom" class="space-y-4">
            <SheetHeader>
                <SheetTitle>Rentang tanggal</SheetTitle>
                <SheetDescription>Menurut waktu Kendari (WITA).</SheetDescription>
            </SheetHeader>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="p in pintasan"
                    :key="p.label"
                    variant="secondary"
                    size="sm"
                    @click="pakaiPintasan(p.hari)"
                >
                    {{ p.label }}
                </Button>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="rentang-dari">Dari</Label>
                    <Input id="rentang-dari" v-model="dariLokal" type="date" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="rentang-sampai">Sampai</Label>
                    <Input id="rentang-sampai" v-model="sampaiLokal" type="date" />
                </div>
            </div>

            <Button class="w-full" @click="terapkan">Terapkan</Button>
        </SheetContent>
    </Sheet>
</template>
