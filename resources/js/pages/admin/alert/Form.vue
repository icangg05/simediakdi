<script setup lang="ts">
import KopHalaman from '@/components/domain/KopHalaman.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Loader2, Power, RadioTower, Search, Send, Siren, SlidersHorizontal, Zap } from 'lucide-vue-next';
import type { Component } from 'vue';

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

/**
 * Jenis dipilih lewat kartu, bukan `<select>` polos.
 *
 * Pilihan ini menentukan bidang isian mana yang muncul di bawahnya, dan sebab
 * akibat itu jauh lebih terbaca kalau ketiganya terlihat sekaligus. Ronanya
 * sama dengan lencana jenis di daftar aturan: merah untuk lonjakan nada
 * negatif, biru untuk pengamatan kata kunci, kuning untuk keadaan sistem yang
 * perlu diperiksa.
 */
const JENIS: { nilai: string; label: string; keterangan: string; ikon: Component; kelas: string; ikonKelas: string }[] = [
    {
        nilai: 'berita_negatif',
        label: 'Berita negatif seketika',
        keterangan: 'Satu pesan per berita, terkirim tepat setelah Gemini menilainya negatif. Tanpa jeda.',
        ikon: Zap,
        kelas: 'border-sentimen-negatif/50 bg-sentimen-negatif-lembut/60',
        ikonKelas: 'text-sentimen-negatif',
    },
    {
        nilai: 'lonjakan_negatif',
        label: 'Lonjakan berita negatif',
        keterangan: 'Membandingkan jumlah berita negatif terhadap rata-rata jendela sebelumnya.',
        ikon: Siren,
        kelas: 'border-sentimen-negatif/50 bg-sentimen-negatif-lembut/60',
        ikonKelas: 'text-sentimen-negatif',
    },
    {
        nilai: 'kata_kunci_muncul',
        label: 'Kata kunci muncul di judul',
        keterangan: 'Mengawasi daftar istilah, terlepas dari sentimen beritanya.',
        ikon: Search,
        kelas: 'border-aksen-biru/50 bg-aksen-biru/5',
        ikonKelas: 'text-aksen-biru',
    },
    {
        nilai: 'sumber_mati',
        label: 'Sumber feed berhenti',
        keterangan: 'Memberi tahu saat sebuah sumber tidak lagi menghasilkan berita.',
        ikon: RadioTower,
        kelas: 'border-sentimen-review/50 bg-sentimen-review-lembut/60',
        ikonKelas: 'text-sentimen-review',
    },
];

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
        :breadcrumbs="[
            { title: 'Alert', href: '/admin/alert' },
            { title: aturan ? 'Ubah' : 'Tambah', href: '#' },
        ]"
    >
        <KopHalaman
            :judul="aturan ? `Ubah ${aturan.nama}` : 'Tambah aturan alert'"
            keterangan="Aturan yang terlalu longgar membuat grup Telegram ramai, dan grup yang ramai membuat orang mematikan notifikasinya. Setelah itu tidak ada alert yang sampai."
        />

        <Card class="muncul max-w-2xl overflow-hidden" style="animation-delay: 80ms">
            <CardContent class="p-5 sm:p-6">
                <form class="space-y-6" @submit.prevent="simpan">
                    <div class="grid gap-1.5">
                        <Label for="nama">Nama aturan</Label>
                        <Input id="nama" v-model="form.nama" required autofocus placeholder="Lonjakan berita negatif Pemkot" />
                        <p class="text-xs text-muted-foreground">
                            Nama ini yang muncul sebagai judul pesan di grup Telegram. Tulis supaya terbaca jelas di layar ponsel tanpa membuka
                            aplikasi.
                        </p>
                        <InputError :message="form.errors.nama" />
                    </div>

                    <section class="space-y-3">
                        <div class="flex items-center gap-2">
                            <Siren class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <h2 class="shrink-0 text-xs font-semibold tracking-wide text-muted-foreground uppercase">Yang diawasi</h2>
                            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                        </div>

                        <div role="radiogroup" aria-label="Jenis aturan" class="grid gap-2 sm:grid-cols-2">
                            <button
                                v-for="j in JENIS"
                                :key="j.nilai"
                                type="button"
                                role="radio"
                                :aria-checked="form.jenis === j.nilai"
                                class="tekan flex flex-col gap-1.5 rounded-lg border p-3 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
                                :class="form.jenis === j.nilai ? j.kelas : 'hover:bg-muted/50'"
                                @click="form.jenis = j.nilai"
                            >
                                <component
                                    :is="j.ikon"
                                    class="size-4 shrink-0"
                                    :class="form.jenis === j.nilai ? j.ikonKelas : 'text-muted-foreground'"
                                    aria-hidden="true"
                                />
                                <span class="text-sm font-medium">{{ j.label }}</span>
                                <span class="text-xs text-muted-foreground">{{ j.keterangan }}</span>
                            </button>
                        </div>
                        <InputError :message="form.errors.jenis" />

                        <!-- Bidang lanjutan menempel di dalam bidang bertinta
                             yang ronanya mengikuti jenis terpilih, jadi terlihat
                             ia milik kartu yang barusan dipilih, bukan
                             pertanyaan baru yang berdiri sendiri. -->
                        <div v-if="form.jenis === 'berita_negatif'" class="space-y-2 rounded-lg bg-sentimen-negatif-lembut/40 p-3">
                            <label class="flex items-start gap-2 text-sm">
                                <input v-model="form.kondisi.abaikan_perlu_review" type="checkbox" class="mt-0.5 size-4 rounded border-input" />
                                <span>
                                    Lewati berita yang model sendiri ragukan
                                    <span class="mt-0.5 block text-xs text-muted-foreground">
                                        Berita yang masuk antrean review dikirim setelah ada yang memutuskan labelnya, bukan saat model masih ragu.
                                    </span>
                                </span>
                            </label>
                            <p class="text-xs text-muted-foreground">
                                Aturan ini tidak dinilai berkala dan tidak memakai jendela waktu maupun jeda. Satu berita negatif berarti satu pesan,
                                saat itu juga. Berita yang datang berturut-turut terkirim satu per satu.
                            </p>
                        </div>

                        <div v-else-if="form.jenis === 'lonjakan_negatif'" class="space-y-3 rounded-lg bg-sentimen-negatif-lembut/40 p-3">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="grid gap-1.5">
                                    <Label for="minimal">Minimal artikel</Label>
                                    <Input id="minimal" v-model.number="form.kondisi.minimal_artikel" type="number" min="1" class="bg-background" />
                                    <p class="text-xs text-muted-foreground">
                                        Penahan utamanya. Tanpa ini, kenaikan 1 ke 3 artikel terbaca sebagai lonjakan 300% dan alert menyala hampir
                                        tiap hari.
                                    </p>
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="kelipatan">Kelipatan dari rata-rata</Label>
                                    <Input
                                        id="kelipatan"
                                        v-model.number="form.kondisi.kelipatan_dari_rata_rata"
                                        type="number"
                                        step="0.1"
                                        min="1"
                                        class="bg-background"
                                    />
                                    <p class="text-xs text-muted-foreground">Dibandingkan rata-rata empat jendela sebelumnya, bukan satu.</p>
                                </div>
                            </div>

                            <label class="flex cursor-pointer items-start gap-2 rounded-md bg-background p-2.5 text-sm">
                                <Checkbox v-model:checked="form.kondisi.abaikan_perlu_review" class="mt-0.5" />
                                <span class="min-w-0">
                                    Abaikan artikel yang model sendiri ragukan
                                    <span class="block text-xs text-muted-foreground">
                                        Label yang ditandai perlu review biasanya bukan bahan yang pantas membangunkan orang di luar jam kerja.
                                    </span>
                                </span>
                            </label>
                        </div>

                        <div v-else-if="form.jenis === 'kata_kunci_muncul'" class="grid gap-1.5 rounded-lg bg-aksen-biru/5 p-3">
                            <Label for="istilah">Istilah, satu per baris</Label>
                            <textarea
                                id="istilah"
                                v-model="form.kondisi.istilah"
                                rows="4"
                                class="rounded-md border border-input bg-background px-3 py-2 text-sm leading-relaxed outline-hidden transition-colors focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                placeholder="demo&#10;unjuk rasa&#10;pungli"
                            />
                            <InputError :message="form.errors['kondisi.istilah']" />
                        </div>

                        <div v-else-if="form.jenis === 'sumber_mati'" class="grid gap-1.5 rounded-lg bg-sentimen-review-lembut/40 p-3">
                            <Label for="jam">Diam berapa jam sebelum dianggap mati</Label>
                            <Input id="jam" v-model.number="form.kondisi.jam" type="number" min="1" max="720" class="bg-background sm:w-40" />
                            <p class="text-xs text-muted-foreground">Media daerah wajar diam semalaman, jadi ambangnya bukan hitungan menit.</p>
                        </div>
                    </section>

                    <section v-if="form.jenis !== 'berita_negatif'" class="space-y-3">
                        <div class="flex items-center gap-2">
                            <SlidersHorizontal class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <h2 class="shrink-0 text-xs font-semibold tracking-wide text-muted-foreground uppercase">Waktu dan pengiriman</h2>
                            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="jendela">Jendela waktu (jam)</Label>
                                <Input id="jendela" v-model.number="form.jendela_jam" type="number" min="1" max="168" required />
                                <p class="text-xs text-muted-foreground">Rentang yang dihitung setiap kali aturan dinilai.</p>
                                <InputError :message="form.errors.jendela_jam" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="jeda">Jeda minimal antar kirim (jam)</Label>
                                <Input id="jeda" v-model.number="form.jeda_minimal_jam" type="number" min="1" max="168" required />
                                <p class="text-xs text-muted-foreground">
                                    Pembatas pengiriman berulang. Aturan yang mengirim tiap 15 menit membuat orang mematikan notifikasi grupnya, dan
                                    setelah itu tidak ada alert yang sampai.
                                </p>
                                <InputError :message="form.errors.jeda_minimal_jam" />
                            </div>
                        </div>

                        <p class="flex items-start gap-1.5 text-xs text-muted-foreground">
                            <Send class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                            Dikirim lewat Telegram. Ini satu-satunya kanal yang tersedia, dan alamat tujuannya disetel di halaman Pengaturan sistem.
                        </p>
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
                            <span class="min-w-0">
                                {{
                                    form.jenis === 'berita_negatif'
                                        ? 'Aktif, setiap berita negatif langsung dikirim'
                                        : 'Aktif, aturan ini ikut dinilai berkala'
                                }}
                            </span>
                        </label>
                    </section>

                    <div class="flex flex-wrap gap-2 border-t pt-4">
                        <Button type="submit" class="tekan" :disabled="form.processing">
                            <Loader2 v-if="form.processing" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                            Simpan
                        </Button>
                        <Button as-child type="button" variant="ghost">
                            <Link href="/admin/alert">Batal</Link>
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </LayoutAdmin>
</template>
