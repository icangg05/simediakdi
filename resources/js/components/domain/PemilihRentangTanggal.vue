<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { endOfMonth, endOfWeek, format, startOfMonth, startOfWeek, subDays, subMonths } from 'date-fns';
import { id } from 'date-fns/locale';
import { CalendarDays } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/**
 * Tombol yang membuka sheet dari bawah, bukan dropdown kecil (dokumen 04 C.1).
 * Halaman ini dipakai di ponsel sungguhan, dan dropdown tanggal berukuran kecil
 * sulit disentuh.
 *
 * `inline` menampilkan pintasan periode langsung di header, bukan hanya di
 * dalam sheet. Berpindah antara ringkasan harian, mingguan, bulanan, dan tiga
 * bulanan adalah hal yang paling sering dilakukan di panel eksekutif, dan
 * mengubur pilihannya di balik satu ketukan membuatnya jarang dipakai.
 *
 * `tanpaSheet` membuka kedua kotak tanggal langsung di halaman dan meniadakan
 * sheet sama sekali. Dipakai di halaman yang memang halaman penyaring, tempat
 * rentang tanggal berdiri sejajar dengan pencarian dan filter lain. Menyembunyikan
 * satu penyaring di balik lapisan yang menutupi hasilnya membuat pengguna
 * menutup dan membuka lapisan itu berulang kali hanya untuk melihat akibat
 * pilihannya.
 *
 * `kalender` mengganti pintasan bergulir dengan periode panel eksekutif:
 * Senin-Minggu, bulan kalender penuh, dan tiga bulan yang dimulai pada tanggal
 * 1 dua bulan sebelumnya. Portal media tetap memakai rentang bergulir karena
 * kontraknya memang menyebut "30 hari terakhir".
 *
 * `tanpaPilih` menurunkan rentangnya menjadi keterangan, bukan kendali. Keempat
 * pintasan tetap bisa ditekan, dan tanggal di bawahnya melaporkan rentang yang
 * dihasilkan pintasan itu. Dipakai di halaman ringkasan, tempat pertanyaannya
 * selalu salah satu dari empat periode baku dan tidak pernah rentang khusus.
 * Sebelumnya rentang khusus tersedia di sana lewat sheet, dan itu membuka
 * seluruh permukaan tanggal untuk kebutuhan yang tidak pernah muncul, sementara
 * kotak tanggalnya sendiri adalah satu satunya benda di kop yang bisa
 * mengembalikan halaman dalam keadaan tidak cocok dengan pintasan mana pun.
 *
 * `rentangDiBawah` mempertahankan pemilih rentang khusus, tetapi menaruh
 * tombolnya di bawah pintasan. Dipakai saat lebar kop perlu diringkas tanpa
 * menghilangkan kemampuan memilih tanggal bebas.
 */
const props = defineProps<{
    dari: string;
    sampai: string;
    inline?: boolean;
    tanpaSheet?: boolean;
    tanpaPilih?: boolean;
    rentangDiBawah?: boolean;
    kalender?: boolean;
}>();
const emit = defineEmits<{ ubah: [dari: string, sampai: string] }>();

const terbuka = ref(false);
const dariLokal = ref(props.dari);
const sampaiLokal = ref(props.sampai);

/**
 * Kotak tanggal ikut rentang yang sedang berlaku, bukan hanya rentang saat
 * komponen pertama kali dipasang.
 *
 * Kunjungan Inertia di panel eksekutif memakai `preserveState`, jadi instance
 * komponen ini bertahan dan kedua ref lokalnya tidak ikut berganti saat prop
 * berubah. Dulu tidak terlihat karena isinya hanya muncul di dalam sheet.
 * Begitu kotaknya berdiri di halaman, nilai basi itu langsung terbaca.
 */
watch(
    () => [props.dari, props.sampai] as const,
    ([dari, sampai]) => {
        dariLokal.value = dari;
        sampaiLokal.value = sampai;
    },
);

