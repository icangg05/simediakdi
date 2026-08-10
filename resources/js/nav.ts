import type { NavItem, PeranPengguna } from '@/types';
import {
    BarChart3,
    BellRing,
    Bot,
    ClipboardCheck,
    FileSignature,
    FileText,
    FlaskConical,
    LayoutGrid,
    Newspaper,
    Rss,
    ScrollText,
    Send,
    Settings,
    Users,
} from 'lucide-vue-next';

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
        { title: 'Kontrak', href: '/admin/kontrak', icon: FileSignature },
        { title: 'Verifikasi Pemuatan', href: '/admin/pemuatan', icon: ClipboardCheck },
        { title: 'Alert', href: '/admin/alert', icon: BellRing },
        { title: 'Media', href: '/admin/media', icon: Newspaper },
        { title: 'Sumber Feed', href: '/admin/sumber-feed', icon: Rss },
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
        { title: 'Kontrak Saya', href: '/portal/kontrak', icon: FileSignature },
        { title: 'Lapor Pemuatan', href: '/portal/lapor', icon: Send },
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
