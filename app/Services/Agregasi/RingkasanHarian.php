<?php

namespace App\Services\Agregasi;

use App\Models\Artikel;
use App\Support\Waktu;
use Illuminate\Support\Facades\DB;

/**
 * Praperhitungan untuk seluruh grafik.
 *
 * Dashboard eksekutif membaca satu baris per hari tanpa join dan tanpa agregasi
 *, itulah alasan halamannya bisa selesai di bawah dua detik. Menghitung ini
 * saat request berarti membuang alasan tabelnya ada.
 *
 * Arti baris NULL: `media_id` NULL berarti seluruh media digabung. Satu baris
 * lagi per media. Sejak konteks pantauan dihapus, dua dimensi menjadi satu.
 */
class RingkasanHarian
{
    /** @return int jumlah baris yang ditulis */
    public function hitung(string $tanggalWita): int
    {
        $mulai = Waktu::awalHari($tanggalWita);
        $akhir = Waktu::akhirHari($tanggalWita);

        // GROUPING SETS menghasilkan baris total dan baris per media dalam
        // satu pemindaian.
        //
        // HAVING-nya bukan hiasan: GROUPING SETS menandai kolom yang sedang
        // diagregasi dengan NULL, dan `media_id` juga bernilai NULL untuk
        // artikel yang domainnya belum dikenali. Tanpa GROUPING(), baris
        // "seluruh media" dan baris "media tak dikenal" jatuh ke kunci unik
        // yang sama lalu saling menimpa.
        // Tanggal terbit, bukan tanggal unduh. Alasannya di
        // App\Models\Artikel::waktuTerbit().
        $terbit = Artikel::waktuTerbit('a');

        $sql = <<<SQL
        INSERT INTO ringkasan_harian (
            tanggal, media_id,
            jumlah_artikel,
            jumlah_negatif, jumlah_netral, jumlah_positif, jumlah_perlu_review,
            dihitung_at
        )
        SELECT
            ?::date,
            a.media_id,
            count(DISTINCT a.id),
            count(*) FILTER (WHERE s.label_efektif = 'negatif'),
            count(*) FILTER (WHERE s.label_efektif = 'netral'),
            count(*) FILTER (WHERE s.label_efektif = 'positif'),
            count(*) FILTER (WHERE s.perlu_review),
            now()
        FROM artikel a
        LEFT JOIN analisis_sentimen s
               ON s.artikel_id = a.id AND s.relevan = true
        WHERE {$terbit} >= ? AND {$terbit} <= ?
        GROUP BY GROUPING SETS (
            (),
            (a.media_id)
        )
        HAVING NOT (GROUPING(a.media_id) = 0 AND a.media_id IS NULL)
        ON CONFLICT (tanggal, media_id) DO UPDATE SET
            jumlah_artikel = EXCLUDED.jumlah_artikel,
            jumlah_negatif = EXCLUDED.jumlah_negatif,
            jumlah_netral = EXCLUDED.jumlah_netral,
            jumlah_positif = EXCLUDED.jumlah_positif,
            jumlah_perlu_review = EXCLUDED.jumlah_perlu_review,
            dihitung_at = EXCLUDED.dihitung_at
        SQL;

        // Baris lama tanggal ini dihapus dulu, bukan hanya ditimpa.
        //
        // `ON CONFLICT` hanya menyentuh baris yang muncul di hasil SELECT. Kalau
        // artikel berpindah tanggal, berpindah media, atau relevansinya dicabut,
        // baris per media yang lama tidak ikut terhitung ulang dan angkanya
        // membeku di nilai kemarin. Peringkat Media lalu menyebut sebuah media
        // memuat puluhan berita, sedangkan arsip yang dibuka dari baris itu
        // kosong karena artikelnya memang sudah tidak ada di sana.
        return DB::transaction(function () use ($sql, $tanggalWita, $mulai, $akhir) {
            DB::table('ringkasan_harian')->where('tanggal', $tanggalWita)->delete();

            return DB::affectingStatement($sql, [$tanggalWita, $mulai, $akhir]);
        });
    }

    /**
     * Menulis ulang beberapa hari terakhir sekaligus.
     *
     * Sekali sehari rentangnya diperlebar untuk menangkap koreksi label yang
     * baru dilakukan admin belakangan, tanpa itu, angka hari kemarin membeku
     * dengan label model yang sudah diperbaiki manusia.
     *
     * @return array<string, int>
     */
    public function hitungMundur(int $hari): array
    {
        $hasil = [];

        for ($i = 0; $i < $hari; $i++) {
            $tanggal = Waktu::tanggalWita(now()->subDays($i));
            $hasil[$tanggal] = $this->hitung($tanggal);
        }

        return $hasil;
    }
}
