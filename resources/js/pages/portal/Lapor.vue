<script setup lang="ts">
import KeadaanKosong from '@/components/KeadaanKosong.vue';
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

const props = defineProps<{
    kontrak: { id: number; nomor: string | null; judul: string; tanggal_mulai: string; tanggal_akhir: string }[];
    sudahOtomatis: { id: number; judul: string | null; url: string; tanggal_muat: string | null }[];
}>();

const page = usePage<SharedData & { hasilPeriksa?: { kontrak_id: number; baris: HasilBaris[] } }>();

// Kontrak dipilih otomatis kalau hanya ada satu. Dropdown berisi satu pilihan
// adalah pertanyaan yang jawabannya sudah diketahui sistem.
const kontrakTerpilih = ref<number | null>(props.kontrak[0]?.id ?? null);

const formPeriksa = useForm({ kontrak_id: kontrakTerpilih.value, tautan: '' });

const hasil = computed<HasilBaris[]>(() => page.props.hasilPeriksa?.baris ?? []);
const bisaDikirim = computed(() => hasil.value.filter((h) => h.status === 'berhasil' || h.status === 'gagal'));

/** Isian per baris. Judul dan tanggal terisi dari ekstraksi, kecuali saat gagal. */
const isian = ref<Record<string, { judul: string; tanggal: string; bukti: File | null }>>({});

watch(
    hasil,
    (baris) => {
        isian.value = Object.fromEntries(
            baris.map((b) => [
                b.url_kanonik,
                {
                    judul: b.judul ?? '',
                    tanggal: b.tanggal ?? format(new Date(), 'yyyy-MM-dd'),
                    bukti: null,
                },
            ]),
        );
    },
    { immediate: true },
);

const catatanTerbuka = ref(false);
const formKirim = useForm<Record<string, unknown>>({});

function periksa() {
    formPeriksa.kontrak_id = kontrakTerpilih.value;
    formPeriksa.post('/portal/lapor/periksa', { preserveScroll: true });
}

function kirim() {
    formKirim
        .transform(() => ({
            kontrak_id: kontrakTerpilih.value,
            keterangan: catatan.value || null,
            baris: bisaDikirim.value.map((b) => ({
                url: b.url_kanonik,
                judul: isian.value[b.url_kanonik]?.judul ?? '',
                tanggal: isian.value[b.url_kanonik]?.tanggal ?? '',
                bukti: isian.value[b.url_kanonik]?.bukti ?? null,
            })),
        }))
        // forceFormData: baris yang gagal diekstrak boleh membawa unggahan
        // tangkapan layar, dan berkas tidak bisa dikirim sebagai JSON.
        .post('/portal/lapor', { forceFormData: true });
}

const catatan = ref('');

function pilihBerkas(kunci: string, e: Event) {
    const berkas = (e.target as HTMLInputElement).files?.[0] ?? null;
    if (isian.value[kunci]) isian.value[kunci].bukti = berkas;
}

const gaya: Record<HasilBaris['status'], { badge: string; label: string; kelas: string }> = {
    berhasil: { badge: 'outline', label: 'Terbaca', kelas: '' },
    sudah_tercatat: { badge: 'secondary', label: 'Sudah tercatat', kelas: 'opacity-70' },
    domain_salah: { badge: 'destructive', label: 'Ditolak', kelas: 'border-destructive/40' },
    gagal: { badge: 'secondary', label: 'Perlu diisi manual', kelas: 'border-amber-500/40' },
};

const tanggal = (n: string | null) => (n ? format(new Date(n), 'd MMM yyyy', { locale: id }) : '-');
</script>

