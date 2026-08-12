<script setup lang="ts">
import { useAppearance } from '@/composables/useAppearance';
import { Monitor, Moon, Sun } from 'lucide-vue-next';

interface Props {
    class?: string;
}

const { class: containerClass = '' } = defineProps<Props>();

const { appearance, updateAppearance } = useAppearance();

/**
 * Label berbahasa Indonesia, dan warnanya diambil dari token, bukan palet
 * Tailwind mentah.
 *
 * Versi bawaan starter kit memakai `neutral-100` sampai `neutral-700` yang
 * ditulis tangan untuk kedua mode. Nilainya tidak pernah sama dengan `--muted`
 * dan `--background` yang dipakai seluruh jalur tab lain di aplikasi ini, jadi
 * kelompok tab di halaman Tampilan terlihat sedikit berbeda dari kelompok tab
 * di halaman Berita dan Antrean AI tanpa alasan.
 */
const tabs = [
    { value: 'light', Icon: Sun, label: 'Terang' },
    { value: 'dark', Icon: Moon, label: 'Gelap' },
    { value: 'system', Icon: Monitor, label: 'Sistem' },
] as const;
</script>

<template>
    <div role="radiogroup" aria-label="Tema tampilan" :class="['inline-flex gap-1 rounded-lg bg-muted p-1', containerClass]">
        <button
            v-for="{ value, Icon, label } in tabs"
            :key="value"
            type="button"
            role="radio"
            :aria-checked="appearance === value"
            :class="[
                'tekan flex items-center gap-1.5 rounded-md px-3.5 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                appearance === value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
            ]"
            @click="updateAppearance(value)"
        >
            <component :is="Icon" class="size-4 shrink-0" aria-hidden="true" />
            {{ label }}
        </button>
    </div>
</template>
