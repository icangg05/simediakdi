<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useFormatAngka } from '@/composables/useFormatAngka';
import LayoutAdmin from '@/layouts/LayoutAdmin.vue';
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { Loader2, Pause, RefreshCw } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Baris {
    id: number;
    artikel_id: number;
    judul: string;
    media: string | null;
    prioritas: number;
    status: string;
    percobaan: number;
    galat: string | null;
    waktu: string | null;
    nada: 'relevan' | 'tidak_relevan' | 'perlu_review' | null;
    sentimen: 'positif' | 'netral' | 'negatif' | null;
}


const props = defineProps<{
    ringkasan: { menunggu: number; berjalan: number; selesai: number; menyerah: number; total: number };
    prioritas: { nilai: number; label: string; jumlah: number }[];
    laju: { jam: number; hari: number };
    kuota: {
        sisa_hari_ini: number;
        kapasitas_harian: number;
        tersisa: number;
        jeda_detik: number;
        per_hari: number;
        perkiraan_hari: number | null;
    };
    terbaru: Baris[];
    diperbarui: string;
}>();

const { formatAngka } = useFormatAngka();

/**
 * Pembaruan otomatis lewat polling, bukan websocket.
 *
 * Proyek ini belum memasang Reverb maupun Echo, dan menambahkannya berarti satu
 * layanan baru yang harus hidup terus demi satu halaman. Antrean bergerak dalam
 * hitungan detik, bukan milidetik, jadi tarikan tiap lima detik sudah terbaca
 * sebagai langsung oleh mata.
 *
 * `only` wajib. Tanpanya setiap tarikan mengirim ulang seluruh prop halaman
 * termasuk menu dan data pengguna, dua belas kali semenit, selama halamannya
 * dibiarkan terbuka di layar monitor ruangan.
 */
const { start, stop } = usePoll(
    5000,
    { only: ['ringkasan', 'prioritas', 'laju', 'kuota', 'terbaru', 'diperbarui'] },
    { autoStart: true },
);

const berjalanOtomatis = ref(true);

function alihkanOtomatis() {
    berjalanOtomatis.value = !berjalanOtomatis.value;
    berjalanOtomatis.value ? start() : stop();
}

const sedangIsi = ref(false);

function isiSekarang() {
    sedangIsi.value = true;

    router.post('/admin/antrean-ai/isi', {}, {
        preserveScroll: true,
        showProgress: false,
        onFinish: () => (sedangIsi.value = false),
    });
}

const persen = computed(() =>
    props.ringkasan.total === 0
        ? 0
        : Math.round(((props.ringkasan.selesai + props.ringkasan.menyerah) / props.ringkasan.total) * 100),
);

const jam = (nilai: string | null) => (nilai ? format(new Date(nilai), 'HH:mm:ss', { locale: id }) : '-');

const WARNA_STATUS: Record<string, string> = {
    berjalan: 'border-transparent bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    selesai: 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    gagal: 'border-transparent bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
};

const WARNA_NADA: Record<string, string> = {
    relevan: 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    tidak_relevan: 'border-transparent bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
    perlu_review: 'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
};

const LABEL_NADA: Record<string, string> = {
    relevan: 'Relevan',
    tidak_relevan: 'Tidak relevan',
    perlu_review: 'Perlu review',
};

const WARNA_SENTIMEN: Record<string, string> = {
    positif: 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    netral: 'border-transparent bg-slate-200 text-slate-800 dark:bg-slate-800 dark:text-slate-300',
    negatif: 'border-transparent bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
};

const kapital = (teks: string) => teks.charAt(0).toUpperCase() + teks.slice(1);
</script>

