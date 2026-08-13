<script setup lang="ts">
import KopHalaman from '@/components/domain/KopHalaman.vue';
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
import { Eye, KeyRound, Loader2, Newspaper, Power, ShieldCheck, UserRound } from 'lucide-vue-next';
import { computed } from 'vue';

interface Pengguna {
    id: number;
    name: string;
    username: string;
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
    username: props.pengguna?.username ?? '',
    email: props.pengguna?.email ?? '',
    password: '',
    peran: props.pengguna?.peran ?? 'media',
    media_id: props.pengguna?.media_id ? String(props.pengguna.media_id) : '',
    jabatan: props.pengguna?.jabatan ?? '',
    telepon: props.pengguna?.telepon ?? '',
    aktif: props.pengguna?.aktif ?? true,
});

const butuhMedia = computed(() => form.peran === 'media');

/**
 * Peran dipilih lewat tiga kartu, bukan dropdown.
 *
 * Yang membedakan ketiganya bukan namanya melainkan apa yang boleh
 * dilakukannya, dan itu keterangan yang tidak muat di dalam satu baris
 * dropdown. Peran media juga memunculkan satu kotak isian tambahan di
 * bawahnya, dan sebab akibat itu jauh lebih mudah dibaca kalau pilihannya
 * terlihat semua sekaligus.
 *
 * Warnanya sama dengan warna peran di daftar pengguna, jadi kartu yang dipilih
 * di sini berona sama dengan lencana yang nanti muncul di tabel.
 */
