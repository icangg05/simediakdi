<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import type { OpsiFilter } from '@/types/tabel';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface Aturan {
    id: number;
    nama: string;
    jenis: string;
    ambang: number | null;
    jendela_jam: number;
    jeda_minimal_jam: number;
    kanal: string;
    penerima: string[] | null;
    aktif: boolean;
    kondisi: Record<string, unknown> | null;
}

const props = defineProps<{ aturan: Aturan | null }>();

const kondisi = (props.aturan?.kondisi ?? {}) as Record<string, never>;

const form = useForm({
    nama: props.aturan?.nama ?? '',
    jenis: props.aturan?.jenis ?? 'lonjakan_negatif',
    ambang: props.aturan?.ambang ?? null,
    jendela_jam: props.aturan?.jendela_jam ?? 6,
    jeda_minimal_jam: props.aturan?.jeda_minimal_jam ?? 6,
    kanal: props.aturan?.kanal ?? 'telegram',
    penerima: props.aturan?.penerima ?? [],
    aktif: props.aturan?.aktif ?? true,
    kondisi: {
        minimal_artikel: (kondisi.minimal_artikel as number | undefined) ?? 3,
        kelipatan_dari_rata_rata: (kondisi.kelipatan_dari_rata_rata as number | undefined) ?? 2,
        abaikan_perlu_review: (kondisi.abaikan_perlu_review as boolean | undefined) ?? true,
        istilah: ((kondisi.istilah as string[] | undefined) ?? []).join('\n'),
        jam: (kondisi.jam as number | undefined) ?? 24,
    },
});

function simpan() {
    if (props.aturan) {
        form.put(`/admin/alert/${props.aturan.id}`);
    } else {
        form.post('/admin/alert');
    }
}
</script>

<template>
    <Head :title="aturan ? `Ubah ${aturan.nama}` : 'Tambah aturan alert'" />

    <LayoutAdmin
        :judul="aturan ? `Ubah ${aturan.nama}` : 'Tambah aturan alert'"
        :breadcrumbs="[
            { title: 'Alert', href: '/admin/alert' },
            { title: aturan ? 'Ubah' : 'Tambah', href: '#' },
        ]"
    >
        <Card class="max-w-2xl">
            <CardContent class="pt-6">
                <form class="space-y-4" @submit.prevent="simpan">
                    <div class="grid gap-1.5">
                        <Label for="nama">Nama aturan</Label>
                        <Input id="nama" v-model="form.nama" required autofocus placeholder="Lonjakan berita negatif Pemkot" />
                        <p class="text-xs text-muted-foreground">
                            Nama ini yang muncul sebagai judul pesan di grup Telegram. Tulis supaya terbaca jelas di
                            layar ponsel tanpa membuka aplikasi.
                        </p>
                        <InputError :message="form.errors.nama" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="jenis">Jenis</Label>
                        <select
                            id="jenis"
                            v-model="form.jenis"
                            class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="lonjakan_negatif">Lonjakan berita negatif</option>
                            <option value="kata_kunci_muncul">Kata kunci muncul di judul</option>
                            <option value="sumber_mati">Sumber feed berhenti menghasilkan berita</option>
                            <option value="kontrak_tertinggal">Kontrak tertinggal dari target</option>
                        </select>
                        <InputError :message="form.errors.jenis" />
                    </div>

                    <template v-if="form.jenis === 'lonjakan_negatif'">

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="minimal">Minimal artikel</Label>
                                <Input id="minimal" v-model.number="form.kondisi.minimal_artikel" type="number" min="1" />
                                <p class="text-xs text-muted-foreground">
                                    Penahan utamanya. Tanpa ini, kenaikan 1 ke 3 artikel terbaca sebagai lonjakan 300%
                                    dan alert menyala hampir tiap hari.
                                </p>
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="kelipatan">Kelipatan dari rata-rata</Label>
                                <Input id="kelipatan" v-model.number="form.kondisi.kelipatan_dari_rata_rata" type="number" step="0.1" min="1" />
                                <p class="text-xs text-muted-foreground">
                                    Dibandingkan rata-rata empat jendela sebelumnya, bukan satu.
                                </p>
                            </div>
                        </div>

                        <label class="flex items-start gap-2 text-sm">
                            <Checkbox v-model:checked="form.kondisi.abaikan_perlu_review" class="mt-0.5" />
                            <span>
                                Abaikan artikel yang model sendiri ragukan
                                <span class="block text-xs text-muted-foreground">
                                    Label yang ditandai perlu review biasanya bukan bahan yang pantas membangunkan orang
                                    di luar jam kerja.
                                </span>
                            </span>
                        </label>
                    </template>

                    <div v-else-if="form.jenis === 'kata_kunci_muncul'" class="grid gap-1.5">
                        <Label for="istilah">Istilah, satu per baris</Label>
                        <textarea
                            id="istilah"
                            v-model="form.kondisi.istilah"
                            rows="4"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                            placeholder="demo&#10;unjuk rasa&#10;pungli"
                        />
                        <InputError :message="form.errors['kondisi.istilah']" />
                    </div>

                    <div v-else-if="form.jenis === 'sumber_mati'" class="grid gap-1.5">
                        <Label for="jam">Diam berapa jam sebelum dianggap mati</Label>
                        <Input id="jam" v-model.number="form.kondisi.jam" type="number" min="1" max="720" />
                        <p class="text-xs text-muted-foreground">
                            Media daerah wajar diam semalaman, jadi ambangnya bukan hitungan menit.
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="jendela">Jendela waktu (jam)</Label>
                            <Input id="jendela" v-model.number="form.jendela_jam" type="number" min="1" max="168" required />
                            <InputError :message="form.errors.jendela_jam" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="jeda">Jeda minimal antar kirim (jam)</Label>
                            <Input id="jeda" v-model.number="form.jeda_minimal_jam" type="number" min="1" max="168" required />
                            <p class="text-xs text-muted-foreground">
                                Pembatas pengiriman berulang. Aturan yang mengirim tiap 15 menit membuat orang mematikan
                                notifikasi grupnya, dan setelah itu tidak ada alert yang sampai.
                            </p>
                            <InputError :message="form.errors.jeda_minimal_jam" />
                        </div>
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox v-model:checked="form.aktif" />
                        Aktif
                    </label>

                    <div class="flex gap-2 pt-2">
                        <Button type="submit" :disabled="form.processing">Simpan</Button>
                        <Button as-child type="button" variant="ghost">
                            <Link href="/admin/alert">Batal</Link>
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </LayoutAdmin>
</template>
