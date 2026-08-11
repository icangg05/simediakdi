<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface Media {
    id: number;
    nama: string;
    slug: string;
    jenis: string;
    tier: string;
    url_website: string | null;
    domain: string | null;
    kota: string | null;
    provinsi: string | null;
    partner: boolean;
    nama_pic: string | null;
    kontak_pic: string | null;
    catatan: string | null;
    aktif: boolean;
}

const props = defineProps<{ media: Media | null }>();

const form = useForm({
    nama: props.media?.nama ?? '',
    slug: props.media?.slug ?? '',
    jenis: props.media?.jenis ?? 'online',
    tier: props.media?.tier ?? 'lokal',
    url_website: props.media?.url_website ?? '',
    domain: props.media?.domain ?? '',
    kota: props.media?.kota ?? '',
    provinsi: props.media?.provinsi ?? '',
    partner: props.media?.partner ?? false,
    nama_pic: props.media?.nama_pic ?? '',
    kontak_pic: props.media?.kontak_pic ?? '',
    catatan: props.media?.catatan ?? '',
    aktif: props.media?.aktif ?? true,
});

function simpan() {
    if (props.media) {
        form.put(`/admin/media/${props.media.id}`);
    } else {
        form.post('/admin/media');
    }
}
</script>

<template>
    <Head :title="media ? `Ubah ${media.nama}` : 'Tambah media'" />

    <LayoutAdmin
        :judul="media ? `Ubah ${media.nama}` : 'Tambah media'"
        :breadcrumbs="[
            { title: 'Media', href: '/admin/media' },
            { title: media ? 'Ubah' : 'Tambah', href: '#' },
        ]"
    >
        <Card class="max-w-3xl">
            <CardContent class="pt-6">
                <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="simpan">
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="nama">Nama media</Label>
                        <Input id="nama" v-model="form.nama" required autofocus />
                        <InputError :message="form.errors.nama" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="slug">Slug</Label>
                        <Input id="slug" v-model="form.slug" placeholder="Dibuat otomatis dari nama" />
                        <InputError :message="form.errors.slug" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="url_website">URL situs</Label>
                        <Input id="url_website" v-model="form.url_website" type="url" placeholder="https://…" />
                        <InputError :message="form.errors.url_website" />
                    </div>

                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="domain">Domain</Label>
                        <Input id="domain" v-model="form.domain" placeholder="kendaripos.fajar.co.id" />
                        <p class="text-xs text-muted-foreground">
                            Dipakai mencocokkan artikel ke media. Tulis subdomain lengkap kalau medianya menumpang domain induk, supaya artikel dari
                            media lain di domain yang sama tidak ikut tercocokkan.
                        </p>
                        <InputError :message="form.errors.domain" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="jenis">Jenis</Label>
                        <Select id="jenis" v-model="form.jenis">
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="online">Online</SelectItem>
                                <SelectItem value="cetak">Cetak</SelectItem>
                                <SelectItem value="tv">TV</SelectItem>
                                <SelectItem value="radio">Radio</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.jenis" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="tier">Tier</Label>
                        <Select id="tier" v-model="form.tier">
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="nasional">Nasional</SelectItem>
                                <SelectItem value="regional">Regional</SelectItem>
                                <SelectItem value="lokal">Lokal</SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-muted-foreground">Menentukan pembobotan di peringkat media.</p>
                        <InputError :message="form.errors.tier" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="kota">Kota</Label>
                        <Input id="kota" v-model="form.kota" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="provinsi">Provinsi</Label>
                        <Input id="provinsi" v-model="form.provinsi" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="nama_pic">Nama PIC</Label>
                        <Input id="nama_pic" v-model="form.nama_pic" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="kontak_pic">Kontak PIC</Label>
                        <Input id="kontak_pic" v-model="form.kontak_pic" />
                    </div>

                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="catatan">Catatan</Label>
                        <textarea
                            id="catatan"
                            v-model="form.catatan"
                            rows="3"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            placeholder="Jenis situs dan jalur pengambilannya, misalnya: SPA tanpa feed, andalkan portal pelaporan."
                        />
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox v-model:checked="form.partner" />
                        Punya kerja sama dengan Pemkot
                    </label>

                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox v-model:checked="form.aktif" />
                        Aktif
                    </label>

                    <div class="flex gap-2 sm:col-span-2">
                        <Button type="submit" :disabled="form.processing">Simpan</Button>
                        <Button variant="ghost" as-child>
                            <Link href="/admin/media">Batal</Link>
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </LayoutAdmin>
</template>
