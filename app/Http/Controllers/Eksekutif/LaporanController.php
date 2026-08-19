<?php

namespace App\Http\Controllers\Eksekutif;

use App\Enums\LabelSentimen;
use App\Http\Controllers\Controller;
use App\Models\Artikel;
use App\Services\Agregasi\NarasiEksekutif;
use App\Services\Agregasi\RingkasanEksekutif;
use App\Support\Periode;
use App\Support\UrlEksternal;
use App\Support\Waktu;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Number;
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
     * Berkas PDF yang langsung terunduh, tanpa melewati dialog cetak peramban.
     *
     * Dirender dompdf di server, bukan oleh peramban pembaca. Dialog cetak
     * menuntut pengguna memilih ukuran kertas, margin, dan latar belakang yang
     * benar sebelum berkasnya jadi, dan satu pilihan yang keliru di sana
     * menghasilkan laporan tanpa warna atau terpotong. Di server ukurannya
     * selalu F4 potret dan hasilnya sama untuk siapa pun yang menekannya.
     */
    public function unduh(Request $request, RingkasanEksekutif $ringkasan, NarasiEksekutif $narasi): HttpResponse
    {
        $awalBulan = $this->awalBulan($request->query('bulan'));
        $periode = new Periode(dari: $awalBulan, sampai: $awalBulan->endOfMonth()->startOfDay());
        $kpi = $ringkasan->kpi($periode->dari, $periode->sampai);

        $pdf = Pdf::loadView('laporan.eksekutif', [
            'namaBulan' => $awalBulan->locale('id')->translatedFormat('F Y'),
            'rentangPeriode' => $this->tanggalPanjang($periode->dari).' sampai '.$this->tanggalPanjang($periode->sampai),
            'waktuPembuatan' => CarbonImmutable::now(Waktu::ZONA)->locale('id')->translatedFormat('j F Y \p\u\k\u\l H.i').' WITA',
            'ringkasan' => $this->kalimatRingkasan($kpi, $awalBulan),
            'kpi' => $kpi,
            'narasi' => $narasi->padaRentang('30d', $periode->dari, $periode->sampai)?->untukInertia(),
            'tren' => $this->trenLaporan($this->deretLaporan($periode, $ringkasan)),
            'negatif' => $this->beritaNegatif($periode),
            'media' => $ringkasan->peringkatMedia(
                $periode->dari,
                $periode->sampai,
                batas: null,
                termasukTanpaBerita: true,
                hanyaAktif: true,
            ),
        ]);

        // 210 x 330 mm dalam satuan titik, ukuran F4 yang dipakai perkantoran
        // di Indonesia. Dompdf tidak mengenal nama "f4" dan diam-diam jatuh ke
        // A4 bila diberi nama yang tidak dikenalnya.
        $pdf->setPaper([0, 0, 595.28, 935.43]);

        return $pdf->download('laporan-pemberitaan-'.$awalBulan->format('Y-m').'.pdf');
    }

    /**
     * Seluruh berita bersentimen negatif pada bulan laporan.
     *
     * Dibawa lengkap, tanpa batas jumlah, karena inilah bagian laporan yang
     * ditindaklanjuti. Ringkasan penilaian ikut dikirim supaya pembaca tahu
     * alasan sebuah berita dinilai negatif, dan status kerja sama medianya ikut
     * karena tindak lanjut atas media mitra dan nonmitra tidak sama.
     *
     * @return list<array<string, mixed>>
     */
    private function beritaNegatif(Periode $periode): array
    {
        return Artikel::query()
            ->relevanBerlabel()
            ->whereHas('analisisSentimen', fn ($s) => $s
                ->where('relevan', true)
                ->where('label_efektif', LabelSentimen::Negatif->value))
            ->with(['media:id,nama,partner', 'analisisSentimen' => fn ($q) => $q->where('relevan', true)])
            ->terbitAntara($periode->mulaiUtc(), $periode->akhirUtc())
            ->orderByRaw(Artikel::waktuTerbit().' desc')
            ->get(['id', 'media_id', 'judul', 'url', 'dipublikasikan_at', 'diambil_at'])
            ->map(fn (Artikel $artikel): array => [
                'judul' => $artikel->judul,
                // Alamat sumbernya ikut, supaya judul di lembar negatif bisa
                // ditekan langsung dari berkas PDF. Pembaca laporan yang ingin
                // memeriksa sebuah berita sebelumnya harus mencarinya sendiri
                // di mesin pencari dengan menyalin judulnya.
                'url' => UrlEksternal::http($artikel->url),
                'media' => $artikel->media?->nama ?? 'Tidak diketahui',
                'partner' => (bool) $artikel->media?->partner,
                'tanggal' => CarbonImmutable::parse($artikel->dipublikasikan_at ?? $artikel->diambil_at)
                    ->setTimezone(Waktu::ZONA)
                    ->locale('id')
                    ->translatedFormat('j M Y'),
                'penilaian' => $artikel->analisisSentimen->first()?->reason_summary,
            ])
            ->all();
    }

    /**
     * @param  array{satuan: string, baris: list<array<string, mixed>>}  $deret
     * @return list<array<string, mixed>> baris tren yang sudah membawa label rentangnya
     */
    private function trenLaporan(array $deret): array
    {
        return collect($deret['baris'])
            ->map(fn (array $baris): array => [
                ...$baris,
                'berlabel' => $baris['jumlah_positif'] + $baris['jumlah_netral'] + $baris['jumlah_negatif'],
                'rentang' => $this->rentangTerbaca((string) $baris['rentang_dari'], (string) $baris['rentang_sampai']),
            ])
            ->all();
    }

    /**
     * Kalimat pembuka laporan, disusun dari angka yang sudah dihitung.
     *
     * @param  array<string, mixed>  $kpi
     */
    private function kalimatRingkasan(array $kpi, CarbonImmutable $awalBulan): string
    {
        $berlabel = (int) $kpi['berlabel'];

        if ($berlabel === 0) {
            return 'Belum ada pemberitaan yang tercatat pada '.$awalBulan->locale('id')->translatedFormat('F Y').'.';
        }

        $nada = collect([
            'positif' => (int) $kpi['positif'],
            'netral' => (int) $kpi['netral'],
            'negatif' => (int) $kpi['negatif'],
        ])->sortDesc();

        return Number::format($berlabel, locale: 'id').' berita tercatat pada periode ini. '
            .'Nada '.$nada->keys()->first().' paling banyak muncul, yaitu '
            .Number::format($nada->first(), locale: 'id').' berita ('
            .Number::percentage($nada->first() / $berlabel * 100, maxPrecision: 1, locale: 'id').').';
    }

    private function tanggalPanjang(CarbonImmutable $tanggal): string
    {
        return $tanggal->locale('id')->translatedFormat('j F Y');
    }

    /** "1-7 Juni 2026", dan tanggal utuh bila rentangnya hanya satu hari. */
    private function rentangTerbaca(string $dari, string $sampai): string
    {
        $awal = CarbonImmutable::parse($dari, Waktu::ZONA);
        $akhir = CarbonImmutable::parse($sampai, Waktu::ZONA);

        if ($awal->isSameDay($akhir)) {
            return $this->tanggalPanjang($awal);
        }

        return $awal->format('j').'-'.$this->tanggalPanjang($akhir);
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
