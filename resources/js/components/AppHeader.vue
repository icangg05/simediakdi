<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { getInitials } from '@/composables/useInitials';
import { hrefAktif, navPerPeran } from '@/nav';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown, Menu } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Navigasi mendatar untuk panel eksekutif.
 *
 * Dipakai menggantikan sidebar hanya di halaman walikota. Menunya lima dan
 * dilihat di layar besar saat presentasi, dan lebar yang dimakan sidebar lebih
 * berguna untuk grafik. Admin dengan dua belas menu tetap memakai sidebar.
 *
 * Daftar menunya dibaca dari `@/nav`, sumber yang sama dengan sidebar.
 *
 * Kopnya navy merek, bukan putih. Halaman ini dibuka pimpinan daerah dan sering
 * ditampilkan di ruang rapat, dan pita berwarna di paling atas menyatakan ini
 * panel resmi Pemerintah Kota sebelum satu angka pun dibaca. Warna navy juga
 * memberi tepi atas yang tegas untuk latar bertinta di bawahnya.
 */
const page = usePage<SharedData>();
const auth = computed(() => page.props.auth);

const menuTerbuka = ref(false);

const mainNavItems = computed(() => navPerPeran[auth.value.user?.peran] ?? []);
const beranda = computed(() => mainNavItems.value[0]?.href ?? '/dashboard');
const aktif = computed(() => hrefAktif(mainNavItems.value, page.url));
</script>

<template>
    <div class="bg-brand text-white">
        <div class="mx-auto flex h-16 w-full max-w-[1400px] items-center gap-2 px-4 md:px-6">
            <!-- Menu ponsel. Lima menu tidak muat mendatar di layar sempit. -->
            <Sheet v-model:open="menuTerbuka">
                <SheetTrigger :as-child="true">
                    <Button variant="ghost" size="icon" class="h-9 w-9 text-white hover:bg-white/15 hover:text-white lg:hidden">
                        <Menu class="h-5 w-5" />
                        <span class="sr-only">Buka menu</span>
                    </Button>
                </SheetTrigger>
                <SheetContent side="left" class="w-[300px] p-6">
                    <SheetHeader class="text-left">
                        <SheetTitle class="flex items-center gap-2">
                            <img src="/img/Lambang_Kota_Kendari.webp" alt="" class="size-6 object-contain" width="24" height="24" />
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

            <Link :href="beranda" class="flex min-w-0 items-center">
                <AppLogo />
            </Link>

            <!--
                Menu sebagai pil, bukan tab bergaris bawah. Di atas bidang navy
                pekat, garis bawah tipis nyaris tidak terlihat dari jarak ruang
                rapat, sedangkan pil putih penuh terbaca dari belakang ruangan.
            -->
            <nav class="ml-6 hidden items-center gap-1 lg:flex" aria-label="Menu utama">
                <Link
                    v-for="item in mainNavItems"
                    :key="item.title"
                    :href="item.href"
                    :aria-current="item.href === aktif ? 'page' : undefined"
                    class="tekan flex items-center gap-2 rounded-full px-3.5 py-2 text-sm font-medium"
                    :class="item.href === aktif ? 'bg-white text-brand' : 'text-white/75 hover:bg-white/10 hover:text-white'"
                >
                    <component :is="item.icon" v-if="item.icon" class="h-4 w-4" aria-hidden="true" />
                    {{ item.title }}
                </Link>
            </nav>

            <div class="ml-auto flex items-center">
                <DropdownMenu>
                    <DropdownMenuTrigger :as-child="true">
                        <Button
                            variant="ghost"
                            class="tekan h-auto gap-2 rounded-full py-1 pl-1 pr-2 text-white hover:bg-white/15 hover:text-white focus-visible:ring-white"
                        >
                            <Avatar class="size-8 overflow-hidden rounded-full">
                                <AvatarImage :src="auth.user.avatar" :alt="auth.user.name" />
                                <AvatarFallback class="rounded-full bg-white/20 text-xs font-semibold text-white">
                                    {{ getInitials(auth.user?.name) }}
                                </AvatarFallback>
                            </Avatar>
                            <ChevronDown class="h-4 w-4 opacity-70" aria-hidden="true" />
                            <span class="sr-only">Buka menu akun</span>
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
