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
import { computed } from 'vue';

interface Pengguna {
    id: number;
    name: string;
    email: string;
    jabatan: string | null;
    telepon: string | null;
    aktif: boolean;
    peran: string;
    media_id: number | null;
}

const props = defineProps<{ pengguna: Pengguna | null; daftarMedia: OpsiFilter[] }>();

const form = useForm({
    name: props.pengguna?.name ?? '',
    email: props.pengguna?.email ?? '',
    password: '',
    peran: props.pengguna?.peran ?? 'media',
    media_id: props.pengguna?.media_id ? String(props.pengguna.media_id) : '',
    jabatan: props.pengguna?.jabatan ?? '',
    telepon: props.pengguna?.telepon ?? '',
    aktif: props.pengguna?.aktif ?? true,
});

const butuhMedia = computed(() => form.peran === 'media');

function simpan() {
    form.transform((data) => ({
        ...data,
        media_id: data.peran === 'media' && data.media_id ? Number(data.media_id) : null,
    }));

    if (props.pengguna) {
        form.put(`/admin/pengguna/${props.pengguna.id}`);
    } else {
        form.post('/admin/pengguna');
    }
}
</script>

<template>
    <Head :title="pengguna ? `Ubah ${pengguna.name}` : 'Tambah pengguna'" />

    <LayoutAdmin
        :judul="pengguna ? `Ubah ${pengguna.name}` : 'Tambah pengguna'"
        :breadcrumbs="[
            { title: 'Pengguna', href: '/admin/pengguna' },
            { title: pengguna ? 'Ubah' : 'Tambah', href: '#' },
        ]"
    >
        <Card class="max-w-2xl">
            <CardContent class="pt-6">
                <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="simpan">
                    <div class="grid gap-1.5">
                        <Label for="name">Nama</Label>
                        <Input id="name" v-model="form.name" required autofocus />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="email">Email</Label>
                        <Input id="email" v-model="form.email" type="email" required />
                        <InputError :message="form.errors.email" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="password">Kata sandi</Label>
                        <Input id="password" v-model="form.password" type="password" :required="!pengguna" autocomplete="new-password" />
                        <p class="text-xs text-muted-foreground">
                            {{ pengguna ? 'Kosongkan kalau tidak ingin mengubahnya.' : 'Minimal 8 karakter.' }}
                        </p>
                        <InputError :message="form.errors.password" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="peran">Peran</Label>
                        <Select id="peran" v-model="form.peran">
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="superadmin">Admin Diskominfo</SelectItem>
                                <SelectItem value="walikota">Wali Kota dan Staf Khusus</SelectItem>
                                <SelectItem value="media">Pengelola Media</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.peran" />
                    </div>

                    <div v-if="butuhMedia" class="grid gap-1.5 sm:col-span-2">
                        <Label for="media_id">Media yang ditautkan</Label>
                        <Select id="media_id" v-model="form.media_id">
                            <SelectTrigger><SelectValue placeholder="Pilih media" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="m in daftarMedia" :key="m.nilai" :value="m.nilai">
                                    {{ m.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-muted-foreground">Pengguna hanya akan melihat data media ini. Wajib diisi untuk peran media.</p>
                        <InputError :message="form.errors.media_id" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="jabatan">Jabatan</Label>
                        <Input id="jabatan" v-model="form.jabatan" />
                        <p class="text-xs text-muted-foreground">Tampil di audit log agar jejaknya bermakna.</p>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="telepon">Telepon</Label>
                        <Input id="telepon" v-model="form.telepon" />
                    </div>

                    <label class="flex items-center gap-2 text-sm sm:col-span-2">
                        <Checkbox v-model:checked="form.aktif" />
                        Aktif
                    </label>

                    <div class="flex gap-2 sm:col-span-2">
                        <Button type="submit" :disabled="form.processing">Simpan</Button>
                        <Button variant="ghost" as-child>
                            <Link href="/admin/pengguna">Batal</Link>
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </LayoutAdmin>
</template>
