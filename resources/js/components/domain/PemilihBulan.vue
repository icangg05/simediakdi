<script setup lang="ts">
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { endOfMonth, format, parseISO, startOfMonth } from 'date-fns';
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
 * Bulan yang sedang dibaca, selama rentangnya tidak keluar dari satu bulan.
 *
 * Syaratnya bukan lagi tanggal 1 sampai akhir bulan. Rentang 1 sampai 17 Mei
 * masih menjawab pertanyaan tentang Mei, dan memaksa Select kosong di situ
 * membuat pembaca melihat "Pilih bulan" padahal bulannya sudah jelas. Hari ini
 * dan pekan berjalan ikut tercakup tanpa perlu diperiksa sendiri, karena
 * keduanya jatuh di dalam satu bulan.
 *
 * Rentang yang melintasi dua bulan atau lebih tidak punya jawaban di sini, dan
 * `undefined` memadamkan seluruh komponen. Menawarkan satu nama bulan untuk
 * rentang tiga bulan adalah keterangan yang salah, bukan keterangan yang
 * kurang.
 *
 * Bulannya juga harus ada di daftar pilihan. Nilai di luar daftar membuat
 * Select menampilkan pemicu tanpa isi, persis keadaan yang dihindari di sini.
 * Ini bukan syarat yang mengganggu: `bulanTersedia()` selalu menyertakan bulan
 * rentang yang sedang dibuka.
 */
const bulanAktif = computed(() => {
    const awal = parseISO(props.dari);
    const bulan = format(awal, 'yyyy-MM');

    if (format(parseISO(props.sampai), 'yyyy-MM') !== bulan) {
        return undefined;
    }

    return props.opsi.includes(bulan) ? bulan : undefined;
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
    <Select v-if="bulanAktif" :model-value="bulanAktif" @update:model-value="pilih">
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
