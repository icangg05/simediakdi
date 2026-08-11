<script setup lang="ts">
import BadgeTahapPortal from '@/components/domain/BadgeTahapPortal.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import LayoutPortal from '@/layouts/LayoutPortal.vue';
import type { SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { AlertTriangle, CheckCircle2, Info, Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface HasilBaris {
    url: string;
    url_kanonik: string;
    status: 'berhasil' | 'sudah_tercatat' | 'domain_salah' | 'gagal';
    judul: string | null;
    tanggal: string | null;
    pesan: string | null;
}

interface Kiriman {
    id: number;
    url: string;
    judul: string | null;
    tanggal: string | null;
    status: 'tampil' | 'di_luar_pantauan' | 'diproses' | 'gagal';
}

const props = defineProps<{
    sudahOtomatis: { id: number; judul: string | null; url: string; tanggal: string | null; ditambahkan_sendiri: boolean }[];
    kiriman: Kiriman[];
}>();

const page = usePage<SharedData & { hasilPeriksa?: { baris: HasilBaris[] } }>();

const formPeriksa = useForm({ tautan: '' });

const hasil = computed<HasilBaris[]>(() => page.props.hasilPeriksa?.baris ?? []);
const bisaDikirim = computed(() => hasil.value.filter((h) => h.status === 'berhasil' || h.status === 'gagal'));

/** Isian per baris. Judul dan tanggal terisi dari ekstraksi, kecuali saat gagal. */
const isian = ref<Record<string, { judul: string; tanggal: string }>>({});

watch(
    hasil,
    (baris) => {
        isian.value = Object.fromEntries(
            baris.map((b) => [
                b.url_kanonik,
                {
                    judul: b.judul ?? '',
                    tanggal: b.tanggal ?? format(new Date(), 'yyyy-MM-dd'),
                },
            ]),
        );
    },
    { immediate: true },
);

const formKirim = useForm<Record<string, unknown>>({});

function periksa() {
    formPeriksa.post('/portal/lapor/periksa', { preserveScroll: true });
}

function kirim() {
    formKirim
        .transform(() => ({
            baris: bisaDikirim.value.map((b) => ({
                url: b.url_kanonik,
                judul: isian.value[b.url_kanonik]?.judul ?? '',
                tanggal: isian.value[b.url_kanonik]?.tanggal ?? '',
            })),
        }))
        .post('/portal/lapor');
}

const gaya: Record<HasilBaris['status'], { badge: string; label: string; kelas: string }> = {
    berhasil: { badge: 'outline', label: 'Terbaca', kelas: '' },
    sudah_tercatat: { badge: 'secondary', label: 'Sudah ada', kelas: 'opacity-70' },
    domain_salah: { badge: 'destructive', label: 'Ditolak', kelas: 'border-destructive/40' },
    gagal: { badge: 'secondary', label: 'Perlu diisi manual', kelas: 'border-amber-500/40' },
};

// Peta tahap kiriman tidak lagi ada di sini. Ia pindah ke
// components/domain/BadgeTahapPortal.vue, karena beranda portal kini
// menampilkan tahap yang sama dan dua salinan berarti dua layar yang cepat atau
// lambat akan menyebut hal berbeda untuk berita yang sama.

const tanggal = (n: string | null) => (n ? format(new Date(n), 'd MMM yyyy', { locale: id }) : '-');
</script>

<template>
    <Head title="Tambah berita" />

    <LayoutPortal
        judul="Tambah berita"
        :breadcrumbs="[
            { title: 'Portal media', href: '/portal' },
            { title: 'Tambah berita', href: '/portal/lapor' },
        ]"
    >
        <p class="text-sm text-muted-foreground">
            Periksa dulu daftar di bawah. Berita yang sudah ada di situ tidak perlu ditambahkan, karena sistem sudah menemukannya sendiri.
        </p>

        <!-- F-48. Ditaruh sebelum form, bukan sesudah: fungsinya mengurangi
             kiriman ganda, dan itu hanya bekerja kalau dibaca lebih dulu. -->
        <Card v-if="props.sudahOtomatis.length">
            <CardHeader class="pb-2">
                <CardTitle class="flex items-center gap-2 text-sm font-medium">
                    <CheckCircle2 class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                    Sudah terpantau sistem (30 hari terakhir)
                </CardTitle>
            </CardHeader>
            <CardContent class="p-0">
                <ul class="max-h-56 divide-y overflow-y-auto">
                    <li v-for="a in props.sudahOtomatis" :key="a.id" class="flex items-baseline gap-3 px-4 py-2">
                        <span class="shrink-0 text-xs text-muted-foreground">{{ tanggal(a.tanggal) }}</span>
                        <a :href="a.url" target="_blank" rel="noopener noreferrer" class="truncate text-sm hover:underline">
                            {{ a.judul ?? a.url }}
                        </a>
                        <!-- Penanda asal baris, bukan penilaian atas isinya.
                             Daftar ini bercampur, dan tanpa penanda media
                             membacanya sebagai bukti crawler menemukan semuanya
                             sendiri. -->
                        <Badge :variant="a.ditambahkan_sendiri ? 'outline' : 'secondary'" class="ml-auto shrink-0 font-normal">
                            {{ a.ditambahkan_sendiri ? 'Anda tambahkan' : 'Otomatis' }}
                        </Badge>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">Tempel tautan berita yang belum terpantau</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <div class="space-y-1.5">
                    <Label for="tautan">Satu tautan per baris</Label>
                    <textarea
                        id="tautan"
                        v-model="formPeriksa.tautan"
                        rows="5"
                        placeholder="https://contoh.id/berita-pertama&#10;https://contoh.id/berita-kedua"
                        class="w-full rounded-md border border-input bg-background p-3 font-mono text-xs"
                    />
                    <p v-if="formPeriksa.errors.tautan" class="text-xs text-destructive">
                        {{ formPeriksa.errors.tautan }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Judul dan tanggal dibaca sistem dari halamannya. Anda tidak perlu mengetik apa pun kecuali halamannya gagal dibaca.
                    </p>
                </div>

                <Button size="sm" :disabled="formPeriksa.processing || !formPeriksa.tautan.trim()" @click="periksa">
                    <Search class="mr-1.5 h-4 w-4" />
                    {{ formPeriksa.processing ? 'Memeriksa...' : 'Periksa' }}
                </Button>
            </CardContent>
        </Card>

        <Card v-if="hasil.length">
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">Hasil pemeriksaan</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <div v-for="b in hasil" :key="b.url_kanonik" class="space-y-2 rounded-md border p-3" :class="gaya[b.status].kelas">
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 break-all text-xs text-muted-foreground">{{ b.url }}</p>
                        <Badge :variant="gaya[b.status].badge" class="shrink-0">{{ gaya[b.status].label }}</Badge>
                    </div>

                    <p
                        v-if="b.pesan"
                        class="flex items-start gap-1.5 text-xs"
                        :class="b.status === 'domain_salah' ? 'text-destructive' : 'text-muted-foreground'"
                    >
                        <component :is="b.status === 'domain_salah' ? AlertTriangle : Info" class="mt-0.5 h-3 w-3 shrink-0" aria-hidden="true" />
                        {{ b.pesan }}
                    </p>

                    <!-- Berhasil: judul dan tanggal ditampilkan untuk dicek
                         sekilas, tidak untuk diketik ulang. -->
                    <template v-if="b.status === 'berhasil'">
                        <p class="text-sm font-medium">{{ b.judul }}</p>
                        <p class="text-xs text-muted-foreground">Terbit {{ tanggal(b.tanggal) }}</p>
                    </template>

                    <!-- F-51. Hanya kasus ini yang meminta isian tambahan. -->
                    <div v-else-if="b.status === 'gagal' && isian[b.url_kanonik]" class="grid gap-2 sm:grid-cols-2">
                        <div class="space-y-1 sm:col-span-2">
                            <Label :for="`judul-${b.url_kanonik}`" class="text-xs">Judul berita</Label>
                            <Input :id="`judul-${b.url_kanonik}`" v-model="isian[b.url_kanonik].judul" class="h-8" />
                        </div>
                        <div class="space-y-1">
                            <Label :for="`tanggal-${b.url_kanonik}`" class="text-xs">Tanggal terbit</Label>
                            <Input :id="`tanggal-${b.url_kanonik}`" v-model="isian[b.url_kanonik].tanggal" type="date" class="h-8" />
                        </div>
                    </div>
                </div>

                <div class="space-y-2 border-t pt-3">
                    <p v-if="formKirim.errors.baris" class="text-xs text-destructive">{{ formKirim.errors.baris }}</p>

                    <Button :disabled="formKirim.processing || !bisaDikirim.length" @click="kirim">
                        {{ formKirim.processing ? 'Menambahkan...' : `Tambahkan semua (${bisaDikirim.length})` }}
                    </Button>
                    <p class="text-xs text-muted-foreground">Tautan yang sudah ada atau ditolak tidak ikut ditambahkan.</p>
                </div>
            </CardContent>
        </Card>

        <!-- Perlu ada karena berita kiriman tidak langsung tampil di "Berita
             saya": ia harus diunduh dan dinilai lebih dulu. Tanpa daftar ini
             media menambah berita, tidak melihatnya muncul, lalu menambahnya
             lagi. -->
        <Card v-if="props.kiriman.length">
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">Berita yang Anda tambahkan</CardTitle>
            </CardHeader>
            <CardContent class="p-0">
                <ul class="divide-y">
                    <li v-for="k in props.kiriman" :key="k.id" class="space-y-1 px-4 py-2.5">
                        <div class="flex items-start justify-between gap-3">
                            <a :href="k.url" target="_blank" rel="noopener noreferrer" class="min-w-0 text-sm hover:underline">
                                <span class="line-clamp-2">{{ k.judul ?? k.url }}</span>
                            </a>
                            <BadgeTahapPortal :tahap="k.status" class="mt-0.5 shrink-0" />
                        </div>
                        <p class="text-xs text-muted-foreground">Terbit {{ tanggal(k.tanggal) }}</p>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </LayoutPortal>
</template>
