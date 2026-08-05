<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Artikel;
use App\Services\Agregasi\RingkasanEksekutif;
use App\Support\Periode;
use App\Support\Waktu;
use Illuminate\Http\Request;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Ekspor Excel (F-34).
 *
 * Ditulis sebagai aliran, bukan dikumpulkan di memori lalu dikirim: ekspor
 * setahun bisa puluhan ribu baris, dan menyusunnya sekaligus akan menghabiskan
 * memori PHP-FPM yang dibagi seluruh permintaan lain.
 */
class EksporController extends Controller
{
    public function artikel(Request $request, RingkasanEksekutif $ringkasan): BinaryFileResponse
    {
        $periode = Periode::dariRequest($request, $ringkasan);
        $nama = 'artikel-'.$periode->dari->toDateString().'-sd-'.$periode->sampai->toDateString().'.xlsx';

        // Ditulis ke berkas sementara, bukan langsung ke output. SimpleExcelWriter
        // punya streamDownload() sendiri yang mengirim header dan menulis ke
        // output, membungkusnya lagi dengan response()->streamDownload()
        // membuat keduanya berebut dan proses mati tanpa pesan.
        //
        // Memorinya tetap aman: baris ditulis satu per satu ke berkas, tidak
        // dikumpulkan dulu.
        $jalur = tempnam(sys_get_temp_dir(), 'ekspor-').'.xlsx';

        $penulis = SimpleExcelWriter::create($jalur)->addHeader([
            'Judul', 'Media', 'Penulis', 'Terbit', 'Diambil', 'Jumlah kata',
            'Status dedup', 'Konteks', 'Nada', 'Kode alasan', 'Perlu review', 'URL',
        ]);

        Artikel::query()
            ->with(['media:id,nama', 'analisisSentimen'])
            ->whereBetween('diambil_at', [$periode->mulaiUtc(), $periode->akhirUtc()])
            // chunkById, bukan get(): jumlah barisnya tidak dibatasi apa pun
            // selain rentang tanggal.
            ->chunkById(500, function ($kumpulan) use ($penulis) {
                foreach ($kumpulan as $artikel) {
                    $analisis = $artikel->analisisSentimen->where('relevan', true);

                    // Artikel tanpa analisis relevan tetap diekspor satu baris,
                    // menyembunyikannya membuat total di Excel tidak cocok
                    // dengan angka di dashboard.
                    if ($analisis->isEmpty()) {
                        $penulis->addRow($this->baris($artikel, null));

                        continue;
                    }

                    foreach ($analisis as $satu) {
                        $penulis->addRow($this->baris($artikel, $satu));
                    }
                }
            });

        $penulis->close();

        return response()->download($jalur, $nama, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** @return list<mixed> */
    private function baris(Artikel $artikel, mixed $analisis): array
    {
        return [
            $artikel->judul,
            $artikel->media?->nama ?? 'Belum ditautkan',
            $artikel->penulis,
            $artikel->dipublikasikan_at ? Waktu::tanggalWita($artikel->dipublikasikan_at) : null,
            Waktu::tanggalWita($artikel->diambil_at),
            $artikel->jumlah_kata,
            $artikel->status_dedup->value,
            $analisis?->label_efektif?->value,
            $analisis?->reason_code,
            $analisis?->perlu_review ? 'ya' : 'tidak',
            $artikel->url,
        ];
    }
}
