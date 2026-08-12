<script setup lang="ts">
import { TransitionRoot } from '@headlessui/vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

import DeleteUser from '@/components/DeleteUser.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem, type SharedData, type User } from '@/types';
import { Check, CircleCheck, Loader2, MailWarning, UserRound } from 'lucide-vue-next';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
    className?: string;
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Profil',
        href: '/settings/profile',
    },
];

const page = usePage<SharedData>();
const user = page.props.auth.user as User;

const form = useForm({
    name: user.name,
    email: user.email,
});

const submit = () => {
    form.patch(route('profile.update'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <SettingsLayout :breadcrumbs="breadcrumbs">
        <Head title="Profil" />

        <Card class="muncul overflow-hidden" style="animation-delay: 80ms">
            <CardContent class="space-y-5 p-5 sm:p-6">
                <div class="flex items-center gap-2">
                    <UserRound class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <h2 class="shrink-0 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Identitas</h2>
                    <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                </div>

                <p class="text-sm text-muted-foreground">
                    Nama ini yang tercatat di audit log setiap kali Anda mengubah data, jadi tulis nama yang dikenali rekan kerja Anda.
                </p>

                <form class="space-y-5" @submit.prevent="submit">
                    <div class="grid gap-1.5">
                        <Label for="name">Nama</Label>
                        <Input id="name" v-model="form.name" required autocomplete="name" placeholder="Nama lengkap" />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="email">Alamat email</Label>
                        <Input id="email" v-model="form.email" type="email" required autocomplete="username" placeholder="nama@instansi.go.id" />
                        <p class="text-xs text-muted-foreground">Dipakai untuk masuk ke sistem. Peran akun hanya bisa diubah admin.</p>
                        <InputError :message="form.errors.email" />
                    </div>

                    <!-- Email yang belum diverifikasi bukan galat, ia langkah
                         yang belum selesai. Kuning, sama dengan seluruh keadaan
                         "menunggu" di panel admin. -->
                    <div
                        v-if="mustVerifyEmail && !user.email_verified_at"
                        class="flex items-start gap-2.5 rounded-lg bg-sentimen-review-lembut p-3 text-sm text-sentimen-review ring-1 ring-inset ring-sentimen-review/25"
                    >
                        <MailWarning class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <div class="min-w-0 space-y-1">
                            <p class="font-medium">Alamat email ini belum diverifikasi.</p>
                            <Link
                                :href="route('verification.send')"
                                method="post"
                                as="button"
                                class="rounded font-medium underline underline-offset-2 hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                            >
                                Kirim ulang tautan verifikasi
                            </Link>
                        </div>
                    </div>

                    <div
                        v-if="status === 'verification-link-sent'"
                        class="flex items-start gap-2.5 rounded-lg bg-sentimen-positif-lembut p-3 text-sm text-sentimen-positif ring-1 ring-inset ring-sentimen-positif/25"
                    >
                        <CircleCheck class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <p>Tautan verifikasi baru sudah dikirim ke alamat email Anda.</p>
                    </div>

                    <div class="flex items-center gap-3 border-t pt-4">
                        <Button type="submit" class="tekan" :disabled="form.processing">
                            <Loader2 v-if="form.processing" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                            Simpan
                        </Button>

                        <!-- Penanda tersimpan memakai hijau sehat dan ikon
                             centang, bukan teks abu. Ia satu-satunya balasan
                             yang diterima pengguna setelah menekan Simpan, dan
                             teks abu di sebelah tombol abu mudah terlewat. -->
                        <TransitionRoot
                            :show="form.recentlySuccessful"
                            enter="transition ease-in-out duration-200"
                            enter-from="opacity-0 translate-y-1"
                            leave="transition ease-in-out duration-200"
                            leave-to="opacity-0"
                        >
                            <p class="inline-flex items-center gap-1.5 text-sm font-medium text-sentimen-positif">
                                <Check class="size-4 shrink-0" aria-hidden="true" />
                                Tersimpan
                            </p>
                        </TransitionRoot>
                    </div>
                </form>
            </CardContent>
        </Card>

        <DeleteUser />
    </SettingsLayout>
</template>
