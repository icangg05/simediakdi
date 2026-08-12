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
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Handshake, Link2, Loader2, MapPin, Power } from 'lucide-vue-next';

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
        :breadcrumbs="[
            { title: 'Media', href: '/admin/media' },
            { title: media ? 'Ubah' : 'Tambah', href: '#' },
        ]"
    >
        <KopHalaman
            :judul="media ? `Ubah ${media.nama}` : 'Tambah media'"
            keterangan="Identitas media dan cara sistem mencocokkan artikel kepadanya. Sumber pengambilan diatur terpisah di halaman kelola media."
        />

        <Card class="muncul max-w-3xl overflow-hidden" style="animation-delay: 80ms">
            <CardContent class="p-5 sm:p-6">
                <!--
                    Form dibagi tiga kelompok berjudul, bukan satu kisi panjang
                    berisi tiga belas kotak. Yang membedakan ketiganya bukan
                    tampilan: identitas menentukan bagaimana artikel dicocokkan,
                    lokasi dan kontak hanya keterangan, dan saklar di bawah
                    menentukan apakah medianya ditarik sama sekali. Judul
                    bergaris memisahkannya tanpa menambah kotak berbingkai di
                    dalam kartu.
                -->
                <form class="space-y-6" @submit.prevent="simpan">
                    <section class="space-y-3">
                        <div class="flex items-center gap-2">
                            <Link2 class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <h2 class="shrink-0 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Identitas dan pencocokan</h2>
                            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
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
                                    Dipakai mencocokkan artikel ke media. Tulis subdomain lengkap kalau medianya menumpang domain induk, supaya
                                    artikel dari media lain di domain yang sama tidak ikut tercocokkan.
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
                        </div>
                    </section>

                    <section class="space-y-3">
                        <div class="flex items-center gap-2">
                            <MapPin class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <h2 class="shrink-0 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Lokasi dan kontak</h2>
                            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
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
                                    class="rounded-md border border-input bg-background px-3 py-2 text-sm leading-relaxed shadow-sm outline-none transition-colors focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    placeholder="Jenis situs dan jalur pengambilannya, misalnya: SPA tanpa feed, andalkan portal pelaporan."
                                />
                            </div>
                        </div>
                    </section>

                    <section class="space-y-3">
                        <div class="flex items-center gap-2">
                            <Power class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <h2 class="shrink-0 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Saklar</h2>
                            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                        </div>

                        <!-- Kedua saklar diberi bidang yang bisa diketuk penuh,
                             bukan kotak centang kecil dengan teks di sebelahnya.
                             Ikonnya ikut menyala saat dicentang, jadi keadaannya
                             terbaca tanpa memeriksa kotak centangnya. -->
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label
                                class="tekan flex cursor-pointer items-center gap-2.5 rounded-lg border p-3 text-sm transition-colors"
                                :class="form.partner ? 'border-aksen-toska/40 bg-aksen-toska/5' : 'hover:bg-muted/50'"
                            >
                                <Checkbox v-model:checked="form.partner" />
                                <Handshake
                                    class="size-4 shrink-0"
                                    :class="form.partner ? 'text-aksen-toska' : 'text-muted-foreground'"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0">Punya kerja sama dengan Pemkot</span>
                            </label>

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
                                <span class="min-w-0">Aktif, beritanya ditarik sistem</span>
                            </label>
                        </div>
                    </section>

                    <div class="flex flex-wrap gap-2 border-t pt-4">
                        <Button type="submit" class="tekan" :disabled="form.processing">
                            <Loader2 v-if="form.processing" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                            Simpan
                        </Button>
                        <Button variant="ghost" as-child>
                            <Link href="/admin/media">Batal</Link>
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </LayoutAdmin>
</template>
