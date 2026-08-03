<script setup lang="ts">
defineProps<{
    daftar: Array<{ id: number; nama: string; utama: boolean }>;
    terpilih: number | null;
}>();

const emit = defineEmits<{ ubah: [id: number] }>();
</script>

<template>
    <select
        class="h-9 rounded-md border border-input bg-background px-2 text-sm"
        :value="terpilih ?? ''"
        aria-label="Konteks pantauan"
        @change="emit('ubah', Number(($event.target as HTMLSelectElement).value))"
    >
        <option v-for="k in daftar" :key="k.id" :value="k.id">
            {{ k.nama }}{{ k.utama ? ' (utama)' : '' }}
        </option>
    </select>
</template>