type Pintasan = {
    kunci: string;
    label: string;
    keterangan: string;
    rentang: (hariIni: Date) => [Date, Date];
};

const PINTASAN_BERGULIR: Pintasan[] = [
    { kunci: 'today', label: 'Hari ini', keterangan: 'Hari ini', rentang: (hariIni) => [hariIni, hariIni] },
    { kunci: '7d', label: '7 hari', keterangan: '7 hari terakhir', rentang: (hariIni) => [subDays(hariIni, 6), hariIni] },
    { kunci: '30d', label: '30 hari', keterangan: '30 hari terakhir', rentang: (hariIni) => [subDays(hariIni, 29), hariIni] },
    { kunci: '90d', label: '90 hari', keterangan: '90 hari terakhir', rentang: (hariIni) => [subDays(hariIni, 89), hariIni] },
];

const PINTASAN_KALENDER: Pintasan[] = [
    { kunci: 'today', label: 'Hari ini', keterangan: 'Hari ini', rentang: (hariIni) => [hariIni, hariIni] },
    {
        kunci: '7d',
        label: 'Minggu ini',
        keterangan: 'Senin sampai Minggu',
        rentang: (hariIni) => [startOfWeek(hariIni, { weekStartsOn: 1 }), endOfWeek(hariIni, { weekStartsOn: 1 })],
    },
    {
        kunci: '30d',
        label: 'Bulan ini',
        keterangan: 'Tanggal 1 sampai akhir bulan',
        rentang: (hariIni) => [startOfMonth(hariIni), endOfMonth(hariIni)],
    },
    {
        kunci: '90d',
        label: '3 bulan',
        keterangan: 'Dari tanggal 1 dua bulan sebelumnya sampai hari ini',
        rentang: (hariIni) => [startOfMonth(subMonths(hariIni, 2)), hariIni],
    },
];

const pintasan = computed(() => (props.kalender ? PINTASAN_KALENDER : PINTASAN_BERGULIR));

/**
 * Tanggal menurut kalender setempat, bukan UTC.
 *
 * `toISOString()` selalu mengembalikan tanggal UTC. Kendari berada di UTC+8,
 * jadi antara pukul 00.00 dan 07.59 WITA hasilnya mundur satu hari dan pintasan
 * "Hari ini" memilih tanggal kemarin setiap pagi.
 */
const tanggalIso = (d: Date) => format(d, 'yyyy-MM-dd');

function rentangIso(pintasan: Pintasan): [string, string] {
    const [dari, sampai] = pintasan.rentang(new Date());

    return [tanggalIso(dari), tanggalIso(sampai)];
}

function pakaiPintasan(pintasan: Pintasan) {
    [dariLokal.value, sampaiLokal.value] = rentangIso(pintasan);
    terapkan();
}

function terapkan() {
    emit('ubah', dariLokal.value, sampaiLokal.value);
    terbuka.value = false;
}

/** Pintasan yang sedang dilihat, supaya pengguna tahu ini rentang yang mana. */
const pintasanAktif = computed(() => {
    return pintasan.value.find((pilihan) => {
        const [dari, sampai] = rentangIso(pilihan);

        return props.dari === dari && props.sampai === sampai;
    })?.kunci;
});

const ringkas = computed(() => {
    const f = (t: string) => format(new Date(t), 'd MMM yyyy', { locale: id });

    return `${f(props.dari)} - ${f(props.sampai)}`;
});
</script>

