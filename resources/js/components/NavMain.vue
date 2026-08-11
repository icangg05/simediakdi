<script setup lang="ts">
import { SidebarGroup, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { hrefAktif } from '@/nav';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    items: NavItem[];
}>();

const page = usePage<SharedData>();

const aktif = computed(() => hrefAktif(props.items, page.url));
</script>

<template>
    <!--
        Tanpa label grup. "Platform" adalah teks bawaan starter kit dan tidak
        menerangkan apa pun: daftar di bawahnya sudah satu-satunya daftar di
        sidebar, jadi judul yang menamainya hanya menambah baris yang dibaca
        sekali lalu diabaikan selamanya.
    -->
    <SidebarGroup class="px-2 py-0">
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton as-child :is-active="item.href === aktif">
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
