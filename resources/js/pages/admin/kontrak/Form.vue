<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { OpsiFilter } from '@/types/tabel';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface Kontrak {
    id: number;
    media_id: number;
    nomor: string | null;
    judul: string;
    jenis: string;
    tanggal_mulai: string;
    tanggal_akhir: string;
    nilai: string | null;
    target_pemuatan: number | null;
    status: string;
    catatan: string | null;
}

const props = defineProps<{ kontrak: Kontrak | null; daftarMedia: OpsiFilter[] }>();

const form = useForm({
    media_id: props.kontrak ? String(props.kontrak.media_id) : '',
    nomor: props.kontrak?.nomor ?? '',
    judul: props.kontrak?.judul ?? '',
    jenis: props.kontrak?.jenis ?? 'publikasi',
    tanggal_mulai: props.kontrak?.tanggal_mulai?.slice(0, 10) ?? '',
    tanggal_akhir: props.kontrak?.tanggal_akhir?.slice(0, 10) ?? '',
    nilai: props.kontrak?.nilai ?? '',
    target_pemuatan: props.kontrak?.target_pemuatan ?? null,
    status: props.kontrak?.status ?? 'draft',
    catatan: props.kontrak?.catatan ?? '',
    berkas: null as File | null,
});

function simpan() {
    if (props.kontrak) {
        // Unggahan berkas butuh POST; _method memberi tahu Laravel ini PUT.
        form.transform((data) => ({ ...data, _method: 'put' })).post(`/admin/kontrak/${props.kontrak.id}`);
    } else {
        form.post('/admin/kontrak');
    }
}
</script>

<template>
    <Head :title="kontrak ? `Ubah ${kontrak.judul}` : 'Tambah kontrak'" />

    <LayoutAdmin
        :judul="kontrak ? `Ubah ${kontrak.judul}` : 'Tambah kontrak'"
        :breadcrumbs="[
            { title: 'Kontrak', href: '/admin/kontrak' },
            { title: kontrak ? 'Ubah' : 'Tambah', href: '#' },
        ]"
    >
        <Card class="max-w-3xl">
            <CardContent class="pt-6">
                <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="simpan">
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="judul">Judul kontrak</Label>
                        <Input id="judul" v-model="form.judul" required autofocus />
                        <InputError :message="form.errors.judul" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="media_id">Media</Label>
                        <Select id="media_id" v-model="form.media_id">
                            <SelectTrigger><SelectValue placeholder="Pilih media" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="m in daftarMedia" :key="m.nilai" :value="m.nilai">
                                    {{ m.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.media_id" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="nomor">Nomor dokumen</Label>
                        <Input id="nomor" v-model="form.nomor" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="jenis">Jenis</Label>
                        <Select id="jenis" v-model="form.jenis">
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="advertorial">Advertorial</SelectItem>
                                <SelectItem value="publikasi">Publikasi</SelectItem>
                                <SelectItem value="banner">Banner</SelectItem>
                                <SelectItem value="lain">Lain</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="status">Status</Label>
                        <Select id="status" v-model="form.status">
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="draft">Draft</SelectItem>
                                <SelectItem value="aktif">Aktif</SelectItem>
                                <SelectItem value="selesai">Selesai</SelectItem>
                                <SelectItem value="batal">Batal</SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-muted-foreground">
                            Pencocokan pemuatan otomatis hanya berjalan untuk kontrak berstatus aktif.
                        </p>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="tanggal_mulai">Tanggal mulai</Label>
                        <Input id="tanggal_mulai" v-model="form.tanggal_mulai" type="date" required />
                        <InputError :message="form.errors.tanggal_mulai" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="tanggal_akhir">Tanggal akhir</Label>
                        <Input id="tanggal_akhir" v-model="form.tanggal_akhir" type="date" required />
                        <InputError :message="form.errors.tanggal_akhir" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="nilai">Nilai kontrak (Rp)</Label>
                        <Input id="nilai" v-model="form.nilai" type="number" min="0" step="1" />
                        <InputError :message="form.errors.nilai" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="target_pemuatan">Target pemuatan</Label>
                        <Input id="target_pemuatan" v-model.number="form.target_pemuatan" type="number" min="1" />
                        <p class="text-xs text-muted-foreground">Jumlah artikel yang dijanjikan sepanjang periode.</p>
                        <InputError :message="form.errors.target_pemuatan" />
                    </div>

                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="berkas">Dokumen kontrak (PDF)</Label>
                        <Input
                            id="berkas"
                            type="file"
                            accept="application/pdf"
                            @change="form.berkas = ($event.target as HTMLInputElement).files?.[0] ?? null"
                        />
                        <p class="text-xs text-muted-foreground">
                            Maksimal 10 MB. Disimpan di luar folder publik dan hanya bisa dibuka lewat aplikasi.
                        </p>
                        <InputError :message="form.errors.berkas" />
                    </div>

                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="catatan">Catatan</Label>
                        <textarea
                            id="catatan"
                            v-model="form.catatan"
                            rows="3"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        />
                    </div>

                    <div class="flex gap-2 sm:col-span-2">
                        <Button type="submit" :disabled="form.processing">Simpan</Button>
                        <Button variant="ghost" as-child>
                            <Link href="/admin/kontrak">Batal</Link>
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </LayoutAdmin>
</template>
