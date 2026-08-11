<script setup lang="ts">
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { formatDistanceToNow } from 'date-fns';
import { id } from 'date-fns/locale';
import { AlertTriangle, Plus, Send } from 'lucide-vue-next';

interface Aturan {
    id: number;
    nama: string;
    jenis: string;
    kanal: string;
    aktif: boolean;
    jendela_jam: number;
    jeda_minimal_jam: number;
    riwayat_count: number;
    dipicu_terakhir_at: string | null;
}

interface Riwayat {
    id: number;
    ringkasan: string;
    status_kirim: string;
    pesan_error: string | null;
    aturan: string | null;
    dipicu_at: string;
}

const props = defineProps<{
    aturan: Aturan[];
    riwayat: Riwayat[];
    telegramSiap: boolean;
}>();

const labelJenis: Record<string, string> = {
    lonjakan_negatif: 'Lonjakan negatif',
    kata_kunci_muncul: 'Kata kunci muncul',
    sumber_mati: 'Sumber feed mati',
};

const sejak = (n: string) => formatDistanceToNow(new Date(n), { addSuffix: true, locale: id });

const uji = (a: Aturan) => router.post(`/admin/alert/${a.id}/uji`, {}, { preserveScroll: true });
const hapus = (a: Aturan) => {
    if (confirm(`Hapus aturan ${a.nama}? Riwayat pengirimannya ikut terhapus.`)) {
        router.delete(`/admin/alert/${a.id}`);
    }
};
</script>

<template>
    <Head title="Alert" />

    <LayoutAdmin judul="Alert" :breadcrumbs="[{ title: 'Alert', href: '/admin/alert' }]">
        <!-- Telegram belum terkonfigurasi adalah kegagalan diam: aturan
             terpicu benar dan tidak seorang pun menerima apa pun. -->
        <div v-if="!props.telegramSiap" class="flex items-start gap-2 rounded-md border border-amber-500/40 bg-amber-500/5 p-3 text-sm">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
            <div>
                <p class="font-medium">Telegram belum terkonfigurasi.</p>
                <p class="text-muted-foreground">
                    Aturan di bawah akan tetap dinilai dan tercatat di riwayat, tapi pesannya tidak sampai ke mana pun. Isi
                    <code class="text-xs">TELEGRAM_BOT_TOKEN</code> dan <code class="text-xs">TELEGRAM_CHAT_ID</code> di
                    <code class="text-xs">.env</code>, lalu uji lewat tombol di bawah.
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Button as-child size="sm">
                <Link href="/admin/alert/create">
                    <Plus class="mr-1.5 h-4 w-4" />
                    Tambah aturan
                </Link>
            </Button>
            <Button size="sm" variant="outline" @click="router.post('/admin/alert/uji-telegram', {}, { preserveScroll: true })">
                <Send class="mr-1.5 h-4 w-4" />
                Kirim pesan uji ke Telegram
            </Button>
        </div>

        <Card>
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">Aturan</CardTitle>
            </CardHeader>
            <CardContent class="p-0">
                <KeadaanKosong
                    v-if="!props.aturan.length"
                    judul="Belum ada aturan alert"
                    keterangan="Mulai dari satu aturan lonjakan negatif. Aturan yang terlalu banyak sejak awal membuat grup Telegram ramai dan orang berhenti membacanya."
                />
                <ul v-else class="divide-y">
                    <li v-for="a in props.aturan" :key="a.id" class="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
                        <div class="min-w-0 space-y-0.5">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-medium">{{ a.nama }}</p>
                                <Badge v-if="!a.aktif" variant="secondary">nonaktif</Badge>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{ labelJenis[a.jenis] ?? a.jenis }}
                                · jendela {{ a.jendela_jam }} jam · jeda kirim {{ a.jeda_minimal_jam }} jam
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ a.riwayat_count }} kali terkirim
                                <template v-if="a.dipicu_terakhir_at"> · terakhir {{ sejak(a.dipicu_terakhir_at) }}</template>
                            </p>
                        </div>
                        <div class="flex shrink-0 gap-1.5">
                            <Button size="sm" variant="outline" class="h-7" @click="uji(a)">Uji</Button>
                            <Button as-child size="sm" variant="ghost" class="h-7">
                                <Link :href="`/admin/alert/${a.id}/edit`">Ubah</Link>
                            </Button>
                            <Button size="sm" variant="ghost" class="h-7 text-destructive" @click="hapus(a)">Hapus</Button>
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="pb-2">
                <CardTitle class="text-sm font-medium">Riwayat pengiriman</CardTitle>
            </CardHeader>
            <CardContent class="p-0">
                <KeadaanKosong
                    v-if="!props.riwayat.length"
                    judul="Belum ada alert yang terkirim"
                    keterangan="Riwayat terisi sendiri saat aturan terpicu. Pengiriman yang gagal juga tercatat di sini beserta sebabnya."
                />
                <ul v-else class="divide-y">
                    <li v-for="r in props.riwayat" :key="r.id" class="space-y-0.5 px-4 py-2.5">
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-sm">{{ r.ringkasan }}</p>
                            <Badge :variant="r.status_kirim === 'terkirim' ? 'outline' : 'destructive'" class="shrink-0">
                                {{ r.status_kirim }}
                            </Badge>
                        </div>
                        <p class="text-xs text-muted-foreground">{{ r.aturan }} · {{ sejak(r.dipicu_at) }}</p>
                        <p v-if="r.pesan_error" class="text-xs text-destructive">{{ r.pesan_error }}</p>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </LayoutAdmin>
</template>
