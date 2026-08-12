<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\LabelSentimen;
use App\Models\AnalisisSentimen;
use App\Models\AturanAlert;
use App\Models\RiwayatAlert;
use App\Services\Alert\PengirimTelegram;
use App\Services\Alert\PesanAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Satu berita negatif, satu pesan, dikirim segera setelah Gemini menilainya.
 *
 * Tidak lewat `alert:periksa`. Penilaian berkala menambah jeda sampai 15 menit
 * pada kabar yang paling mahal terlambat, dan berita buruk yang baru diketahui
 * humas setelah menyebar di grup lain sudah kehilangan gunanya.
 *
 * Satu pesan per artikel, bukan satu pesan berisi beberapa. Berita yang datang
 * berturut-turut memang terkirim berturut-turut, dan itu disengaja: pesan yang
 * memuat lima judul sekaligus dibaca sebagai satu peristiwa, padahal isinya
 * lima peristiwa berbeda yang masing-masing punya penanggung jawab sendiri.
 *
 * Dijalankan di antrean, bukan di dalam permintaan yang memicunya. Telegram
 * bisa lambat atau menolak, dan tombol Klasifikasi di layar admin tidak boleh
 * ikut menunggu jawaban dari server lain.
 */
class KirimAlertBeritaNegatif implements ShouldQueue
{
    use Queueable;

    /**
     * Tiga percobaan, lalu menyerah dan meninggalkan jejak gagal di riwayat.
     *
     * Alert yang mencoba selamanya akan tetap terkirim berjam-jam kemudian,
     * dan peringatan basi lebih membingungkan daripada tidak ada peringatan.
     */
    public int $tries = 3;

    public function __construct(public int $artikelId) {}

    /**
     * Telegram menolak pengiriman yang terlalu rapat ke satu grup, sekitar 20
     * pesan per menit. Crawl yang menghasilkan tiga puluh berita negatif
     * sekaligus akan menabrak batas itu, dan yang ditolak adalah pesan yang
     * paling perlu sampai.
     *
     * Pembatas bawaan Laravel yang menahannya, bukan `sleep` di dalam job.
     * Job yang melewati jatah dikembalikan ke antrean dengan penundaan, jadi
     * worker tetap bisa mengerjakan pekerjaan lain sementara itu.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('telegram')];
    }

    public function handle(PengirimTelegram $telegram): void
    {
        $aturan = AturanAlert::query()
            ->where('jenis', 'berita_negatif')
            ->where('aktif', true)
            ->first();

        if ($aturan === null) {
            return;
        }

        // Dibaca ulang di sini, bukan dibawa dari pemicunya. Antara penilaian
        // dan pengiriman bisa ada koreksi manusia yang mengubah labelnya, dan
        // mengirim peringatan untuk artikel yang barusan dinyatakan netral
        // adalah cara tercepat membuat orang berhenti percaya pada alert.
        $baris = AnalisisSentimen::query()
            ->join('artikel', 'artikel.id', '=', 'analisis_sentimen.artikel_id')
            ->leftJoin('media', 'media.id', '=', 'artikel.media_id')
            ->where('analisis_sentimen.artikel_id', $this->artikelId)
            ->first([
                'analisis_sentimen.relevan',
                'analisis_sentimen.perlu_review',
                'analisis_sentimen.label_efektif',
                'analisis_sentimen.reason_summary',
                'artikel.judul',
                'artikel.url',
                'artikel.diambil_at',
                'media.nama as media',
            ]);

        if ($baris === null || ! $baris->relevan || $baris->label_efektif !== LabelSentimen::Negatif) {
            return;
        }

        // Model yang ragu tidak membangunkan siapa pun, kecuali aturannya
        // memang meminta begitu. Ambang yang sama dipakai aturan lonjakan
        // negatif, dan dua alert yang memutuskan "layak dikirim" dengan cara
        // berbeda akan terbaca sebagai kerusakan oleh yang menerimanya.
        if ($baris->perlu_review && ($aturan->kondisi['abaikan_perlu_review'] ?? true)) {
            return;
        }

        $berita = [
            'judul' => $baris->judul,
            'url' => $baris->url,
            'media' => $baris->media,
            'diambil_at' => $baris->diambil_at,
        ];

        // Riwayat ditulis lebih dulu, dengan status tertunda. Unique index
        // (aturan, artikel) yang menahan pengiriman ganda, dan ia hanya bisa
        // menahan kalau barisnya sudah ada sebelum pesannya dikirim. Menulisnya
        // setelah pengiriman berarti dua worker sempat mengirim dua pesan lalu
        // baru bertabrakan saat menyimpan jejaknya.
        //
        // `insertOrIgnore`, bukan `create` yang dibungkus try. Tabrakan unique
        // yang dilempar sebagai exception membatalkan seluruh transaksi yang
        // sedang berjalan di Postgres, dan job ini bisa saja dijalankan di
        // dalam transaksi milik pemanggilnya. ON CONFLICT DO NOTHING tidak
        // menyentuh transaksi sama sekali dan menjawab pertanyaan yang sama:
        // nol baris berarti artikel ini sudah pernah dialertkan.
        $dibuat = RiwayatAlert::query()->insertOrIgnore([
            'aturan_alert_id' => $aturan->id,
            'artikel_id' => $this->artikelId,
            'dipicu_at' => now(),
            'ringkasan' => mb_substr((string) $baris->judul, 0, 500),
            // Query builder tidak melewati cast model, jadi payload dikodekan
            // di sini. Tanpa ini Postgres menerima larik PHP sebagai string.
            'payload' => json_encode($berita),
            'status_kirim' => 'tertunda',
        ]);

        if ($dibuat === 0) {
            return;
        }

        $riwayat = RiwayatAlert::query()
            ->where('aturan_alert_id', $aturan->id)
            ->where('artikel_id', $this->artikelId)
            ->firstOrFail();

        $kirim = $telegram->kirim(PesanAlert::berita($aturan, $berita, $baris->reason_summary));

        $riwayat->update([
            'status_kirim' => $kirim['terkirim'] ? 'terkirim' : 'gagal',
            'pesan_error' => $kirim['error'],
        ]);

        // Penanda kapan aturan ini terakhir mengirim. Tidak dipakai sebagai
        // jeda, karena alert ini memang tanpa jeda, hanya supaya halaman Alert
        // bisa menyebutkan kapan terakhir ada kabar.
        $aturan->update(['dipicu_terakhir_at' => now()]);
    }
}
