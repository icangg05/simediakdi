<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PeranPengguna;
use App\Http\Controllers\Controller;
use App\Jobs\BuatNarasiBulanan;
use App\Models\NarasiEksekutif as BarisNarasi;
use App\Models\PemantauanNarasiBulanan;
use App\Services\Agregasi\RingkasanEksekutif;
use App\Support\Waktu;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Memantau dan, bila diperlukan, menjadwalkan analisis laporan bulanan. */
class AnalisisBulananController extends Controller
{
    public function __invoke(Request $request, RingkasanEksekutif $ringkasan): Response
    {
        $awalBulanIni = CarbonImmutable::now(Waktu::ZONA)->startOfMonth();
        $pantauan = PemantauanNarasiBulanan::query()
            ->with('narasi')
            ->orderByDesc('bulan')
            ->get()
            ->keyBy(fn (PemantauanNarasiBulanan $baris): string => $baris->bulan->format('Y-m'));

        // Riwayat lama pernah memakai preset 30 hari bergulir. Baris seperti
        // 16 Juli–14 Agustus bukan laporan Juli dan tidak boleh dipasangkan ke
        // bulan hanya karena tanggal mulainya berada pada Juli.
        $narasi = BarisNarasi::query()
            ->where('periode', '30d')
            ->orderByDesc('dari')
            ->get()
            ->filter(fn (BarisNarasi $baris): bool => $this->narasiTepatBulan(
                $baris,
                $baris->dari->toImmutable()->startOfMonth(),
            ) !== null)
            ->keyBy(fn (BarisNarasi $baris): string => $baris->dari->format('Y-m'));
        $jumlahBahan = $this->jumlahBahanPerBulan();

        $bulan = collect($ringkasan->bulanTersedia())
            ->merge($pantauan->keys())
            ->merge($narasi->keys())
            ->unique()
            ->sortDesc()
            ->values()
            ->map(fn (string $nilai): array => $this->baris(
                $nilai,
                $pantauan->get($nilai),
                $narasi->get($nilai),
                $awalBulanIni,
                (int) ($jumlahBahan->get($nilai) ?? 0),
            ));

        return Inertia::render('admin/AnalisisBulanan', [
            'ringkasan' => $this->ringkasan($bulan),
            'bulan' => $bulan,
            'bolehAnalisisManual' => $request->user()?->berperan(PeranPengguna::Superadmin) ?? false,
            'diperbarui' => now()->toIso8601String(),
        ]);
    }

    /** Menjadwalkan satu bulan, tanpa menahan request selama Gemini bekerja. */
    public function jalankan(Request $request, string $bulan): RedirectResponse
    {
        $awal = $this->awalBulan($bulan);
        abort_if($awal === null, 404);
        $hasil = $this->narasiPadaBulan($awal);
        $pantauanLama = PemantauanNarasiBulanan::query()
            ->whereDate('bulan', $awal->toDateString())
            ->first();

        // Bulan lampau yang sudah final cukup dianalisis sekali. Bulan berjalan
        // tetap boleh diminta ulang; sidik bahan di service mencegah panggilan
        // Gemini bila datanya memang belum berubah.
        if ($hasil !== null
            && $awal->lt(CarbonImmutable::now(Waktu::ZONA)->startOfMonth())
            && ($pantauanLama?->dikunci ?? true)) {
            return back()->with('catatan', 'Analisis '.$this->namaBulan($awal).' sudah final dan tidak berubah lagi.');
        }

        if ($this->jumlahBahan($awal) === 0) {
            return back()->with('galat', 'Belum ada data pemberitaan yang dapat dianalisis untuk '.$this->namaBulan($awal).'.');
        }

        $pantauan = DB::transaction(function () use ($awal, $hasil): ?PemantauanNarasiBulanan {
            $pantauan = PemantauanNarasiBulanan::query()->firstOrCreate(
                ['bulan' => $awal->toDateString()],
                ['status' => PemantauanNarasiBulanan::STATUS_MENUNGGU],
            );
            $pantauan = PemantauanNarasiBulanan::query()->lockForUpdate()->findOrFail($pantauan->id);
            $batasMenunggu = now()->subMinutes(15);
            $batasBerjalan = now()->subMinutes(30);

            $masihDiproses = $pantauan->status === PemantauanNarasiBulanan::STATUS_BERJALAN
                && ($pantauan->mulai_at === null || $pantauan->mulai_at->gte($batasBerjalan));
            $sudahDijadwalkan = $pantauan->status === PemantauanNarasiBulanan::STATUS_MENUNGGU
                && $pantauan->mulai_at?->gte($batasMenunggu);

            if ($masihDiproses || $sudahDijadwalkan) {
                return null;
            }

            // Tautan ke hasil 30 hari bergulir yang keliru ikut dilepas. Job
            // kemudian hanya boleh menguncinya lagi dengan hasil satu bulan
            // kalender yang tepat.
            $pantauan->update([
                'status' => PemantauanNarasiBulanan::STATUS_MENUNGGU,
                'dikunci' => false,
                // Hasil lama tetap dapat dibuka selama pembaruan berlangsung.
                // Tautan lama yang rentangnya keliru tetap dilepas karena
                // $hasil bernilai null untuk narasi 30 hari bergulir.
                'narasi_eksekutif_id' => $hasil?->id,
                'galat' => null,
                'mulai_at' => now(),
                'selesai_at' => null,
                'gagal_at' => null,
            ]);

            return $pantauan;
        });

        if ($pantauan === null) {
            return back()->with('catatan', 'Analisis '.$this->namaBulan($awal).' sudah berada dalam antrean atau sedang dikerjakan.');
        }

        try {
            // Job khusus tidak mengikat parameter ke signature command yang
            // mungkin masih dimuat worker versi lama. Itulah penyebab nyata
            // permintaan Juni gagal sebelum command sempat berjalan.
            Bus::dispatch(new BuatNarasiBulanan($pantauan->id, $bulan));
        } catch (Throwable $galat) {
            $pantauan->update([
                'status' => PemantauanNarasiBulanan::STATUS_GAGAL,
                'galat' => trim($galat->getMessage()) ?: $galat::class,
                'gagal_at' => now(),
            ]);
            report($galat);

            return back()->with('galat', 'Analisis belum berhasil dimasukkan ke antrean. Pesan teknisnya sudah dicatat pada halaman ini.');
        }

        activity('analisis-bulanan')
            ->causedBy($request->user())
            ->performedOn($pantauan)
            ->withProperties(['bulan' => $bulan])
            ->log('Analisis bulanan diminta manual');

        return back()
            ->with('sukses', 'Analisis '.$this->namaBulan($awal).' dimasukkan ke antrean.')
            ->with('catatan', 'Status akan diperbarui otomatis saat proses mulai dan selesai.');
    }

