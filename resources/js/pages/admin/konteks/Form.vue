<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface Konteks {
    id: number;
    nama: string;
    slug: string;
    deskripsi: string | null;
    deskripsi_model: string | null;
    kata_kunci: string[] | null;
    utama: boolean;
    urutan: number;
    aktif: boolean;
}

const props = defineProps<{ konteks: Konteks | null }>();

const form = useForm({
    nama: props.konteks?.nama ?? '',
    slug: props.konteks?.slug ?? '',
    deskripsi: props.konteks?.deskripsi ?? '',
    deskripsi_model: props.konteks?.deskripsi_model ?? '',
    kata_kunci: (props.konteks?.kata_kunci ?? []).join('\n'),
    utama: props.konteks?.utama ?? false,
    urutan: props.konteks?.urutan ?? 0,
    aktif: props.konteks?.aktif ?? true,
});

function simpan() {
    if (props.konteks) {
        form.put(`/admin/konteks/${props.konteks.id}`);
    } else {
        form.post('/admin/konteks');
    }
}
</script>

<template>
    <Head :title="konteks ? `Ubah ${konteks.nama}` : 'Tambah konteks'" />

    <LayoutAdmin
        :judul="konteks ? `Ubah ${konteks.nama}` : 'Tambah konteks pantauan'"
        :breadcrumbs="[
            { title: 'Konteks', href: '/admin/konteks' },
            { title: konteks ? 'Ubah' : 'Tambah', href: '#' },
        ]"
    >
        <Card class="max-w-2xl">
            <CardContent class="pt-6">
                <form class="space-y-4" @submit.prevent="simpan">
                    <div class="grid gap-1.5">
                        <Label for="nama">Nama konteks</Label>
                        <Input id="nama" v-model="form.nama" required autofocus placeholder="Pemerintah Kota Kendari" />
                        <p class="text-xs text-muted-foreground">
                            Dikirim apa adanya ke model sentimen sebagai input konteks. Tulis seperti kalimat
                            yang wajar dibaca, bukan seperti kode.
                        </p>
                        <InputError :message="form.errors.nama" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="deskripsi">Deskripsi untuk manusia</Label>
                        <textarea
                            id="deskripsi"
                            v-model="form.deskripsi"
                            rows="2"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                            placeholder="Untuk admin dan pelabel. Tidak dikirim ke model."
                        />
                        <p class="text-xs text-muted-foreground">
                            Hanya dibaca orang di halaman admin. Mengubahnya tidak mengubah hasil apa pun.
                        </p>
                    </div>

                    <!--
                        Field inilah yang benar-benar menentukan relevansi, dan sebelumnya
                        tidak ada di form sama sekali. Akibatnya admin menyunting "Deskripsi"
                        di atas sambil mengira itu mengubah perilaku model, padahal tidak.
                    -->
                    <div class="grid gap-1.5 rounded-md border border-sentimen-review/40 bg-sentimen-review-lembut/30 p-3">
                        <Label for="deskripsi_model">Deskripsi untuk model, penentu relevansi</Label>
                        <textarea
                            id="deskripsi_model"
                            v-model="form.deskripsi_model"
                            rows="8"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm leading-relaxed"
                            placeholder="Artikel membahas ... secara substantif: ... Bukan artikel yang hanya ..."
                        />
                        <p class="text-xs text-muted-foreground">
                            Teks ini diubah menjadi vektor, lalu setiap artikel dinilai dari seberapa mirip
                            maknanya dengan teks ini. Tulis dua bagian: apa yang termasuk, lalu apa yang
                            <strong>tidak</strong> termasuk. Bagian kedua sama pentingnya, karena kesalahan
                            tersering bukan gagal mengenali Pemkot melainkan gagal membedakannya dari Pemprov,
                            instansi vertikal, dan Kendari sebagai lokasi belaka.
                        </p>
                        <p class="text-xs font-medium">
                            Setelah mengubahnya, jalankan
                            <code class="rounded bg-muted px-1">php artisan nlp:hitung-ulang-vektor</code>.
                            Sebelum itu dijalankan, seluruh skor relevansi masih dibandingkan terhadap
                            deskripsi yang lama.
                        </p>
                        <InputError :message="form.errors.deskripsi_model" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="kata_kunci">Kata kunci penyaring</Label>
                        <textarea
                            id="kata_kunci"
                            v-model="form.kata_kunci"
                            rows="5"
                            class="rounded-md border border-input bg-background px-3 py-2 font-mono text-xs"
                            placeholder="satu kata kunci per baris&#10;pemkot kendari&#10;wali kota kendari"
                        />
                        <p class="text-xs text-muted-foreground">
                            Satu per baris. Dipakai dua kali: menyaring artikel yang jelas tidak nyambung sebelum
                            model dipanggil, dan mengetatkan hasil model setelahnya, artikel dianggap benar-benar
                            membahas konteks kalau kata kunci muncul di judul, atau minimal tiga kali di badan
                            berita. Tanpa pengetat itu, presisi penyaring hanya 47%.
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Buat longgar dan lengkap, termasuk variasi penulisannya. Dikosongkan berarti konteks ini
                            tidak disaring maupun diketatkan sama sekali.
                        </p>
                        <InputError :message="form.errors.kata_kunci" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="urutan">Urutan tampil</Label>
                            <Input id="urutan" v-model.number="form.urutan" type="number" min="0" max="999" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="slug">Slug</Label>
                            <Input id="slug" v-model="form.slug" placeholder="Dibuat otomatis dari nama" />
                            <InputError :message="form.errors.slug" />
                        </div>
                    </div>

                    <label class="flex items-start gap-2 text-sm">
                        <Checkbox v-model="form.utama" class="mt-0.5" />
                        <span>
                            Jadikan konteks utama
                            <span class="block text-xs text-muted-foreground">
                                Seluruh angka dashboard eksekutif bertumpu pada konteks ini. Hanya boleh ada satu,
                                menandai yang baru otomatis melepas yang lama.
                            </span>
                        </span>
                    </label>

                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox v-model="form.aktif" />
                        Aktif
                    </label>

                    <div class="flex gap-2">
                        <Button type="submit" :disabled="form.processing">Simpan</Button>
                        <Button variant="ghost" as-child>
                            <Link href="/admin/konteks">Batal</Link>
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </LayoutAdmin>
</template>
