<script setup lang="ts">
import KopHalaman from '@/components/domain/KopHalaman.vue';
import PilKop from '@/components/domain/PilKop.vue';
import KeadaanKosong from '@/components/KeadaanKosong.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { formatDistanceToNow } from 'date-fns';
import { id } from 'date-fns/locale';
import {
    Activity,
    BellRing,
    CircleCheck,
    CircleX,
    History,
    Pencil,
    Plus,
    RadioTower,
    Search,
    Send,
    Siren,
    Trash2,
    TriangleAlert,
    Zap,
} from 'lucide-vue-next';
import { computed, type Component } from 'vue';

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

/**
 * Empat jenis aturan, masing-masing dengan rona dan ikonnya.
 *
 * Dua yang memantau nada negatif memakai merah, karena yang dipantaunya
 * betul-betul nada negatif pemberitaan. Dua yang lain tidak: kata kunci muncul
 * adalah pengamatan, bukan penilaian, jadi ia biru. Sumber mati adalah keadaan
 * sistem yang perlu diperiksa, jadi ia kuning, sama dengan seluruh keadaan
 * "menunggu diperiksa" di panel admin.
 */
const JENIS: Record<string, { label: string; kelas: string; ikon: Component }> = {
    berita_negatif: { label: 'Berita negatif seketika', kelas: 'bg-sentimen-negatif-lembut text-sentimen-negatif', ikon: Zap },
    lonjakan_negatif: { label: 'Lonjakan negatif', kelas: 'bg-sentimen-negatif-lembut text-sentimen-negatif', ikon: Siren },
    kata_kunci_muncul: { label: 'Kata kunci muncul', kelas: 'bg-aksen-biru/10 text-aksen-biru', ikon: Search },
    sumber_mati: { label: 'Sumber feed mati', kelas: 'bg-sentimen-review-lembut text-sentimen-review', ikon: RadioTower },
};

const sejak = (n: string) => formatDistanceToNow(new Date(n), { addSuffix: true, locale: id });

const uji = (a: Aturan) => router.post(`/admin/alert/${a.id}/uji`, {}, { preserveScroll: true });

const hapus = (a: Aturan) => {
    if (confirm(`Hapus aturan ${a.nama}? Riwayat pengirimannya ikut terhapus.`)) {
        router.delete(`/admin/alert/${a.id}`);
    }
};

const jumlahAktif = computed(() => props.aturan.filter((a) => a.aktif).length);

const jumlahGagal = computed(() => props.riwayat.filter((r) => r.status_kirim !== 'terkirim').length);
</script>

