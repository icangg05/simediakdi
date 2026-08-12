<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Loader2, TriangleAlert } from 'lucide-vue-next';

/*
 * CATATAN PRODUK, perlu keputusan.
 *
 * PRODUCT.md bagian Keamanan berbunyi "Akun dinonaktifkan, tidak dihapus", dan
 * `ProfileController::destroy` memanggil `$user->delete()`. Tombol di bawah
 * karena itu bertentangan dengan batasan produk yang sudah ditetapkan, dan ia
 * juga menghapus jejak yang diandalkan audit log.
 *
 * Berkas ini tidak menghapus fiturnya sendiri, karena mencabut kemampuan yang
 * sudah berjalan bukan keputusan yang boleh diambil diam-diam. Yang dilakukan
 * di sini hanya membuat akibatnya terbaca sejelas mungkin sebelum ditekan.
 */
const passwordInput = ref<HTMLInputElement | null>(null);

const form = useForm({
    password: '',
});

const hapusAkun = (e: Event) => {
    e.preventDefault();

    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => tutup(),
        onError: () => passwordInput.value?.focus(),
        onFinish: () => form.reset(),
    });
};

const tutup = () => {
    form.clearErrors();
    form.reset();
};
</script>

<template>
    <!--
        Bidang merah, bukan kartu putih dengan satu tombol merah di dalamnya.

        Ini satu-satunya tindakan di seluruh area pengaturan yang tidak bisa
        dibatalkan, dan ia berdiri persis di bawah form yang tombolnya cuma
        menyimpan nama. Bidang berwarna memisahkan keduanya sebelum dibaca,
        sehingga tidak ada yang menekannya karena mengira itu tombol simpan
        kedua.
    -->
    <Card class="muncul overflow-hidden border-destructive/30" style="animation-delay: 140ms">
        <CardContent class="space-y-4 bg-destructive/5 p-5 sm:p-6">
            <div class="flex items-start gap-3">
                <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-destructive/10 text-destructive">
                    <TriangleAlert class="size-4" aria-hidden="true" />
                </span>
                <div class="min-w-0 space-y-1">
                    <h2 class="text-sm font-semibold text-destructive">Hapus akun</h2>
                    <p class="text-sm text-muted-foreground">
                        Akun beserta seluruh datanya dihapus permanen dan tidak bisa dikembalikan. Kalau Anda hanya ingin berhenti memakai sistem
                        untuk sementara, minta admin menonaktifkan akun Anda alih-alih menghapusnya.
                    </p>
                </div>
            </div>

            <Dialog>
                <DialogTrigger as-child>
                    <Button variant="destructive" class="tekan">Hapus akun saya</Button>
                </DialogTrigger>

                <DialogContent>
                    <form class="space-y-5" @submit="hapusAkun">
                        <DialogHeader class="space-y-3">
                            <DialogTitle>Hapus akun ini secara permanen?</DialogTitle>
                            <DialogDescription>
                                Begitu akun dihapus, seluruh data yang menempel padanya ikut hilang dan tidak bisa dipulihkan. Masukkan kata sandi
                                Anda untuk memastikan ini benar-benar Anda.
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-1.5">
                            <Label for="password">Kata sandi</Label>
                            <Input
                                id="password"
                                ref="passwordInput"
                                v-model="form.password"
                                type="password"
                                name="password"
                                autocomplete="current-password"
                                placeholder="Kata sandi Anda"
                            />
                            <InputError :message="form.errors.password" />
                        </div>

                        <DialogFooter>
                            <DialogClose as-child>
                                <Button type="button" variant="outline" @click="tutup">Batal</Button>
                            </DialogClose>

                            <Button type="submit" variant="destructive" :disabled="form.processing">
                                <Loader2 v-if="form.processing" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                                Hapus permanen
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </CardContent>
    </Card>
</template>
