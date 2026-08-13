<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { TransitionRoot } from '@headlessui/vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type BreadcrumbItem } from '@/types';
import { Check, Circle, KeyRound, Loader2, ShieldCheck } from 'lucide-vue-next';

interface Props {
    className?: string;
}

defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Kata sandi',
        href: '/settings/password',
    },
];

const passwordInput = ref<HTMLInputElement>();
const currentPasswordInput = ref<HTMLInputElement>();

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

/**
 * Dua syarat, dan hanya yang benar-benar ditegakkan server.
 *
 * Sengaja bukan penunjuk kekuatan sandi berskala lemah sampai kuat. Penunjuk
 * semacam itu menebak, dan tebakan yang tampil sebagai bilah berwarna akan
 * berbeda dari yang diputuskan server: sandi yang dinilai "kuat" di layar tetap
 * bisa ditolak, dan yang dinilai "lemah" tetap bisa diterima. Yang ditampilkan
 * di sini persis aturan yang dijalankan validasi, jadi centangnya tidak pernah
 * berbohong.
 *
 * Penegakan yang sebenarnya tetap di server. Daftar ini hanya menghemat satu
 * putaran kirim dan tolak.
 */
const syarat = computed(() => [
    { teks: 'Sekurangnya 8 karakter', lolos: form.password.length >= 8 },
    { teks: 'Konfirmasi sama dengan sandi baru', lolos: form.password.length > 0 && form.password === form.password_confirmation },
]);

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: (errors: Record<string, string>) => {
            if (errors.password) {
                form.reset('password', 'password_confirmation');
                if (passwordInput.value instanceof HTMLInputElement) {
                    passwordInput.value.focus();
                }
            }

            if (errors.current_password) {
                form.reset('current_password');
                if (currentPasswordInput.value instanceof HTMLInputElement) {
                    currentPasswordInput.value.focus();
                }
            }
        },
    });
};
</script>

<template>
    <SettingsLayout :breadcrumbs="breadcrumbItems">
        <Head title="Kata sandi" />

        <Card class="muncul overflow-hidden" style="animation-delay: 80ms">
            <CardContent class="space-y-5 p-5 sm:p-6">
                <div class="flex items-center gap-2">
                    <KeyRound class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <h2 class="shrink-0 text-xs font-semibold tracking-wide text-muted-foreground uppercase">Ganti kata sandi</h2>
                    <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                </div>

                <p class="text-sm text-muted-foreground">
                    Pakai sandi yang panjang dan tidak dipakai di layanan lain. Akun peran admin dan wali kota juga dilindungi 2FA.
                </p>

                <form class="space-y-5" @submit.prevent="updatePassword">
                    <div class="grid gap-1.5">
                        <Label for="current_password">Kata sandi sekarang</Label>
                        <Input
                            id="current_password"
                            ref="currentPasswordInput"
                            v-model="form.current_password"
                            type="password"
                            autocomplete="current-password"
                            placeholder="Kata sandi yang sedang berlaku"
                        />
                        <InputError :message="form.errors.current_password" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="password">Kata sandi baru</Label>
                        <Input
                            id="password"
                            ref="passwordInput"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                            placeholder="Kata sandi baru"
                        />
                        <InputError :message="form.errors.password" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="password_confirmation">Ulangi kata sandi baru</Label>
                        <Input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            placeholder="Ketik ulang kata sandi baru"
                        />
                        <InputError :message="form.errors.password_confirmation" />
                    </div>

                    <!-- Daftar syarat yang mencentang dirinya sendiri saat
                         diketik. Ikonnya berubah dari lingkaran kosong menjadi
                         centang, jadi kemajuannya terbaca tanpa bergantung pada
                         warna saja. -->
                    <ul class="space-y-1.5 rounded-lg bg-muted/50 p-3">
                        <li
                            v-for="s in syarat"
                            :key="s.teks"
                            class="flex items-center gap-2 text-xs transition-colors"
                            :class="s.lolos ? 'text-sentimen-positif' : 'text-muted-foreground'"
                        >
                            <component :is="s.lolos ? Check : Circle" class="size-3.5 shrink-0" aria-hidden="true" />
                            {{ s.teks }}
                        </li>
                    </ul>

                    <div class="flex items-center gap-3 border-t pt-4">
                        <Button type="submit" class="tekan" :disabled="form.processing">
                            <Loader2 v-if="form.processing" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                            <ShieldCheck v-else class="size-4" aria-hidden="true" />
                            Simpan kata sandi
                        </Button>

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
    </SettingsLayout>
</template>