    /** @return array<string, mixed> */
    private function baris(
        string $nilai,
        ?PemantauanNarasiBulanan $pantauan,
        ?BarisNarasi $narasi,
        CarbonImmutable $awalBulanIni,
        int $jumlahBahan,
    ): array {
        $awal = CarbonImmutable::createFromFormat('!Y-m', $nilai, Waktu::ZONA)->startOfMonth();
        $hasilPantauan = $this->narasiTepatBulan($pantauan?->narasi, $awal);
        $hasil = $hasilPantauan ?? $this->narasiTepatBulan($narasi, $awal);
        $status = $pantauan?->status
            ?? ($hasil ? PemantauanNarasiBulanan::STATUS_SELESAI : 'belum_dianalisis');
        $dikunci = $hasil !== null
            && ($pantauan?->dikunci ?? $awal->lt($awalBulanIni));
        $galat = $pantauan?->galat;

        // Status selesai hanya sah bila hasilnya memang tepat untuk tanggal 1
        // sampai akhir bulan tersebut. Ini juga membuat data salah lama aman
        // dibaca sebelum migrasi perbaikannya sempat dijalankan di server.
        if ($hasil === null && $status === PemantauanNarasiBulanan::STATUS_SELESAI) {
            $status = $jumlahBahan > 0 ? 'belum_dianalisis' : PemantauanNarasiBulanan::STATUS_TANPA_DATA;
        }

        if ($hasil === null && $status === PemantauanNarasiBulanan::STATUS_TANPA_DATA && $jumlahBahan > 0) {
            $status = 'belum_dianalisis';
        }

        // Baris menunggu tanpa waktu mulai hanyalah status bawaan, belum
        // membuktikan bahwa suatu job benar-benar sudah masuk antrean.
        if ($hasil === null && $status === PemantauanNarasiBulanan::STATUS_MENUNGGU && $pantauan?->mulai_at === null) {
            $status = $jumlahBahan > 0 ? 'belum_dianalisis' : PemantauanNarasiBulanan::STATUS_TANPA_DATA;
        }

        if ($status === PemantauanNarasiBulanan::STATUS_MENUNGGU
            && $pantauan?->mulai_at?->lt(now()->subMinutes(15))) {
            $status = PemantauanNarasiBulanan::STATUS_GAGAL;
            $galat = 'Analisis sudah menunggu lebih dari 15 menit. Periksa worker antrean, lalu jalankan ulang bila worker sudah aktif.';
        }

        // Proses normal selesai dalam beberapa menit. Bila worker mati keras,
        // blok catch di service tidak sempat berjalan dan status akan tertinggal
        // sebagai "berjalan" selamanya.
        if ($status === PemantauanNarasiBulanan::STATUS_BERJALAN
            && $pantauan?->mulai_at?->lt(now()->subMinutes(30))) {
            $status = PemantauanNarasiBulanan::STATUS_GAGAL;
            $galat = 'Proses tidak selesai lebih dari 30 menit. Periksa penjadwal dan log aplikasi, lalu jalankan ulang bila worker sudah aktif.';
        }

        $sedangDiproses = in_array($status, [
            PemantauanNarasiBulanan::STATUS_MENUNGGU,
            PemantauanNarasiBulanan::STATUS_BERJALAN,
        ], true);
        $belumFinal = ! $dikunci || $hasil === null;

        return [
            'bulan' => $nilai,
            'status' => $status,
            'dikunci' => $dikunci,
            'bulan_berjalan' => $awal->equalTo($awalBulanIni),
            'dijadwalkan_ulang' => ! $dikunci && $awal->gte($awalBulanIni->subMonth()),
            'dapat_dianalisis_manual' => $jumlahBahan > 0 && $belumFinal && ! $sedangDiproses,
            'jumlah_bahan' => $jumlahBahan,
            'pemeriksaan' => $pantauan?->pemeriksaan ?? 0,
            'galat' => $galat,
            'mulai_at' => $pantauan?->mulai_at?->toIso8601String(),
            'selesai_at' => $pantauan?->selesai_at?->toIso8601String(),
            'gagal_at' => $pantauan?->gagal_at?->toIso8601String(),
            'judul' => $hasil?->judul,
            'jumlah_artikel' => $hasil?->jumlah_artikel,
            'model' => $hasil?->model,
            'hasil_dibuat_at' => $hasil?->dibuat_at?->toIso8601String(),
        ];
    }

