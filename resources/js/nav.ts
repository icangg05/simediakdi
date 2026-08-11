import type { NavItem, PeranPengguna } from '@/types';
import { BarChart3, BellRing, Bot, FileText, FlaskConical, LayoutGrid, Newspaper, ScrollText, Send, Settings, Users } from 'lucide-vue-next';

/**
 * Satu aplikasi, tiga grup route. Navigasi mengikuti peran, bukan sebaliknya.
 *
 * Definisinya ada di sini, bukan di dalam komponen, karena dipakai dua bentuk
 * navigasi sekaligus: sidebar untuk admin dan portal media, header untuk panel
 * eksekutif. Dua salinan daftar menu berarti satu di antaranya cepat atau
 * lambat akan tertinggal.
 */
export const navPerPeran: Record<PeranPengguna, NavItem[]> = {
    superadmin: [
        { title: 'Dashboard', href: '/admin', icon: LayoutGrid },
        { title: 'Artikel', href: '/admin/artikel', icon: FileText },
        { title: 'Antrean AI', href: '/admin/antrean-ai', icon: Bot },
        { title: 'Model Relevansi', href: '/admin/model-relevansi', icon: FlaskConical },
        { title: 'Alert', href: '/admin/alert', icon: BellRing },
        // Sumber Feed tidak lagi jadi menu sendiri. Pengelolaannya pindah ke
        // halaman detail tiap media, tempat alamat feed memang punya arti.
        { title: 'Media', href: '/admin/media', icon: Newspaper },
        { title: 'Pengguna', href: '/admin/pengguna', icon: Users },
        { title: 'Log Crawl', href: '/admin/log-crawl', icon: ScrollText },
        { title: 'Pengaturan', href: '/admin/pengaturan', icon: Settings },
    ],
    walikota: [
        { title: 'Ringkasan', href: '/eksekutif', icon: LayoutGrid },
        { title: 'Sentimen', href: '/eksekutif/sentimen', icon: BarChart3 },
        { title: 'Peringkat Media', href: '/eksekutif/media', icon: Newspaper },
        { title: 'Arsip Berita', href: '/eksekutif/berita', icon: FileText },
    ],
    media: [
        { title: 'Beranda', href: '/portal', icon: LayoutGrid },
        { title: 'Berita Saya', href: '/portal/berita', icon: FileText },
        { title: 'Tambah Berita', href: '/portal/lapor', icon: Send },
    ],
};

/**
 * Menu yang disorot: href terpanjang yang cocok dengan halaman sekarang.
 *
 * Bukan pembandingan persis, karena `/admin/media/create` harus tetap menyorot
 * "Media", dan tabel memakai filter sisi server sehingga `url` kerap membawa
 * query string. Terpanjang yang menang supaya "Dashboard" (`/admin`) dan
 * "Ringkasan" (`/eksekutif`) tidak ikut menyala di setiap halaman grupnya.
 */
export function hrefAktif(items: NavItem[], url: string): string | undefined {
    const jalur = url.split('?')[0];

    return items.filter((item) => jalur === item.href || jalur.startsWith(`${item.href}/`)).sort((a, b) => b.href.length - a.href.length)[0]?.href;
}