const PERAN = [
    {
        nilai: 'superadmin',
        label: 'Admin Diskominfo',
        keterangan: 'Boleh mengubah seluruh data dan pengaturan sistem.',
        ikon: ShieldCheck,
        kelas: 'border-brand/50 bg-brand-lembut',
        ikonKelas: 'text-brand dark:text-white',
    },
    {
        nilai: 'walikota',
        label: 'Wali Kota dan Staf',
        keterangan: 'Hanya membaca dashboard eksekutif, tidak bisa menulis apa pun.',
        ikon: Eye,
        kelas: 'border-aksen-biru/50 bg-aksen-biru/5',
        ikonKelas: 'text-aksen-biru',
    },
    {
        nilai: 'media',
        label: 'Pengelola Media',
        keterangan: 'Hanya melihat data medianya sendiri dan melaporkan pemuatan.',
        ikon: Newspaper,
        kelas: 'border-aksen-toska/50 bg-aksen-toska/5',
        ikonKelas: 'text-aksen-toska',
    },
] as const;

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
        :breadcrumbs="[
            { title: 'Pengguna', href: '/admin/pengguna' },
            { title: pengguna ? 'Ubah' : 'Tambah', href: '#' },
        ]"
    >
        <KopHalaman
            :judul="pengguna ? `Ubah ${pengguna.name}` : 'Tambah pengguna'"
            keterangan="Peran menentukan apa yang bisa dilihat dan diubah pengguna ini. Setiap perubahan di halaman ini tercatat di audit log."
        />

        <Card class="muncul max-w-2xl overflow-hidden" style="animation-delay: 80ms">
            <CardContent class="p-5 sm:p-6">
                <form class="space-y-6" @submit.prevent="simpan">
                    <section class="space-y-3">
                        <div class="flex items-center gap-2">
                            <UserRound class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <h2 class="shrink-0 text-xs font-semibold tracking-wide text-muted-foreground uppercase">Identitas</h2>
                            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
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
                                <Label for="jabatan">Jabatan</Label>
                                <Input id="jabatan" v-model="form.jabatan" />
                                <p class="text-xs text-muted-foreground">Tampil di audit log agar jejaknya bermakna.</p>
                            </div>

                            <div class="grid gap-1.5">
                                <Label for="telepon">Telepon</Label>
                                <Input id="telepon" v-model="form.telepon" />
                            </div>
                        </div>
                    </section>

                    <section class="space-y-3">
                        <div class="flex items-center gap-2">
                            <KeyRound class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <h2 class="shrink-0 text-xs font-semibold tracking-wide text-muted-foreground uppercase">Akses masuk</h2>
                            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                        </div>

                        <!--
                            Username duduk di bagian Akses masuk, bukan di
                            Identitas bersama nama dan email.

                            Ia bukan sebutan orangnya, ia kredensial yang
                            diketik di layar masuk, dan pasangannya adalah kata
                            sandi di sebelahnya. Menaruhnya di Identitas akan
                            membuatnya terbaca sebagai nama panggilan yang boleh
                            diubah kapan saja tanpa akibat, padahal mengubahnya
                            mengubah cara pemiliknya masuk mulai saat itu juga.
                        -->
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="username">Username</Label>
                                <Input
                                    id="username"
                                    v-model="form.username"
                                    :required="!!pengguna"
                                    autocomplete="username"
                                    autocapitalize="none"
                                    spellcheck="false"
                                    :placeholder="pengguna ? undefined : 'Dibuat otomatis kalau dikosongkan'"
                                    class="font-mono text-sm"
                                />
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        pengguna
                                            ? 'Dipakai untuk masuk. Mengubahnya membuat username lama tidak berlaku lagi seketika.'
                                            : 'Kosongkan saja, sistem membentuknya dari bagian nama pada email.'
                                    }}
                                </p>
                                <InputError :message="form.errors.username" />
                            </div>

                            <div class="grid gap-1.5">
                                <Label for="password">Kata sandi</Label>
                                <Input id="password" v-model="form.password" type="password" :required="!pengguna" autocomplete="new-password" />
                                <p class="text-xs text-muted-foreground">
                                    {{ pengguna ? 'Kosongkan kalau tidak ingin mengubahnya.' : 'Minimal 8 karakter.' }}
                                </p>
                                <InputError :message="form.errors.password" />
                            </div>
                        </div>
                    </section>

                    <section class="space-y-3">
                        <div class="flex items-center gap-2">
                            <ShieldCheck class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <h2 class="shrink-0 text-xs font-semibold tracking-wide text-muted-foreground uppercase">Peran dan cakupan</h2>
                            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                        </div>

                        <div role="radiogroup" aria-label="Peran pengguna" class="grid gap-2 sm:grid-cols-3">
                            <button
                                v-for="p in PERAN"
                                :key="p.nilai"
                                type="button"
                                role="radio"
                                :aria-checked="form.peran === p.nilai"
                                class="tekan flex flex-col gap-1.5 rounded-lg border p-3 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
                                :class="form.peran === p.nilai ? p.kelas : 'hover:bg-muted/50'"
                                @click="form.peran = p.nilai"
                            >
                                <component
                                    :is="p.ikon"
                                    class="size-4 shrink-0"
                                    :class="form.peran === p.nilai ? p.ikonKelas : 'text-muted-foreground'"
                                    aria-hidden="true"
                                />
                                <span class="text-sm font-medium">{{ p.label }}</span>
                                <span class="text-xs text-muted-foreground">{{ p.keterangan }}</span>
                            </button>
                        </div>
                        <InputError :message="form.errors.peran" />

                        <div v-if="butuhMedia" class="grid gap-1.5 rounded-lg bg-aksen-toska/5 p-3">
                            <Label for="media_id">Media yang ditautkan</Label>
                            <Select id="media_id" v-model="form.media_id">
                                <SelectTrigger class="bg-background"><SelectValue placeholder="Pilih media" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="m in daftarMedia" :key="m.nilai" :value="m.nilai">
                                        {{ m.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p class="text-xs text-muted-foreground">Pengguna hanya akan melihat data media ini. Wajib diisi untuk peran media.</p>
                            <InputError :message="form.errors.media_id" />
                        </div>
                    </section>

                    <section class="space-y-3">
                        <div class="flex items-center gap-2">
                            <Power class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <h2 class="shrink-0 text-xs font-semibold tracking-wide text-muted-foreground uppercase">Saklar</h2>
                            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                        </div>

                        <label
                            class="tekan flex cursor-pointer items-center gap-2.5 rounded-lg border p-3 text-sm transition-colors"
                            :class="form.aktif ? 'border-sentimen-positif/40 bg-sentimen-positif-lembut/50' : 'hover:bg-muted/50'"
                        >
                            <Checkbox v-model:checked="form.aktif" />
                            <Power
                                class="size-4 shrink-0"
                                :class="form.aktif ? 'text-sentimen-positif' : 'text-muted-foreground'"
                                aria-hidden="true"
                            />
                            <span class="min-w-0">Aktif, pengguna ini bisa masuk</span>
                        </label>
                    </section>

                    <div class="flex flex-wrap gap-2 border-t pt-4">
                        <Button type="submit" class="tekan" :disabled="form.processing">
                            <Loader2 v-if="form.processing" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                            Simpan
                        </Button>
                        <Button variant="ghost" as-child>
                            <Link href="/admin/pengguna">Batal</Link>
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </LayoutAdmin>
</template>
