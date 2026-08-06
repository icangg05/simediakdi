<?php

use App\Services\Crawler\HasilEkstraksi;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mengganti penanda "(tanpa judul)" dengan judul yang dirakit dari isi artikel.
 *
 * Artikelnya sendiri utuh dan sebagian dinilai relevan, jadi menghapusnya
 * berarti membuang berita yang sah beserta hitungan pemuatan medianya. Yang
 * rusak hanya judulnya.
 *
 * Sumbernya memang tidak punya judul. Halaman aslinya diperiksa satu per satu:
 * `<title>` hanya berisi nama situs, `og:title` sama, dan `<h1>` kosong.
 * WordPress memberi pos seperti ini slug cadangan seperti `4284-2`, dan itu
 * yang menjelaskan bentuk URL-nya. Tidak ada tempat lain untuk mengambil
 * judulnya selain isi beritanya sendiri.
 *
 * Dicari lewat nilai kolomnya, bukan lewat daftar id. Id hanya berlaku di satu
 * database, sedangkan salinan di lingkungan lain membawa baris yang sama dengan
 * id yang berbeda.
 *
 * ponytail: memanggil HasilEkstraksi dari dalam migration. Berkas ini sekali
 * jalan dan sudah berjalan di semua lingkungan sebelum kelas itu sempat
 * berubah. Kalau suatu saat kelasnya dihapus, kosongkan isi up() ini alih-alih
 * menyalin heuristiknya ke sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        $baris = DB::table('artikel')
            ->where('judul', HasilEkstraksi::TANPA_JUDUL)
            ->whereNotNull('isi')
            ->get(['id', 'isi']);

        foreach ($baris as $satu) {
            $judul = HasilEkstraksi::judulDariIsi($satu->isi);

            // Isi yang tidak menyisakan kalimat apa pun dibiarkan memakai
            // penandanya. Penanda yang jujur lebih baik daripada judul karangan
            // yang terbaca seolah datang dari redaksinya.
            if ($judul === null) {
                continue;
            }

            DB::table('artikel')->where('id', $satu->id)->update(['judul' => $judul]);
        }
    }

    /** Judul lamanya hanya penanda, tidak ada yang layak dikembalikan. */
    public function down(): void {}
};
