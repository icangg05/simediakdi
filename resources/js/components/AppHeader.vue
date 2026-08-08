<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import {
    NavigationMenu,
    NavigationMenuItem,
    NavigationMenuLink,
    NavigationMenuList,
    navigationMenuTriggerStyle,
} from '@/components/ui/navigation-menu';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { getInitials } from '@/composables/useInitials';
import { hrefAktif, navPerPeran } from '@/nav';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Menu } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Navigasi mendatar untuk panel eksekutif.
 *
 * Dipakai menggantikan sidebar hanya di halaman walikota. Menunya lima dan
 * dilihat di layar besar saat presentasi, dan lebar yang dimakan sidebar lebih
 * berguna untuk grafik. Admin dengan dua belas menu tetap memakai sidebar.
 *
 * Daftar menunya dibaca dari `@/nav`, sumber yang sama dengan sidebar.
 */
const page = usePage<SharedData>();
const auth = computed(() => page.props.auth);

const menuTerbuka = ref(false);

const mainNavItems = computed(() => navPerPeran[auth.value.user?.peran] ?? []);
const beranda = computed(() => mainNavItems.value[0]?.href ?? '/dashboard');
const aktif = computed(() => hrefAktif(mainNavItems.value, page.url));
</script>

<template>
    <div class="border-b border-sidebar-border/80">
        <div class="mx-auto flex h-16 max-w-screen-2xl items-center gap-2 px-4 md:px-6">
            <!-- Menu ponsel. Lima menu tidak muat mendatar di layar sempit. -->
            <Sheet v-model:open="menuTerbuka">
                <SheetTrigger :as-child="true">
                    <Button variant="ghost" size="icon" class="h-9 w-9 lg:hidden">
                        <Menu class="h-5 w-5" />
                        <span class="sr-only">Buka menu</span>
                    </Button>
                </SheetTrigger>
                <SheetContent side="left" class="w-[300px] p-6">
                    <SheetHeader class="text-left">
                        <SheetTitle class="flex items-center gap-2">
                            <AppLogoIcon class="size-6 fill-current" />
                            Menu
                        </SheetTitle>
                    </SheetHeader>
                    <nav class="-mx-3 mt-6 space-y-1">
                        <Link
                            v-for="item in mainNavItems"
                            :key="item.title"
                            :href="item.href"
                            class="flex items-center gap-x-3 rounded-lg px-3 py-2 text-sm font-medium hover:bg-accent"
                            :class="item.href === aktif ? 'bg-accent text-accent-foreground' : ''"
                            @click="menuTerbuka = false"
                        >
                            <component :is="item.icon" v-if="item.icon" class="h-5 w-5" />
                            {{ item.title }}
                        </Link>
                    </nav>
                </SheetContent>
            </Sheet>

            <Link :href="beranda" class="flex items-center gap-x-2">
                <AppLogo class="h-6" />
            </Link>

            <div class="hidden h-full lg:flex lg:flex-1">
                <NavigationMenu class="ml-8 flex h-full items-stretch">
                    <NavigationMenuList class="flex h-full items-stretch space-x-1">
                        <NavigationMenuItem v-for="item in mainNavItems" :key="item.title" class="relative flex h-full items-center">
                            <Link :href="item.href">
                                <NavigationMenuLink
                                    :class="[
                                        navigationMenuTriggerStyle(),
                                        'h-9 cursor-pointer px-3',
                                        item.href === aktif ? 'text-foreground' : 'text-muted-foreground',
                                    ]"
                                >
                                    <component :is="item.icon" v-if="item.icon" class="mr-2 h-4 w-4" />
                                    {{ item.title }}
                                </NavigationMenuLink>
                            </Link>
                            <!--
                                Garis penanda hanya di bawah menu yang aktif.
                                Sebelumnya dirender untuk setiap item, sehingga
                                seluruh menu tampak aktif sekaligus.
                            -->
                            <div v-if="item.href === aktif" class="absolute bottom-0 left-0 h-0.5 w-full translate-y-px bg-foreground"></div>
                        </NavigationMenuItem>
                    </NavigationMenuList>
                </NavigationMenu>
            </div>

            <div class="ml-auto flex items-center">
                <DropdownMenu>
                    <DropdownMenuTrigger :as-child="true">
                        <Button
                            variant="ghost"
                            size="icon"
                            class="relative size-10 w-auto rounded-full p-1 focus-within:ring-2 focus-within:ring-primary"
                        >
                            <Avatar class="size-8 overflow-hidden rounded-full">
                                <AvatarImage :src="auth.user.avatar" :alt="auth.user.name" />
                                <AvatarFallback class="rounded-lg bg-neutral-200 font-semibold text-black dark:bg-neutral-700 dark:text-white">
                                    {{ getInitials(auth.user?.name) }}
                                </AvatarFallback>
                            </Avatar>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <UserMenuContent :user="auth.user" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    </div>
</template>
