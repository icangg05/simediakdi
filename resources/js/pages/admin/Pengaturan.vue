<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import IndikatorKesehatan from '@/components/domain/IndikatorKesehatan.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { computed } from 'vue';

interface Nilai {
    label: string;
    nilai: string | number | null;
    env: string;
    diukur: string | null;
}

interface PengaturanAi {
    model: string;
    versi_prompt_relevansi: string;
    prompt_relevansi: string;
    versi_prompt_sentimen: string;
    prompt_sentimen: string;
}

interface Kunci {
    id: number;
    label: string;
    aktif: boolean;
    limit_sampai: string | null;
    alasan_limit: string | null;
    terakhir_dipakai_at: string | null;
}

const props = defineProps<{
    pengaturanAi: PengaturanAi;
    kunci: Kunci[];
    kelompok: { judul: string; catatan: string | null; nilai: Nilai[] }[];
    layanan: { nama: string; sehat: boolean; url: string }[];
    evaluasi: { f1_macro: number; jumlah_sampel: number; dievaluasi_at: string } | null;
}>();

const formAi = useForm({ ...props.pengaturanAi });
const formKunci = useForm({ label: '', kunci: '' });

function simpanAi() {
    formAi.put('/admin/pengaturan/ai', { preserveScroll: true });
}

function tambahKunci() {
    formKunci.post('/admin/pengaturan/kunci', {
        preserveScroll: true,
        onSuccess: () => formKunci.reset(),
    });
}

function ubahAktif(k: Kunci) {
    router.put(`/admin/pengaturan/kunci/${k.id}`, { aktif: !k.aktif }, { preserveScroll: true });
}

function hapusKunci(k: Kunci) {
    if (confirm(`Hapus kunci ${k.label}? Klasifikasi akan memakai kunci yang tersisa.`)) {
        router.delete(`/admin/pengaturan/kunci/${k.id}`, { preserveScroll: true });
    }
}

const waktu = (iso: string) => format(new Date(iso), 'd MMM yyyy HH:mm', { locale: id });

const alasan: Record<string, string> = {
    kuota_harian: 'kuota harian habis',
    kuota_menit: 'kuota per menit habis',
    retry_delay: 'diminta menunggu oleh Google',
};

const jumlahAktif = computed(() => props.kunci.filter((k) => k.aktif).length);

/** Menyalakan selalu boleh. Mematikan hanya boleh selama masih ada kunci menyala lain. */
function bisaDimatikan(k: Kunci): boolean {
    return !k.aktif || jumlahAktif.value > 1;
}

/** Kunci yang sudah mati tidak mengurangi kunci menyala, jadi menghapusnya aman. */
function bisaDihapus(k: Kunci): boolean {
    return props.kunci.length > 1 && (!k.aktif || jumlahAktif.value > 1);
}

function status(k: Kunci): string {
    if (!k.aktif) return 'Dimatikan';
    if (k.limit_sampai) {
        return `Kena limit sampai ${waktu(k.limit_sampai)}` + (k.alasan_limit ? ` (${alasan[k.alasan_limit] ?? k.alasan_limit})` : '');
    }
    return 'Siap dipakai';
}
</script>