<template>
    <Head title="Lapor pemuatan" />

    <LayoutPortal judul="Lapor pemuatan" :breadcrumbs="[{ title: 'Lapor pemuatan', href: '/portal/lapor' }]">
        <!-- F-48. Ditaruh sebelum form, bukan sesudah: fungsinya mengurangi
             laporan ganda, dan itu hanya bekerja kalau dibaca lebih dulu. -->
        <Card v-if="props.sudahOtomatis.length">
            <CardHeader class="pb-2">
                <CardTitle class="flex items-center gap-2 text-sm font-medium">
                    <CheckCircle2 class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                    Berita berikut sudah tercatat otomatis, tidak perlu dilaporkan lagi
                </CardTitle>
            </CardHeader>
            <CardContent class="p-0">
                <ul class="max-h-56 divide-y overflow-y-auto">
                    <li v-for="p in props.sudahOtomatis" :key="p.id" class="flex items-baseline gap-3 px-4 py-2">
                        <span class="shrink-0 text-xs text-muted-foreground">{{ tanggal(p.tanggal_muat) }}</span>
                        <a :href="p.url" target="_blank" rel="noopener noreferrer" class="truncate text-sm hover:underline">
                            {{ p.judul ?? p.url }}
                        </a>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <KeadaanKosong
            v-if="!props.kontrak.length"
            judul="Belum ada kontrak aktif"
            keterangan="Pelaporan pemuatan menempel pada kontrak. Hubungi Diskominfo kalau kontrak Anda sudah berjalan tapi belum tercatat di sistem."
        />

        <template v-else>
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm font-medium">Tempel tautan berita</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div v-if="props.kontrak.length > 1" class="space-y-1.5">
                        <Label for="kontrak">Kontrak tujuan</Label>
                        <select
                            id="kontrak"
                            v-model="kontrakTerpilih"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option v-for="k in props.kontrak" :key="k.id" :value="k.id">
                                {{ k.judul }} ({{ tanggal(k.tanggal_mulai) }} - {{ tanggal(k.tanggal_akhir) }})
                            </option>
                        </select>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="tautan">Tempel tautan berita, satu per baris</Label>
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
                            Judul dan tanggal dibaca sistem dari halamannya. Anda tidak perlu mengetik apa pun kecuali
                            halamannya gagal dibaca.
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
                    <div
                        v-for="b in hasil"
                        :key="b.url_kanonik"
                        class="space-y-2 rounded-md border p-3"
                        :class="gaya[b.status].kelas"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <p class="min-w-0 break-all text-xs text-muted-foreground">{{ b.url }}</p>
                            <Badge :variant="gaya[b.status].badge" class="shrink-0">{{ gaya[b.status].label }}</Badge>
                        </div>

                        <p v-if="b.pesan" class="flex items-start gap-1.5 text-xs" :class="b.status === 'domain_salah' ? 'text-destructive' : 'text-muted-foreground'">
                            <component
                                :is="b.status === 'domain_salah' ? AlertTriangle : Info"
                                class="mt-0.5 h-3 w-3 shrink-0"
                                aria-hidden="true"
                            />
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
                                <Label :for="`tanggal-${b.url_kanonik}`" class="text-xs">Tanggal muat</Label>
                                <Input :id="`tanggal-${b.url_kanonik}`" v-model="isian[b.url_kanonik].tanggal" type="date" class="h-8" />
                            </div>
                            <div class="space-y-1">
                                <Label :for="`bukti-${b.url_kanonik}`" class="text-xs">Tangkapan layar (opsional)</Label>
                                <Input
                                    :id="`bukti-${b.url_kanonik}`"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    class="h-8 py-1"
                                    @change="pilihBerkas(b.url_kanonik, $event)"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="space-y-2 border-t pt-3">
                        <button
                            v-if="!catatanTerbuka"
                            type="button"
                            class="text-xs text-muted-foreground underline"
                            @click="catatanTerbuka = true"
                        >
                            Tambah catatan
                        </button>
                        <div v-else class="space-y-1">
                            <Label for="catatan" class="text-xs">Catatan untuk admin (opsional)</Label>
                            <Input id="catatan" v-model="catatan" class="h-8" />
                        </div>

                        <p v-if="formKirim.errors.baris" class="text-xs text-destructive">{{ formKirim.errors.baris }}</p>

                        <Button :disabled="formKirim.processing || !bisaDikirim.length" @click="kirim">
                            {{ formKirim.processing ? 'Mengirim...' : `Kirim semua (${bisaDikirim.length})` }}
                        </Button>
                        <p class="text-xs text-muted-foreground">
                            Tautan yang sudah tercatat atau ditolak tidak ikut terkirim.
                        </p>
                    </div>
                </CardContent>
            </Card>
        </template>
    </LayoutPortal>
</template>
