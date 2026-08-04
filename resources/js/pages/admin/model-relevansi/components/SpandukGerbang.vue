<script setup lang="ts">
import { AlertTriangle, CheckCircle2 } from 'lucide-vue-next';

defineProps<{
    gerbang: { status: string; label: string; alasan: string | null };
}>();
</script>

<template>
    <!--
        Tidak bisa ditutup selama gerbang belum lulus. Spanduk yang bisa
        ditutup akan ditutup pada hari kedua, lalu keadaan yang dijelaskannya
        berhenti terlihat sama sekali padahal justru sedang berlaku.
    -->
    <div v-if="gerbang.status !== 'passed'" class="rounded-md border-l-4 border-sentimen-negatif bg-sentimen-negatif-lembut p-4" role="status">
        <div class="flex items-start gap-3">
            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-sentimen-negatif" aria-hidden="true" />
            <div class="space-y-1">
                <p class="text-sm font-semibold">Analisis sentimen diblokir. Gerbang relevansi: {{ gerbang.label.toUpperCase() }}</p>
                <p class="text-xs leading-relaxed text-muted-foreground">
                    Model relevansi belum memenuhi standar produksi. Selesaikan pelabelan, pelatihan, dan evaluasi relevansi terlebih dahulu.
                </p>
                <p v-if="gerbang.alasan" class="text-xs leading-relaxed">{{ gerbang.alasan }}</p>
            </div>
        </div>
    </div>

    <div v-else class="rounded-md border-l-4 border-sentimen-positif bg-sentimen-positif-lembut p-4" role="status">
        <div class="flex items-start gap-3">
            <CheckCircle2 class="mt-0.5 h-5 w-5 shrink-0 text-sentimen-positif" aria-hidden="true" />
            <p class="text-sm font-semibold">Gerbang relevansi: LULUS</p>
        </div>
    </div>
</template>