<template>
    <Head title="Pengaturan sistem" />

    <LayoutAdmin judul="Pengaturan sistem" :breadcrumbs="[{ title: 'Pengaturan', href: '/admin/pengaturan' }]">
        <div class="space-y-1 rounded-md border bg-muted/40 p-3 text-sm">
            <p class="font-medium">Hanya pengaturan Gemini yang bisa disunting di sini.</p>
            <p class="text-muted-foreground">
                Model, kedua prompt, dan daftar kunci API disetel dari layar ini karena keduanya perlu diperbaiki saat itu juga. Nilai lain di
                bawahnya hanya ditampilkan. Ambang tersebut mengubah setiap angka dashboard secara surut, termasuk untuk periode yang sudah dilaporkan
                ke pimpinan, jadi perubahannya lewat <code class="text-xs">.env</code> dan deploy supaya tercatat di git bersama alasannya. Kolom
                "diukur dari" ada supaya angka-angka ini tidak terbaca sebagai selera.
            </p>
        </div>

        <Card>
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">Layanan</CardTitle>
            </CardHeader>
            <CardContent class="space-y-2">
                <IndikatorKesehatan
                    v-for="l in props.layanan"
                    :key="l.nama"
                    :label="l.nama"
                    :status="l.sehat ? 'hijau' : 'merah'"
                    :keterangan="
                        l.sehat
                            ? l.url
                            : `Tidak menjawab di ${l.url}. Job yang membutuhkannya menumpuk di antrean dan jalan lagi setelah layanan hidup.`
                    "
                />
            </CardContent>
        </Card>

        <Card v-if="props.evaluasi">
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">Evaluasi model terakhir</CardTitle>
            </CardHeader>
            <CardContent class="text-sm">
                F1 macro <span class="angka font-medium">{{ props.evaluasi.f1_macro.toFixed(4) }}</span> dari
                {{ props.evaluasi.jumlah_sampel }} sampel gold set,
                {{ format(new Date(props.evaluasi.dievaluasi_at), 'd MMMM yyyy', { locale: id }) }}.
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">Kunci API Gemini</CardTitle>
                <p class="text-xs text-muted-foreground">
                    Satu kunci berarti satu kuota harian. Kunci yang kena limit ditandai beserta waktu pulihnya dan dilewati sampai waktu itu, jadi
                    kuota tidak habis untuk permintaan yang sudah pasti ditolak. Kuota harian Gemini pulih pada tengah malam waktu Pasifik, bukan 24
                    jam setelah pemakaian.
                </p>
            </CardHeader>
            <CardContent class="space-y-4">
                <p v-if="props.kunci.length === 0" class="text-sm text-muted-foreground">
                    Belum ada kunci di database, jadi klasifikasi tidak bisa dijalankan sampai ada satu kunci.
                </p>

                <ul v-else class="divide-y rounded-md border">
                    <li v-for="k in props.kunci" :key="k.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                        <div class="space-y-0.5">
                            <p class="text-sm">{{ k.label }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ status(k) }}
                                <template v-if="k.terakhir_dipakai_at"> · terakhir dipakai {{ waktu(k.terakhir_dipakai_at) }} </template>
                            </p>
                        </div>
                        <!-- Satu kunci harus selalu tersisa menyala. Tombol yang akan mematikan
                             kunci terakhir tidak ditampilkan, karena menghentikan klasifikasi
                             seluruh sistem bukan yang dimaksud siapa pun yang menekannya. -->
                        <div class="flex gap-2">
                            <Button v-if="bisaDimatikan(k)" type="button" variant="outline" size="sm" @click="ubahAktif(k)">
                                {{ k.aktif ? 'Matikan' : 'Nyalakan' }}
                            </Button>
                            <Button v-if="bisaDihapus(k)" type="button" variant="ghost" size="sm" @click="hapusKunci(k)">Hapus</Button>
                        </div>
                    </li>
                </ul>

                <form class="flex flex-wrap items-end gap-2" @submit.prevent="tambahKunci">
                    <div class="grid flex-1 gap-1.5">
                        <Label for="label-kunci">Label</Label>
                        <Input id="label-kunci" v-model="formKunci.label" placeholder="Akun cadangan 1" />
                        <InputError :message="formKunci.errors.label" />
                    </div>
                    <div class="grid flex-[2] gap-1.5">
                        <Label for="isi-kunci">Kunci API</Label>
                        <Input id="isi-kunci" v-model="formKunci.kunci" type="password" autocomplete="off" placeholder="AIza..." />
                        <InputError :message="formKunci.errors.kunci" />
                    </div>
                    <Button type="submit" :disabled="formKunci.processing">Tambah kunci</Button>
                </form>

                <p class="text-xs text-muted-foreground">
                    Kunci disimpan terenkripsi dan tidak pernah ditampilkan kembali. Kunci yang salah tempel harus dihapus lalu ditambahkan ulang.
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">Klasifikasi Gemini</CardTitle>
                <p class="text-xs text-muted-foreground">
                    Menyimpan pengaturan di sini tidak mengubah artikel yang sudah dinilai. Setiap hasil menyimpan nama model dan versi promptnya
                    sendiri, jadi hasil lama dan hasil baru tetap bisa dibedakan.
                </p>
            </CardHeader>
            <CardContent>
                <form class="space-y-4" @submit.prevent="simpanAi">
                    <div class="grid gap-1.5">
                        <Label for="model">Model</Label>
                        <Input id="model" v-model="formAi.model" placeholder="gemini-3.5-flash-lite" />
                        <InputError :message="formAi.errors.model" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="versi-relevansi">Label versi prompt relevansi</Label>
                        <Input id="versi-relevansi" v-model="formAi.versi_prompt_relevansi" placeholder="relevance-v2" />
                        <p class="text-xs text-muted-foreground">
                            Sidik isi prompt ditambahkan otomatis, jadi lupa menaikkan label tidak membuat dua prompt berbeda tercatat dengan versi
                            yang sama.
                        </p>
                        <InputError :message="formAi.errors.versi_prompt_relevansi" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="prompt-relevansi">Prompt relevansi</Label>
                        <textarea
                            id="prompt-relevansi"
                            v-model="formAi.prompt_relevansi"
                            rows="16"
                            class="rounded-md border border-input bg-background px-3 py-2 font-mono text-xs"
                        />
                        <InputError :message="formAi.errors.prompt_relevansi" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="versi-sentimen">Label versi prompt sentimen</Label>
                        <Input id="versi-sentimen" v-model="formAi.versi_prompt_sentimen" placeholder="sentiment-v2" />
                        <InputError :message="formAi.errors.versi_prompt_sentimen" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="prompt-sentimen">Prompt sentimen</Label>
                        <textarea
                            id="prompt-sentimen"
                            v-model="formAi.prompt_sentimen"
                            rows="16"
                            class="rounded-md border border-input bg-background px-3 py-2 font-mono text-xs"
                        />
                        <InputError :message="formAi.errors.prompt_sentimen" />
                    </div>

                    <Button type="submit" :disabled="formAi.processing">Simpan pengaturan</Button>
                </form>
            </CardContent>
        </Card>

        <Card v-for="k in props.kelompok" :key="k.judul">
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">{{ k.judul }}</CardTitle>
                <p v-if="k.catatan" class="text-xs text-muted-foreground">{{ k.catatan }}</p>
            </CardHeader>
            <CardContent class="p-0">
                <ul class="divide-y">
                    <li v-for="n in k.nilai" :key="n.env" class="space-y-1 px-4 py-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="text-sm">{{ n.label }}</p>
                            <span class="angka text-sm font-medium">{{ n.nilai }}</span>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            <code class="text-[11px]">{{ n.env }}</code>
                            <template v-if="n.diukur"> · {{ n.diukur }}</template>
                        </p>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </LayoutAdmin>
</template>
