<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { OpsiFilter } from '@/types/tabel';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    entitas: { id: number; nama: string; jenis: string; alias: string[] } | null;
    opsiJenis: OpsiFilter[];
}>();

const form = useForm({
    nama: props.entitas?.nama ?? '',
    jenis: props.entitas?.jenis ?? 'orang',
    alias: (props.entitas?.alias ?? []).join('\n'),
});

function simpan() {
    if (props.entitas) {
        form.put(`/admin/entitas/${props.entitas.id}`);
    } else {
        form.post('/admin/entitas');
    }
}
</script>

<template>
    <Head :title="entitas ? `Ubah ${entitas.nama}` : 'Tambah entitas'" />

    <LayoutAdmin
        :judul="entitas ? `Ubah ${entitas.nama}` : 'Tambah entitas'"
        :breadcrumbs="[
            { title: 'Entitas', href: '/admin/entitas' },
            { title: entitas ? 'Ubah' : 'Tambah', href: '#' },
        ]"
    >
        <Card class="max-w-2xl">
            <CardContent class="pt-6">
                <form class="space-y-4" @submit.prevent="simpan">
                    <div class="grid gap-1.5">
                        <Label for="nama">Nama kanonik</Label>
                        <Input id="nama" v-model="form.nama" required autofocus placeholder="Dinas Pekerjaan Umum" />
                        <p class="text-xs text-muted-foreground">
                            Bentuk yang ditampilkan di grafik dan tabel. Variasi penulisannya masuk ke alias, bukan
                            jadi entitas terpisah.
                        </p>
                        <InputError :message="form.errors.nama" />
                        <InputError :message="form.errors.nama_normal" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="jenis">Jenis</Label>
                        <select
                            id="jenis"
                            v-model="form.jenis"
                            class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option v-for="j in props.opsiJenis" :key="j.nilai" :value="j.nilai">{{ j.label }}</option>
                        </select>
                        <InputError :message="form.errors.jenis" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="alias">Alias, satu per baris</Label>
                        <textarea
                            id="alias"
                            v-model="form.alias"
                            rows="5"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                            placeholder="Dinas PU&#10;DPU Kendari&#10;Dinas PUPR"
                        />
                        <p class="text-xs text-muted-foreground">
                            Pencocokan mengabaikan huruf besar dan tanda baca, jadi cukup tulis variasi kata yang
                            benar-benar berbeda. Bentuk di bawah tiga huruf diabaikan karena mencocoki apa saja.
                        </p>
                        <InputError :message="form.errors.alias" />
                    </div>

                    <div class="flex gap-2 pt-2">
                        <Button type="submit" :disabled="form.processing">Simpan</Button>
                        <Button as-child type="button" variant="ghost">
                            <Link href="/admin/entitas">Batal</Link>
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </LayoutAdmin>
</template>
