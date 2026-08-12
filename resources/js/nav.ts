import type { NavItem, PeranPengguna } from '@/types';
import {
    BarChart3,
    BellRing,
    Bot,
    DatabaseBackup,
    FileText,
    FlaskConical,
    LayoutGrid,
    Newspaper,
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
    /*
     * Sembilan menu dalam satu daftar datar memaksa admin membaca kesembilannya
     * untuk menemukan satu. Kelompoknya dibagi menurut pertanyaan yang sedang
     * dibawa admin, bukan menurut kemiripan teknis halamannya.
     *
     * "Apa yang terjadi hari ini" dijawab Pemantauan. "Kenapa penilaiannya
     * begitu" dijawab Penilaian AI. "Siapa dan media mana" dijawab Kelola.
     * "Kenapa sistemnya begitu" dijawab Sistem.
     *
     * Dashboard sengaja di luar kelompok mana pun. Ia bukan anggota salah satu
     * dari empat pertanyaan itu, melainkan halaman yang dibuka lebih dulu
     * sebelum pertanyaannya terbentuk.
     */
    superadmin: [
        { title: 'Dashboard', href: '/admin', icon: LayoutGrid },

        { title: 'Artikel', href: '/admin/artikel', icon: FileText, kelompok: 'Pemantauan' },
        { title: 'Alert', href: '/admin/alert', icon: BellRing, kelompok: 'Pemantauan' },

        { title: 'Antrean AI', href: '/admin/antrean-ai', icon: Bot, kelompok: 'Penilaian AI' },
        { title: 'Model Relevansi', href: '/admin/model-relevansi', icon: FlaskConical, kelompok: 'Penilaian AI' },

        // Sumber Feed tidak lagi jadi menu sendiri. Pengelolaannya pindah ke
        // halaman detail tiap media, tempat alamat feed memang punya arti.
        { title: 'Media', href: '/admin/media', icon: Newspaper, kelompok: 'Kelola' },
        { title: 'Pengguna', href: '/admin/pengguna', icon: Users, kelompok: 'Kelola' },

        // Log Crawl masuk Sistem, bukan Pemantauan. Yang dipantau di sini bukan
        // beritanya, melainkan apakah mesin penariknya masih bekerja.
        { title: 'Log Crawl', href: '/admin/log-crawl', icon: ScrollText, kelompok: 'Sistem' },
        // Cadangan masuk Sistem, bukan Kelola. Yang dikerjakan di sana bukan
        // isi datanya, melainkan salinan seluruh basis datanya.
        { title: 'Cadangan', href: '/admin/cadangan', icon: DatabaseBackup, kelompok: 'Sistem' },
        { title: 'Pengaturan', href: '/admin/pengaturan', icon: Settings, kelompok: 'Sistem' },
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