    private function narasiTepatBulan(?BarisNarasi $narasi, CarbonImmutable $awal): ?BarisNarasi
    {
        if ($narasi === null
            || $narasi->periode !== '30d'
            || $narasi->dari->toDateString() !== $awal->toDateString()
            || $narasi->sampai->toDateString() !== $awal->endOfMonth()->toDateString()) {
            return null;
        }

        return $narasi;
    }

    private function narasiPadaBulan(CarbonImmutable $awal): ?BarisNarasi
    {
        return BarisNarasi::query()
            ->where('periode', '30d')
            ->where('dari', $awal->toDateString())
            ->where('sampai', $awal->endOfMonth()->toDateString())
            ->first();
    }

    /** @return Collection<string, int> */
    private function jumlahBahanPerBulan(): Collection
    {
        return DB::table('ringkasan_harian')
            ->whereNull('media_id')
            ->get(['tanggal', 'jumlah_negatif', 'jumlah_netral', 'jumlah_positif'])
            ->groupBy(fn (object $baris): string => substr((string) $baris->tanggal, 0, 7))
            ->map(fn (Collection $baris): int => $baris->sum(
                fn (object $hari): int => (int) $hari->jumlah_negatif + (int) $hari->jumlah_netral + (int) $hari->jumlah_positif,
            ));
    }

    private function jumlahBahan(CarbonImmutable $awal): int
    {
        $baris = DB::table('ringkasan_harian')
            ->whereNull('media_id')
            ->whereBetween('tanggal', [$awal->toDateString(), $awal->endOfMonth()->toDateString()])
            ->selectRaw('coalesce(sum(jumlah_negatif + jumlah_netral + jumlah_positif), 0) AS jumlah')
            ->first();

        return (int) ($baris?->jumlah ?? 0);
    }

    private function awalBulan(string $nilai): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $nilai, $bagian) !== 1) {
            return null;
        }

        $tahun = (int) $bagian[1];
        $bulan = (int) $bagian[2];

        return checkdate($bulan, 1, $tahun)
            ? CarbonImmutable::create($tahun, $bulan, 1, 0, 0, 0, Waktu::ZONA)
            : null;
    }

    private function namaBulan(CarbonImmutable $awal): string
    {
        return $awal->locale('id')->translatedFormat('F Y');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $bulan
     * @return array<string, int>
     */
    private function ringkasan(Collection $bulan): array
    {
        return [
            'total' => $bulan->count(),
            'berjalan' => $bulan->where('status', PemantauanNarasiBulanan::STATUS_BERJALAN)->count(),
            'gagal' => $bulan->where('status', PemantauanNarasiBulanan::STATUS_GAGAL)->count(),
            'final' => $bulan->where('dikunci', true)->count(),
        ];
    }
}
