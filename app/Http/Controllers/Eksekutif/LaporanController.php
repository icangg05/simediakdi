<?php

namespace App\Http\Controllers\Eksekutif;

use App\Http\Controllers\Controller;
use App\Services\Agregasi\NarasiEksekutif;
use App\Services\Agregasi\RingkasanEksekutif;
use App\Support\Periode;
use App\Support\Waktu;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Laporan kondisi pemberitaan untuk dibaca dan dicetak pimpinan.
 *
 * Versi pertama sengaja hanya menerima satu bulan kalender. Batas yang tegas
 * membuat dua laporan dengan label bulan yang sama selalu berisi tanggal yang
 * sama, termasuk saat bulan yang dipilih belum selesai berjalan.
 */
class LaporanController extends Controller
{
    public function __invoke(Request $request, RingkasanEksekutif $ringkasan, NarasiEksekutif $narasi): Response
    {
        $awalBulan = $this->awalBulan($request->query('bulan'));
        $periode = new Periode(
            dari: $awalBulan,
            sampai: $awalBulan->endOfMonth()->startOfDay(),
        );

        return Inertia::render('eksekutif/Laporan', [
            'bulan' => $awalBulan->format('Y-m'),
            'opsiBulan' => $ringkasan->bulanTersedia($awalBulan),
            ...$periode->untukInertia(),
            'dibuatPada' => CarbonImmutable::now(Waktu::ZONA)->toIso8601String(),
            'kpi' => $ringkasan->kpi($periode->dari, $periode->sampai),
            // Harus cocok tepat dengan bulan laporan. Mengambil narasi bulanan
            // terbaru dapat menampilkan ulasan Agustus di laporan Juli.
            'narasi' => $narasi->padaRentang('30d', $periode->dari, $periode->sampai)?->untukInertia(),
            'deret' => $this->deretLaporan($periode, $ringkasan),
            // Laporan menyebut seluruh media aktif, termasuk yang belum
            // memberitakan pada bulan ini. Media nonaktif tetap tersimpan untuk
            // riwayat, tetapi bukan lagi bagian kondisi pemantauan saat ini.
            'peringkatMedia' => $ringkasan->peringkatMedia(
                $periode->dari,
                $periode->sampai,
                batas: null,
                termasukTanpaBerita: true,
                hanyaAktif: true,
            ),
        ]);
    }

    /**
     * @return array{satuan: string, baris: list<array<string, mixed>>}
     */
    private function deretLaporan(Periode $periode, RingkasanEksekutif $ringkasan): array
    {
        $deret = $ringkasan->deret($periode->dari, $periode->sampai);

        $deret['baris'] = collect($deret['baris'])
            ->map(function (array $baris) use ($periode): array {
                // date_trunc('week') memakai Senin sebagai titik kelompok. Pada
                // awal bulan, titik itu dapat jatuh di bulan sebelumnya walau
                // data yang dijumlahkan tetap sudah dibatasi ke bulan laporan.
                $awalMinggu = CarbonImmutable::parse((string) $baris['tanggal'], Waktu::ZONA)->startOfDay();
                $akhirMinggu = $awalMinggu->addDays(6);

                $awalTampil = $awalMinggu->lessThan($periode->dari) ? $periode->dari : $awalMinggu;
                $akhirTampil = $akhirMinggu->greaterThan($periode->sampai) ? $periode->sampai : $akhirMinggu;

                return [
                    ...$baris,
                    'rentang_dari' => $awalTampil->toDateString(),
                    'rentang_sampai' => $akhirTampil->toDateString(),
                ];
            })
            ->all();

        return $deret;
    }

    private function awalBulan(mixed $nilai): CarbonImmutable
    {
        if (is_string($nilai) && preg_match('/^(\d{4})-(\d{2})$/', $nilai, $bagian) === 1) {
            $tahun = (int) $bagian[1];
            $bulan = (int) $bagian[2];

            if (checkdate($bulan, 1, $tahun)) {
                return CarbonImmutable::create($tahun, $bulan, 1, 0, 0, 0, Waktu::ZONA);
            }
        }

        return CarbonImmutable::now(Waktu::ZONA)->startOfMonth();
    }
}
