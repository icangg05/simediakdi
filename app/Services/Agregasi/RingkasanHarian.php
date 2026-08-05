<?php

namespace App\Services\Agregasi;

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
        $sql = <<<'SQL'
        INSERT INTO ringkasan_harian (
            tanggal, media_id,
            jumlah_artikel, jumlah_salinan,
            jumlah_negatif, jumlah_netral, jumlah_positif, jumlah_perlu_review,
            dihitung_at
        )
        SELECT
            ?::date,
            a.media_id,
            count(DISTINCT a.id) FILTER (WHERE a.status_dedup = 'asli'),
            count(DISTINCT a.id) FILTER (WHERE a.status_dedup = 'salinan'),
            count(*) FILTER (WHERE s.label_efektif = 'negatif'),
            count(*) FILTER (WHERE s.label_efektif = 'netral'),
            count(*) FILTER (WHERE s.label_efektif = 'positif'),
            count(*) FILTER (WHERE s.perlu_review),
            now()
        FROM artikel a
        LEFT JOIN analisis_sentimen s
               ON s.artikel_id = a.id AND s.relevan = true
        WHERE a.diambil_at >= ? AND a.diambil_at <= ?
        GROUP BY GROUPING SETS (
            (),
            (a.media_id)
        )
        HAVING NOT (GROUPING(a.media_id) = 0 AND a.media_id IS NULL)
        ON CONFLICT (tanggal, media_id) DO UPDATE SET
            jumlah_artikel = EXCLUDED.jumlah_artikel,
            jumlah_salinan = EXCLUDED.jumlah_salinan,
            jumlah_negatif = EXCLUDED.jumlah_negatif,
            jumlah_netral = EXCLUDED.jumlah_netral,
            jumlah_positif = EXCLUDED.jumlah_positif,
            jumlah_perlu_review = EXCLUDED.jumlah_perlu_review,
            dihitung_at = EXCLUDED.dihitung_at
        SQL;

        return DB::affectingStatement($sql, [$tanggalWita, $mulai, $akhir]);
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