<template>
    <Head title="Antrean AI" />

    <LayoutAdmin :breadcrumbs="[{ title: 'Antrean AI', href: '/admin/antrean-ai' }]">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h1 class="text-xl font-semibold">Antrean AI</h1>
                <p class="text-sm text-muted-foreground">
                    Klasifikasi Gemini berjalan sendiri di latar belakang. Halaman ini memperbarui dirinya tiap lima detik.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <!-- Tombol jeda untuk layar yang dibiarkan terbuka berjam-jam.
                     Tarikan tiap lima detik murah, tetapi tidak gratis, dan
                     tidak ada gunanya berjalan saat tidak ada yang menonton. -->
                <button
                    type="button"
                    class="inline-flex h-8 items-center gap-1.5 rounded-md border px-3 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                    :aria-pressed="berjalanOtomatis"
                    @click="alihkanOtomatis"
                >
                    <RefreshCw v-if="berjalanOtomatis" class="size-3.5 animate-spin" style="animation-duration: 3s" />
                    <Pause v-else class="size-3.5" />
                    {{ berjalanOtomatis ? 'Memantau' : 'Dijeda' }}
                </button>

                <Button size="sm" variant="outline" :disabled="sedangIsi" @click="isiSekarang">
                    <Loader2 v-if="sedangIsi" class="size-3.5 animate-spin" />
                    Sisir artikel sekarang
                </Button>
            </div>
        </div>

        <!-- Empat angka besar. Yang dicari admin saat membuka halaman ini
             adalah "masih jalan tidak", dan itu harus terjawab tanpa membaca
             satu baris tabel pun. -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Card>
                <CardContent class="p-4">
                    <p class="text-xs text-muted-foreground">Menunggu</p>
                    <p class="angka text-2xl font-semibold">{{ formatAngka(ringkasan.menunggu) }}</p>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="p-4">
                    <p class="text-xs text-muted-foreground">Sedang dinilai</p>
                    <p class="angka text-2xl font-semibold text-sky-600 dark:text-sky-400">
                        {{ formatAngka(ringkasan.berjalan) }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="p-4">
                    <p class="text-xs text-muted-foreground">Selesai</p>
                    <p class="angka text-2xl font-semibold text-emerald-600 dark:text-emerald-400">
                        {{ formatAngka(ringkasan.selesai) }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="p-4">
                    <p class="text-xs text-muted-foreground">Menyerah setelah 3 percobaan</p>
                    <p class="angka text-2xl font-semibold" :class="ringkasan.menyerah > 0 ? 'text-rose-600 dark:text-rose-400' : ''">
                        {{ formatAngka(ringkasan.menyerah) }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardContent class="space-y-3 p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-sm font-semibold">Kemajuan</h2>
                    <p class="text-xs text-muted-foreground">
                        <span class="angka">{{ formatAngka(persen) }}%</span> dari
                        <span class="angka">{{ formatAngka(ringkasan.total) }}</span> artikel
                    </p>
                </div>

                <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                    <div class="h-full rounded-full bg-emerald-500 transition-all duration-500" :style="{ width: `${persen}%` }" />
                </div>

                <!-- Urutan prioritas dibaca dari atas ke bawah, sama dengan
                     urutan pengerjaannya. Yang di bawah tidak akan tersentuh
                     sebelum yang di atas habis. -->
                <dl class="space-y-1 pt-1 text-sm">
                    <div v-for="p in prioritas" :key="p.nilai" class="flex items-center justify-between gap-2">
                        <dt class="text-muted-foreground">
                            <span class="mr-1.5 inline-flex size-5 items-center justify-center rounded bg-muted text-xs font-medium">
                                {{ p.nilai }}
                            </span>
                            {{ p.label }}
                        </dt>
                        <dd class="angka font-medium">{{ formatAngka(p.jumlah) }}</dd>
                    </div>
                </dl>
            </CardContent>
        </Card>

        <div class="grid gap-3 sm:grid-cols-2">
            <Card>
                <CardContent class="space-y-1 p-4 text-sm">
                    <h2 class="text-sm font-semibold">Laju</h2>
                    <p class="text-muted-foreground">
                        <span class="angka font-medium text-foreground">{{ formatAngka(laju.jam) }}</span> artikel sejam terakhir
                    </p>
                    <p class="text-muted-foreground">
                        <span class="angka font-medium text-foreground">{{ formatAngka(laju.hari) }}</span> artikel sehari terakhir
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardContent class="space-y-1 p-4 text-sm">
                    <h2 class="text-sm font-semibold">Kuota Gemini</h2>
                    <p class="text-muted-foreground">
                        Sisa hari ini
                        <span class="angka font-medium text-foreground">{{ formatAngka(kuota.sisa_hari_ini) }}</span>
                        dari <span class="angka">{{ formatAngka(kuota.kapasitas_harian) }}</span> permintaan
                    </p>
                    <p class="text-muted-foreground">
                        Jarak antar artikel
                        <span class="angka font-medium text-foreground">{{ formatAngka(kuota.jeda_detik) }}</span> detik,
                        kira-kira <span class="angka">{{ formatAngka(kuota.per_hari) }}</span> artikel sehari
                    </p>
                    <!-- Perkiraan dihitung dari kapasitas dan jeda, bukan dari
                         laju sejam terakhir. Laju sejam terakhir jatuh ke nol
                         setiap kali kuota habis, dan perkiraan yang berbunyi
                         "tidak akan pernah selesai" setiap sore tidak menolong
                         siapa pun. -->
                    <p v-if="kuota.perkiraan_hari !== null && kuota.tersisa > 0" class="text-muted-foreground">
                        Sisa antrean kira-kira
                        <span class="angka font-medium text-foreground">{{ formatAngka(kuota.perkiraan_hari) }}</span> hari lagi
                    </p>
                    <p v-else-if="kuota.kapasitas_harian === 0" class="text-rose-600 dark:text-rose-400">
                        Tidak ada kunci Gemini yang menyala. Antrean tidak akan bergerak.
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardContent class="p-0">
                <div class="flex items-center justify-between p-4 pb-2">
                    <h2 class="text-sm font-semibold">Pekerjaan terakhir</h2>
                    <p class="text-xs text-muted-foreground">Diperbarui {{ jam(diperbarui) }}</p>
                </div>

                <p v-if="terbaru.length === 0" class="p-4 pt-0 text-sm text-muted-foreground">
                    Belum ada pekerjaan yang berjalan. Tekan Sisir artikel sekarang kalau antreannya masih kosong.
                </p>

                <div v-else class="divide-y border-t">
                    <div v-for="b in terbaru" :key="b.id" class="flex flex-wrap items-start gap-2 p-3 text-sm">
                        <span class="angka w-16 shrink-0 text-xs text-muted-foreground">{{ jam(b.waktu) }}</span>

                        <div class="min-w-0 flex-1 space-y-1">
                            <Link :href="`/admin/artikel/${b.artikel_id}`" class="font-medium hover:underline">
                                {{ b.judul }}
                            </Link>

                            <div class="flex flex-wrap items-center gap-1.5">
                                <Badge variant="outline" :class="WARNA_STATUS[b.status] ?? ''">
                                    {{ b.status === 'berjalan' ? 'Sedang dinilai' : kapital(b.status) }}
                                </Badge>

                                <Badge v-if="b.nada" variant="outline" :class="WARNA_NADA[b.nada]">
                                    {{ LABEL_NADA[b.nada] }}
                                </Badge>

                                <Badge v-if="b.sentimen" variant="outline" :class="WARNA_SENTIMEN[b.sentimen]">
                                    {{ kapital(b.sentimen) }}
                                </Badge>

                                <span class="text-xs text-muted-foreground">{{ b.media ?? '-' }}</span>

                                <!-- Nomor prioritas ikut ditampilkan supaya
                                     terlihat bahwa antrean benar-benar
                                     mengerjakan yang di atas lebih dulu. -->
                                <span class="text-xs text-muted-foreground">prioritas {{ b.prioritas }}</span>
                            </div>

                            <p v-if="b.galat" class="text-xs text-rose-600 dark:text-rose-400">
                                Percobaan ke-{{ b.percobaan }}: {{ b.galat }}
                            </p>
                        </div>

                    </div>
                </div>
            </CardContent>
        </Card>

    </LayoutAdmin>
</template>
