<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { SumberFeedBaris } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

/**
 * Formulir sumber feed, sebagai dialog di atas halaman detail media.
 *
 * Dulu ini halaman sendiri di `/admin/sumber-feed/create`. Dijadikan dialog
 * karena mengubah satu alamat feed tidak sepadan dengan berpindah halaman,
 * kehilangan posisi gulir, lalu kembali lagi. Daftar feed tetap terlihat di
 * belakangnya, dan itu penting saat admin sedang membandingkan dua sumber.
 *
 * `media_id` tidak ada di formulir ini. Pemiliknya ditentukan alamat rutenya,
 * karena dialog ini hanya bisa dibuka dari halaman detail satu media.
 */
const props = defineProps<{
    /** Null berarti menambah sumber baru. */
    sumber: SumberFeedBaris | null;
    mediaId: number;
}>();

const terbuka = defineModel<boolean>('terbuka', { required: true });

type TipeSumber = 'rss' | 'scrape' | 'scrape_render';

const form = useForm({
    nama: '',
    tipe: 'rss' as TipeSumber,
    url: '',
    selector: { item: '', judul: '', tautan: '' },
    kata_kunci: '',
    interval_menit: 30,
    aktif: true,
});

/**
 * Isi formulir disetel ulang tiap kali dialog dibuka.
 *
 * Komponennya hidup terus di halaman, jadi tanpa ini sisa isian sumber yang
 * tadi disunting akan muncul saat admin menekan "Tambah sumber".
 */
watch(terbuka, (buka) => {
    if (!buka) return;

    form.clearErrors();
    form.defaults({
        nama: props.sumber?.nama ?? '',
        tipe: props.sumber?.tipe ?? 'rss',
        url: props.sumber?.url ?? '',
        selector: {
            item: props.sumber?.selector?.item ?? '',
            judul: props.sumber?.selector?.judul ?? '',
            tautan: props.sumber?.selector?.tautan ?? '',
        },
        kata_kunci: props.sumber?.kata_kunci ?? '',
        interval_menit: props.sumber?.interval_menit ?? 30,
        aktif: props.sumber?.aktif ?? true,
    });
    form.reset();
});

/** Kedua tipe scraping memakai selector yang sama, hanya cara mengambil halamannya yang beda. */
const pakaiSelector = computed(() => form.tipe === 'scrape' || form.tipe === 'scrape_render');

function simpan() {
    form.transform((data) => ({
        ...data,
        // Selector hanya dikirim untuk tipe scrape, supaya baris RSS tidak
        // menyimpan objek kosong yang membingungkan saat dibaca ulang.
        selector: data.tipe === 'rss' ? null : data.selector,
    }));

    const opsi = { preserveScroll: true, onSuccess: () => (terbuka.value = false) };

    if (props.sumber) {
        form.put(`/admin/media/${props.mediaId}/sumber-feed/${props.sumber.id}`, opsi);
    } else {
        form.post(`/admin/media/${props.mediaId}/sumber-feed`, opsi);
    }
}
</script>

<template>
    <Dialog v-model:open="terbuka">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{ sumber ? `Ubah ${sumber.nama}` : 'Tambah sumber feed' }}</DialogTitle>
                <DialogDescription>
                    Alamat yang ditarik berkala untuk media ini. Sebagian besar portal berita cukup satu sumber RSS.
                </DialogDescription>
            </DialogHeader>

            <form id="form-sumber-feed" class="grid gap-4 sm:grid-cols-2" @submit.prevent="simpan">
                <div class="grid gap-1.5 sm:col-span-2">
                    <Label for="sf-nama">Nama sumber</Label>
                    <Input id="sf-nama" v-model="form.nama" required autofocus placeholder="RSS berita utama" />
                    <InputError :message="form.errors.nama" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="sf-tipe">Tipe</Label>
                    <Select id="sf-tipe" v-model="form.tipe">
                        <SelectTrigger><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="rss">RSS</SelectItem>
                            <SelectItem value="scrape">Scraping</SelectItem>
                            <SelectItem value="scrape_render">Scraping (perlu render)</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.tipe" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="sf-interval">Interval (menit)</Label>
                    <Input id="sf-interval" v-model.number="form.interval_menit" type="number" min="5" max="1440" />
                    <InputError :message="form.errors.interval_menit" />
                </div>

                <div class="grid gap-1.5 sm:col-span-2">
                    <Label for="sf-url">URL</Label>
                    <Input id="sf-url" v-model="form.url" type="url" required placeholder="https://contoh.id/feed" />
                    <InputError :message="form.errors.url" />
                </div>

                <template v-if="pakaiSelector">
                    <p v-if="form.tipe === 'scrape_render'" class="text-xs text-muted-foreground sm:col-span-2">
                        Halaman dibuka lewat peramban dulu supaya daftar beritanya yang dirakit JavaScript ikut terbaca. Jauh lebih lambat
                        daripada scraping biasa, jadi pakai hanya kalau tipe Scraping tidak menemukan satu item pun.
                    </p>
                    <div class="grid gap-1.5">
                        <Label for="sf-item">Selector item</Label>
                        <Input id="sf-item" v-model="form.selector.item" placeholder="article.post" />
                        <InputError :message="form.errors['selector.item']" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="sf-judul">Selector judul</Label>
                        <Input id="sf-judul" v-model="form.selector.judul" placeholder="h2.entry-title" />
                        <InputError :message="form.errors['selector.judul']" />
                    </div>
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="sf-tautan">Selector tautan</Label>
                        <Input id="sf-tautan" v-model="form.selector.tautan" placeholder="h2.entry-title a" />
                        <InputError :message="form.errors['selector.tautan']" />
                    </div>
                </template>

                <div class="grid gap-1.5 sm:col-span-2">
                    <Label for="sf-kata-kunci">Kata kunci saringan</Label>
                    <Input id="sf-kata-kunci" v-model="form.kata_kunci" placeholder="Kendari, Sultra, Sulawesi Tenggara" />
                    <p class="text-xs text-muted-foreground">
                        Opsional. Kalau diisi, hanya item yang judul atau ringkasan feed-nya memuat salah satu kata ini yang disimpan. Pisahkan
                        beberapa kata dengan koma. Dipakai untuk feed media nasional yang isinya kebanyakan di luar Kendari.
                    </p>
                    <InputError :message="form.errors.kata_kunci" />
                </div>

                <label class="flex items-center gap-2 text-sm sm:col-span-2">
                    <Checkbox v-model:checked="form.aktif" />
                    Aktif
                </label>
            </form>

            <DialogFooter>
                <Button variant="ghost" type="button" @click="terbuka = false">Batal</Button>
                <Button type="submit" form="form-sumber-feed" :disabled="form.processing">Simpan</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
