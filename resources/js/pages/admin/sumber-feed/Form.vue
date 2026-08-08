<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { OpsiFilter } from '@/types/tabel';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface SumberFeed {
    id: number;
    media_id: number | null;
    nama: string;
    tipe: 'rss' | 'scrape';
    url: string;
    selector: { item?: string; judul?: string; tautan?: string } | null;
    kata_kunci: string | null;
    interval_menit: number;
    aktif: boolean;
}

const props = defineProps<{ sumberFeed: SumberFeed | null; daftarMedia: OpsiFilter[] }>();

const form = useForm({
    media_id: props.sumberFeed?.media_id ? String(props.sumberFeed.media_id) : '',
    nama: props.sumberFeed?.nama ?? '',
    tipe: props.sumberFeed?.tipe ?? 'rss',
    url: props.sumberFeed?.url ?? '',
    selector: {
        item: props.sumberFeed?.selector?.item ?? '',
        judul: props.sumberFeed?.selector?.judul ?? '',
        tautan: props.sumberFeed?.selector?.tautan ?? '',
    },
    kata_kunci: props.sumberFeed?.kata_kunci ?? '',
    interval_menit: props.sumberFeed?.interval_menit ?? 30,
    aktif: props.sumberFeed?.aktif ?? true,
});

function simpan() {
    form.transform((data) => ({
        ...data,
        media_id: data.media_id === '' ? null : Number(data.media_id),
        // Kirim selector hanya untuk tipe scrape, supaya baris RSS tidak
        // menyimpan objek kosong yang membingungkan saat dibaca ulang.
        selector: data.tipe === 'scrape' ? data.selector : null,
    }));

    if (props.sumberFeed) {
        form.put(`/admin/sumber-feed/${props.sumberFeed.id}`);
    } else {
        form.post('/admin/sumber-feed');
    }
}
</script>

<template>
    <Head :title="sumberFeed ? `Ubah ${sumberFeed.nama}` : 'Tambah sumber feed'" />

    <LayoutAdmin
        :judul="sumberFeed ? `Ubah ${sumberFeed.nama}` : 'Tambah sumber feed'"
        :breadcrumbs="[
            { title: 'Sumber feed', href: '/admin/sumber-feed' },
            { title: sumberFeed ? 'Ubah' : 'Tambah', href: '#' },
        ]"
    >
        <Card class="max-w-3xl">
            <CardContent class="pt-6">
                <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="simpan">
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="nama">Nama sumber</Label>
                        <Input id="nama" v-model="form.nama" required autofocus placeholder="RSS berita utama" />
                        <InputError :message="form.errors.nama" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="tipe">Tipe</Label>
                        <Select id="tipe" v-model="form.tipe">
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="rss">RSS</SelectItem>
                                <SelectItem value="scrape">Scraping</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.tipe" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="media_id">Media</Label>
                        <Select id="media_id" v-model="form.media_id">
                            <SelectTrigger><SelectValue placeholder="Lintas media" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="m in daftarMedia" :key="m.nilai" :value="m.nilai">
                                    {{ m.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-muted-foreground">Kosongkan untuk sumber yang menangkap banyak media sekaligus.</p>
                        <InputError :message="form.errors.media_id" />
                    </div>

                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="url">URL</Label>
                        <Input id="url" v-model="form.url" type="url" required placeholder="https://contoh.id/feed" />
                        <InputError :message="form.errors.url" />
                    </div>

                    <template v-if="form.tipe === 'scrape'">
                        <div class="grid gap-1.5">
                            <Label for="sel-item">Selector item</Label>
                            <Input id="sel-item" v-model="form.selector.item" placeholder="article.post" />
                            <InputError :message="form.errors['selector.item']" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="sel-judul">Selector judul</Label>
                            <Input id="sel-judul" v-model="form.selector.judul" placeholder="h2.entry-title" />
                            <InputError :message="form.errors['selector.judul']" />
                        </div>
                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label for="sel-tautan">Selector tautan</Label>
                            <Input id="sel-tautan" v-model="form.selector.tautan" placeholder="h2.entry-title a" />
                            <InputError :message="form.errors['selector.tautan']" />
                        </div>
                    </template>

                    <!--
                        Berlaku untuk semua tipe, bukan hanya sebagian.
                        Sebelumnya kolom ini cuma muncul untuk Google News,
                        sementara sumber Tempo dan Detik justru bergantung
                        padanya sebagai saringan. Menyunting keduanya lewat form
                        ini menghapus saringannya tanpa peringatan, dan feed
                        nasional utuh langsung membanjiri tabel artikel.
                    -->
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="kata_kunci">Kata kunci saringan</Label>
                        <Input id="kata_kunci" v-model="form.kata_kunci" placeholder="Kendari" />
                        <p class="text-xs text-muted-foreground">
                            Opsional. Kalau diisi, hanya item yang judul atau ringkasannya memuat kata ini yang disimpan. Dipakai untuk feed media
                            nasional yang isinya kebanyakan di luar Kendari.
                        </p>
                        <InputError :message="form.errors.kata_kunci" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="interval_menit">Interval (menit)</Label>
                        <Input id="interval_menit" v-model.number="form.interval_menit" type="number" min="5" max="1440" />
                        <InputError :message="form.errors.interval_menit" />
                    </div>

                    <label class="flex items-end gap-2 pb-2 text-sm">
                        <Checkbox v-model="form.aktif" />
                        Aktif
                    </label>

                    <div class="flex gap-2 sm:col-span-2">
                        <Button type="submit" :disabled="form.processing">Simpan</Button>
                        <Button variant="ghost" as-child>
                            <Link href="/admin/sumber-feed">Batal</Link>
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </LayoutAdmin>
</template>
