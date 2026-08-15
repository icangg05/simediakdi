<script setup lang="ts">
defineProps<{ tanpaPadding?: boolean }>();
</script>

<template>
    <div
        class="rounded-xl bg-background text-foreground shadow-lg ring-1 shadow-brand/30 ring-white/25"
        :class="tanpaPadding ? 'p-0' : 'p-1'"
    >
        <slot />
    </div>
</template>
