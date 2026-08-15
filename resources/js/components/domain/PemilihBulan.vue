<script setup lang="ts">
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { endOfMonth, endOfWeek, format, parseISO, startOfMonth, startOfWeek } from 'date-fns';
import { id as localeId } from 'date-fns/locale';
import { CalendarDays } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        dari: string;
        sampai: string;
        opsi: string[];
        id?: string;
    }>(),
    { id: 'bulan-eksekutif' },
);

const emit = defineEmits<{ ubah: [dari: string, sampai: string] }>();

/**
 * Periode harian dan mingguan tetap menampilkan bulan berjalan agar pemilih
 * bulan tidak tampak kosong. Rentang datanya tidak diubah; nilai ini hanya
 * menyelaraskan label Select dengan konteks kalender yang sedang dibaca.
 */
const bulanAktif = computed(() => {
    const hariIni = new Date();
    const tanggalHariIni = format(hariIni, 'yyyy-MM-dd');
    const awalMingguIni = format(startOfWeek(hariIni, { weekStartsOn: 1 }), 'yyyy-MM-dd');
    const akhirMingguIni = format(endOfWeek(hariIni, { weekStartsOn: 1 }), 'yyyy-MM-dd');
    const adalahHariIni = props.dari === tanggalHariIni && props.sampai === tanggalHariIni;
    const adalahMingguIni = props.dari === awalMingguIni && props.sampai === akhirMingguIni;

    if (adalahHariIni || adalahMingguIni) {
        return format(hariIni, 'yyyy-MM');
    }

    const awal = parseISO(props.dari);

    if (format(startOfMonth(awal), 'yyyy-MM-dd') !== props.dari || format(endOfMonth(awal), 'yyyy-MM-dd') !== props.sampai) {
        return undefined;
    }

    return format(awal, 'yyyy-MM');
});

const pilihan = computed(() =>
    props.opsi.map((nilai) => ({
        nilai,
        label: format(parseISO(`${nilai}-01`), 'MMMM yyyy', { locale: localeId }),
    })),
);

function pilih(nilai: unknown) {
    if (typeof nilai !== 'string' || !/^\d{4}-\d{2}$/.test(nilai)) return;

    const awal = parseISO(`${nilai}-01`);

    emit('ubah', format(startOfMonth(awal), 'yyyy-MM-dd'), format(endOfMonth(awal), 'yyyy-MM-dd'));
}
</script>

<template>
    <Select :model-value="bulanAktif" @update:model-value="pilih">
        <SelectTrigger
            :id="props.id"
            class="h-10 w-full min-w-44 border-0 bg-background shadow-none focus:ring-0 focus:ring-offset-0 focus-visible:ring-2 focus-visible:ring-brand-terang/60 focus-visible:ring-offset-0 sm:w-48"
            aria-label="Pilih bulan pemberitaan"
        >
            <CalendarDays class="mr-2 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <SelectValue placeholder="Pilih bulan" />
        </SelectTrigger>
        <SelectContent>
            <SelectItem v-for="item in pilihan" :key="item.nilai" :value="item.nilai">
                <span class="capitalize">{{ item.label }}</span>
            </SelectItem>
        </SelectContent>
    </Select>
</template>