<template>
    <div
        class="flex gap-1.5"
        :class="inline && (tanpaPilih || rentangDiBawah) ? 'flex-col items-stretch sm:items-end' : 'flex-wrap items-center'"
    >
        <!--
            Alas deretan pintasan memakai `secondary`, bukan `muted`.
            Di mode gelap `muted` bernilai 6,9% sedangkan latar halaman 3,9%,
            selisih yang tidak terlihat mata, sehingga deretan ini kehilangan
            bentuknya dan keempat pintasan mengambang tanpa wadah. `secondary`
            bernilai 92,1% di mode terang dan 14,9% di mode gelap, dua-duanya
            terbaca sebagai bidang tersendiri.
        -->
        <div v-if="inline" class="flex flex-wrap items-center gap-1 rounded-lg bg-secondary p-1">
            <Button
                v-for="p in pintasan"
                :key="p.kunci"
                :variant="p.kunci === pintasanAktif ? 'default' : 'ghost'"
                size="sm"
                class="h-8 px-3 text-sm font-semibold"
                :class="p.kunci === pintasanAktif ? '' : 'text-foreground hover:bg-background'"
                :title="p.keterangan"
                :aria-label="`${p.label}: ${p.keterangan}`"
                @click="pakaiPintasan(p)"
            >
                {{ p.label }}
            </Button>
        </div>

        <!--
            Dua kotak tanggal bawaan peramban, bukan kalender buatan sendiri.
            Di ponsel keduanya memanggil pemilih tanggal milik sistem, yang
            sudah dikenal pengguna dan sudah benar soal zona waktu, format, dan
            navigasi papan ketik.
        -->
        <div v-if="tanpaSheet" class="flex flex-wrap items-center gap-1.5">
            <Label for="rentang-dari" class="text-xs text-muted-foreground">Dari</Label>
            <Input id="rentang-dari" v-model="dariLokal" type="date" :max="sampaiLokal" class="h-8 w-38 text-sm" @change="terapkan" />

            <Label for="rentang-sampai" class="text-xs text-muted-foreground">sampai</Label>
            <Input id="rentang-sampai" v-model="sampaiLokal" type="date" :min="dariLokal" class="h-8 w-38 text-sm" @change="terapkan" />
        </div>

        <!--
            Rentang sebagai keterangan, bukan tombol.

            Bentuknya sengaja tidak meniru tombol di atasnya. Rentang ditempatkan
            pada baris kedua agar deretan kendali utama tidak menjadi terlalu
            panjang. Tepi bergaris pada benda yang tidak bisa ditekan adalah janji
            yang tidak ditepati, dan pengguna akan menekannya sekali sebelum
            menyimpulkan halamannya rusak.

            `aria-live` supaya perubahan rentang terbacakan. Yang menekan
            pintasan lewat pembaca layar tidak melihat tanggal di sebelahnya
            berganti, dan tanpa ini satu satunya umpan balik atas ketukannya
            adalah seluruh halaman yang diam diam berganti isi.
        -->
        <p
            v-else-if="tanpaPilih"
            class="inline-flex h-10 items-center gap-2 rounded-lg bg-secondary px-3 font-semibold text-foreground"
            aria-live="polite"
        >
            <CalendarDays class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <span class="angka text-sm">{{ ringkas }}</span>
        </p>

        <Sheet v-else v-model:open="terbuka">
            <!--
                Tinggi 40 piksel supaya sejajar dengan deretan pintasan di
                sebelahnya, yang tingginya 32 piksel ditambah 4 piksel isi
                di atas dan di bawah. Sebelumnya tombol ini `h-10` bawaan
                sedangkan deretannya 36 piksel, dan selisih 4 piksel itu
                membuat keduanya terlihat tidak sengaja diletakkan sebaris.
            -->
            <SheetTrigger as-child>
                <Button :variant="rentangDiBawah ? 'secondary' : 'outline'" class="h-10 gap-2 font-semibold text-foreground">
                    <CalendarDays class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
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
                        :key="p.kunci"
                        :variant="p.kunci === pintasanAktif ? 'default' : 'secondary'"
                        size="sm"
                        :title="p.keterangan"
                        :aria-label="`${p.label}: ${p.keterangan}`"
                        @click="pakaiPintasan(p)"
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
    </div>
</template>