<template>
    <Head title="Alert" />

    <LayoutAdmin :breadcrumbs="[{ title: 'Alert', href: '/admin/alert' }]">
        <KopHalaman
            judul="Alert"
            keterangan="Aturan yang mengawasi arsip berita dan mengirim pesan ke Telegram saat kondisinya terpenuhi. Berita negatif dikirim seketika setelah dinilai, aturan lain dinilai berkala tiap 15 menit."
        >
            <template #aksi>
                <Link
                    href="/admin/alert/create"
                    class="tekan inline-flex items-center gap-2 rounded-lg bg-white px-3.5 py-2 text-xs font-semibold text-brand transition-colors hover:bg-white/90 focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-brand focus-visible:outline-hidden"
                >
                    <Plus class="size-3.5" aria-hidden="true" />
                    Tambah aturan
                </Link>

                <!-- Mengirim notifikasi sungguhan ke grup Telegram, berisi
                     berita negatif terakhir di arsip, jadi ia tidak mendapat
                     bidang putih penuh yang berarti aksi utama. -->
                <button
                    type="button"
                    class="tekan inline-flex items-center gap-2 rounded-lg bg-white/10 px-3.5 py-2 text-xs font-medium text-white ring-1 ring-white/25 transition-colors ring-inset hover:bg-white/20 focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-brand focus-visible:outline-hidden"
                    @click="router.post('/admin/alert/uji-telegram', {}, { preserveScroll: true })"
                >
                    <Send class="size-3.5" aria-hidden="true" />
                    Kirim notifikasi uji
                </button>
            </template>

            <PilKop :nada="telegramSiap ? 'baik' : 'buruk'" :ikon="telegramSiap ? CircleCheck : TriangleAlert">
                Telegram {{ telegramSiap ? 'siap' : 'belum terkonfigurasi' }}
            </PilKop>
            <PilKop :nada="jumlahAktif > 0 ? 'netral' : 'tunggu'" :ikon="BellRing">
                <span class="angka">{{ jumlahAktif }}</span> dari <span class="angka">{{ aturan.length }}</span> aturan aktif
            </PilKop>
            <PilKop v-if="jumlahGagal > 0" nada="buruk" :ikon="CircleX">
                <span class="angka">{{ jumlahGagal }}</span> pengiriman gagal di riwayat
            </PilKop>
        </KopHalaman>

        <!-- Telegram belum terkonfigurasi adalah kegagalan diam: aturan
             terpicu benar dan tidak seorang pun menerima apa pun. Kuning, bukan
             merah: tidak ada yang rusak, hanya belum diisi. -->
        <div
            v-if="!props.telegramSiap"
            class="muncul flex items-start gap-3 rounded-xl bg-sentimen-review-lembut p-4 text-sm text-sentimen-review ring-1 ring-sentimen-review/25 ring-inset"
            style="animation-delay: 60ms"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <div class="min-w-0 space-y-1">
                <p class="font-medium">Telegram belum terkonfigurasi.</p>
                <p class="max-w-[80ch] opacity-90">
                    Aturan di bawah akan tetap dinilai dan tercatat di riwayat, tapi pesannya tidak sampai ke mana pun. Isi token bot dan chat ID di
                    <Link href="/admin/pengaturan" class="font-medium underline underline-offset-2">Pengaturan sistem</Link>, lalu uji lewat tombol di
                    kop.
                </p>
            </div>
        </div>

        <Card class="muncul overflow-hidden" style="animation-delay: 100ms">
            <CardHeader class="flex-row items-center justify-between gap-2 space-y-0 border-b py-3">
                <CardTitle class="flex items-center gap-2 text-sm font-semibold">
                    <span class="grid size-7 place-items-center rounded-md bg-brand-lembut text-brand dark:text-white">
                        <BellRing class="size-4" aria-hidden="true" />
                    </span>
                    Aturan
                </CardTitle>
                <span v-if="aturan.length" class="angka rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">
                    {{ aturan.length }}
                </span>
            </CardHeader>

            <CardContent class="p-0">
                <KeadaanKosong
                    v-if="!props.aturan.length"
                    judul="Belum ada aturan alert"
                    keterangan="Mulai dari satu aturan berita negatif seketika. Aturan yang terlalu banyak sejak awal membuat grup Telegram ramai dan orang berhenti membacanya."
                />

                <!-- Rel bertitik. Titik hijau berarti aturan ini sedang
                     mengawasi, abu berarti ia ada tapi tidak dinilai sama
                     sekali, dan bedanya perlu terlihat sebelum kalimatnya
                     dibaca. -->
                <ul v-else class="divide-y">
                    <li
                        v-for="a in props.aturan"
                        :key="a.id"
                        class="flex flex-wrap items-start justify-between gap-3 px-4 py-3 transition-colors hover:bg-muted/40"
                        :class="a.aktif ? '' : 'opacity-70'"
                    >
                        <div class="flex min-w-0 gap-2.5">
                            <span
                                class="mt-1.5 size-2 shrink-0 rounded-full"
                                :class="a.aktif ? 'bg-sentimen-positif' : 'bg-muted-foreground/40'"
                                aria-hidden="true"
                            />

                            <div class="min-w-0 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-medium">{{ a.nama }}</p>
                                    <span
                                        v-if="JENIS[a.jenis]"
                                        class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-medium"
                                        :class="JENIS[a.jenis].kelas"
                                    >
                                        <component :is="JENIS[a.jenis].ikon" class="size-3 shrink-0" aria-hidden="true" />
                                        {{ JENIS[a.jenis].label }}
                                    </span>
                                    <span v-if="!a.aktif" class="rounded-md bg-muted px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground">
                                        Nonaktif
                                    </span>
                                </div>

                                <p class="angka text-xs text-muted-foreground">
                                    Jendela {{ a.jendela_jam }} jam &middot; jeda kirim {{ a.jeda_minimal_jam }} jam
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    <span class="angka">{{ a.riwayat_count }}</span> kali terkirim
                                    <template v-if="a.dipicu_terakhir_at"> &middot; terakhir {{ sejak(a.dipicu_terakhir_at) }}</template>
                                </p>
                            </div>
                        </div>

                        <!-- Tiga tombol, tiga akibat. Biru memeriksa tanpa
                             mengubah apa pun, netral membuka form, merah
                             menghapus beserta seluruh riwayatnya. Rona yang
                             sama sudah dipakai untuk tiga tombol kunci di
                             halaman Pengaturan. -->
                        <div class="flex shrink-0 gap-1.5">
                            <Button
                                size="sm"
                                variant="outline"
                                class="tekan h-7 border-aksen-biru/40 text-xs text-aksen-biru hover:bg-aksen-biru/10 hover:text-aksen-biru"
                                @click="uji(a)"
                            >
                                <Activity class="size-3.5" aria-hidden="true" />
                                Uji
                            </Button>
                            <Button as-child size="sm" variant="ghost" class="tekan h-7 text-xs">
                                <Link :href="`/admin/alert/${a.id}/edit`">
                                    <Pencil class="size-3.5" aria-hidden="true" />
                                    Ubah
                                </Link>
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                class="tekan h-7 text-xs text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                @click="hapus(a)"
                            >
                                <Trash2 class="size-3.5" aria-hidden="true" />
                                Hapus
                            </Button>
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Card class="muncul overflow-hidden" style="animation-delay: 160ms">
            <CardHeader class="flex-row items-center gap-2 space-y-0 border-b py-3">
                <span class="grid size-7 place-items-center rounded-md bg-muted text-muted-foreground">
                    <History class="size-4" aria-hidden="true" />
                </span>
                <CardTitle class="text-sm font-semibold">Riwayat pengiriman</CardTitle>
            </CardHeader>

            <CardContent class="p-0">
                <KeadaanKosong
                    v-if="!props.riwayat.length"
                    judul="Belum ada alert yang terkirim"
                    keterangan="Riwayat terisi sendiri saat aturan terpicu. Pengiriman yang gagal juga tercatat di sini beserta sebabnya."
                />

                <ul v-else class="divide-y">
                    <li v-for="r in props.riwayat" :key="r.id" class="flex gap-3 px-4 py-3 transition-colors hover:bg-muted/40">
                        <span
                            class="mt-1.5 size-2 shrink-0 rounded-full"
                            :class="r.status_kirim === 'terkirim' ? 'bg-sentimen-positif' : 'bg-sentimen-negatif'"
                            aria-hidden="true"
                        />

                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <p class="min-w-0 text-sm">{{ r.ringkasan }}</p>
                                <span
                                    class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-medium capitalize"
                                    :class="
                                        r.status_kirim === 'terkirim'
                                            ? 'bg-sentimen-positif-lembut text-sentimen-positif'
                                            : 'bg-sentimen-negatif-lembut text-sentimen-negatif'
                                    "
                                >
                                    <component
                                        :is="r.status_kirim === 'terkirim' ? CircleCheck : CircleX"
                                        class="size-3 shrink-0"
                                        aria-hidden="true"
                                    />
                                    {{ r.status_kirim }}
                                </span>
                            </div>
                            <p class="text-xs text-muted-foreground">{{ r.aturan }} &middot; {{ sejak(r.dipicu_at) }}</p>
                            <p v-if="r.pesan_error" class="text-xs wrap-break-word text-sentimen-negatif">{{ r.pesan_error }}</p>
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </LayoutAdmin>
</template>
