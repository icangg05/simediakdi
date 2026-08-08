<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import { ShieldAlert } from 'lucide-vue-next';

defineProps<{ alasan?: string | null; ringkas?: boolean }>();
</script>

<template>
    <!--
        Menggantikan angka, bukan mendampinginya.

        Godaan terbesar di sini adalah menampilkan angka lama supaya halaman
        tidak terlihat kosong. Angka lama dihitung dari relevansi berpresisi
        69,9%, dan menampilkannya tanpa penanda berarti tetap menyampaikan hal
        yang sudah diketahui salah, hanya dengan tanggal yang lebih tua.
    -->
    <Card>
        <CardContent :class="ringkas ? 'p-3' : 'p-6'">
            <div class="flex items-start gap-3">
                <ShieldAlert class="mt-0.5 shrink-0 text-muted-foreground" :class="ringkas ? 'h-4 w-4' : 'h-6 w-6'" aria-hidden="true" />
                <div class="space-y-1">
                    <p :class="ringkas ? 'text-xs font-medium' : 'text-sm font-semibold'">Analisis sentimen belum tersedia</p>
                    <p class="text-xs leading-relaxed text-muted-foreground">
                        Model penilai relevansi berita sedang disiapkan. Angka sentimen baru ditampilkan setelah pengujiannya memenuhi standar, supaya
                        yang dinilai benar-benar berita tentang Pemerintah Kota Kendari.
                    </p>
                    <p v-if="alasan && !ringkas" class="text-xs leading-relaxed text-muted-foreground">
                        {{ alasan }}
                    </p>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
