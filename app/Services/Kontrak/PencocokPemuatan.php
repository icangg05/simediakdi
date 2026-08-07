<?php

namespace App\Services\Kontrak;

use App\Enums\StatusVerifikasi;
use App\Models\Artikel;
use App\Models\Kontrak;
use App\Models\Pemuatan;
use App\Support\Waktu;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Mencocokkan artikel yang ter-crawl ke pemuatan kontrak (F-24).
 *
 * Artikel yang ditemukan sistem sendiri langsung berstatus terverifikasi,
 * tidak ada yang perlu diperiksa manusia, karena bukan media yang melaporkannya.
 * Yang butuh verifikasi hanya laporan mandiri di sprint 5.
 */
class PencocokPemuatan
{
    /**
     * @return int jumlah pemuatan baru
     */
    public function cocokkan(Kontrak $kontrak): int
    {
        if ($kontrak->status !== 'aktif') {
            return 0;
        }

        $baru = 0;

        // Rentang kontrak memakai kalender Kendari, bukan UTC: tanggal di
        // dokumen kontrak ditulis orang yang berada di WITA.
        //
        // Rilis yang sama dimuat lima media dihitung lima pemuatan, karena
        // kontrak menjawab "berapa pemuatan dari media ini", bukan "berapa
        // isu". Masing-masing punya URL dan halamannya sendiri, dan tiap media
        // berhak menghitungnya ke targetnya.
        $artikel = Artikel::withoutGlobalScopes()
            ->where('media_id', $kontrak->media_id)
            ->whereBetween('diambil_at', [
                Waktu::awalHari($kontrak->tanggal_mulai->toDateString()),
                Waktu::akhirHari($kontrak->tanggal_akhir->toDateString()),
            ])
            ->whereNotExists(fn ($q) => $q->selectRaw(1)
                ->from('pemuatan')
                ->whereColumn('pemuatan.artikel_id', 'artikel.id')
                ->where('pemuatan.kontrak_id', $kontrak->id))
            ->get(['id', 'judul', 'url', 'diambil_at']);

        foreach ($artikel as $satu) {
            try {
                Pemuatan::withoutGlobalScopes()->create([
                    'kontrak_id' => $kontrak->id,
                    'media_id' => $kontrak->media_id,
                    'artikel_id' => $satu->id,
                    'url' => mb_substr($satu->url, 0, 1000),
                    'judul' => mb_substr($satu->judul, 0, 500),
                    'tanggal_muat' => Waktu::tanggalWita($satu->diambil_at),
                    'sumber_catatan' => 'otomatis',
                    'status_ekstraksi' => 'berhasil',
                    // Sistem sendiri yang menemukannya; tidak ada klaim pihak
                    // berkepentingan yang perlu diverifikasi.
                    'status_verifikasi' => StatusVerifikasi::Terverifikasi,
                    'diverifikasi_at' => now(),
                ]);

                $baru++;
            } catch (UniqueConstraintViolationException) {
                // URL sudah diklaim pada kontrak ini lewat jalur lain.
            }
        }

        return $baru;
    }

    /**
     * Realisasi kontrak terhadap targetnya.
     *
     * @return array<string, mixed>
     */
    public function progres(Kontrak $kontrak): array
    {
        $terverifikasi = $kontrak->pemuatan()
            ->where('status_verifikasi', StatusVerifikasi::Terverifikasi)
            ->count();

        $menunggu = $kontrak->pemuatan()
            ->where('status_verifikasi', StatusVerifikasi::Menunggu)
            ->count();

        $target = $kontrak->target_pemuatan;
        $sisaHari = (int) max(0, now()->diffInDays($kontrak->tanggal_akhir, absolute: false));

        return [
            'terverifikasi' => $terverifikasi,
            'menunggu' => $menunggu,
            'target' => $target,
            'persen' => $target ? min(100, round($terverifikasi / $target * 100, 1)) : null,
            'sisa_hari' => $sisaHari,
            // F-26: kontrak yang tidak akan tercapai kalau lajunya tetap.
            'tertinggal' => $target !== null
                && $sisaHari > 0
                && $terverifikasi < $target
                && $this->lajuKurang($kontrak, $terverifikasi, $target),
        ];
    }

    /**
     * Bagian periode yang harus berlalu sebelum peringatan bermakna.
     *
     * Tanpa ambang ini, kontrak yang baru jalan dua hari dari sembilan puluh
     * langsung ditandai tertinggal, target pro rata-nya baru satu artikel, dan
     * nol dari satu sudah cukup memicu. Peringatan yang menyala sejak hari
     * pertama akan diabaikan admin, dan sesudah itu tidak berguna lagi
     * meskipun benar.
     */
    private const MINIMAL_BERJALAN = 0.25;

    /**
     * Laju sekarang tidak akan mencapai target sampai tenggat.
     *
     * Dibandingkan terhadap waktu yang sudah berjalan, bukan sekadar "belum
     * penuh", kontrak yang baru jalan seminggu dari tiga bulan wajar saja
     * masih jauh dari target.
     */
    private function lajuKurang(Kontrak $kontrak, int $terverifikasi, int $target): bool
    {
        $total = max(1, $kontrak->tanggal_mulai->diffInDays($kontrak->tanggal_akhir) + 1);
        $berjalan = max(0, min($total, $kontrak->tanggal_mulai->diffInDays(now())));
        $bagian = $berjalan / $total;

        if ($bagian < self::MINIMAL_BERJALAN) {
            return false;
        }

        // Toleransi 20%: laju yang sedikit di bawah pro rata masih bisa
        // dikejar, dan menandainya hanya membuat peringatan kehilangan arti.
        return $terverifikasi < $target * $bagian * 0.8;
    }
}
