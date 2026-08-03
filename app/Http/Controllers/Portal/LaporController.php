<?php

namespace App\Http\Controllers\Portal;

use App\Enums\StatusVerifikasi;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanLaporanPemuatanRequest;
use App\Jobs\ArsipkanBuktiPemuatan;
use App\Models\Kontrak;
use App\Models\Media;
use App\Models\Pemuatan;
use App\Services\Portal\PemeriksaUrlLaporan;
use App\Support\Waktu;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lapor pemuatan, wireframe dokumen 04 bagian C.6.
 *
 * Prinsip halaman ini: satu isian wajib, sisanya kerja sistem. Menggantikan
 * empat field Google Form lama. Setiap kali muncul godaan menambah field,
 * periksa dulu apakah sistem sebenarnya sudah tahu jawabannya. Nama media
 * tidak ditanyakan karena identitas datang dari akun yang login.
 */
class LaporController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('portal/Lapor', [
            'kontrak' => $this->kontrakAktif(),
            'sudahOtomatis' => $this->sudahTercatatOtomatis(),
        ]);
    }

    /**
     * Pratinjau per URL sebelum apa pun disimpan (F-49).
     *
     * Hasilnya dikembalikan lewat session, bukan JSON, supaya halamannya tetap
     * satu request Inertia dan tombol kembali peramban tidak menyisakan
     * pratinjau basi.
     */
    public function periksa(Request $request, PemeriksaUrlLaporan $pemeriksa): RedirectResponse
    {
        $data = $request->validate([
            'kontrak_id' => ['required', 'integer', 'exists:kontrak,id'],
            'tautan' => ['required', 'string', 'max:10000'],
        ]);

        $kontrak = Kontrak::findOrFail($data['kontrak_id']);
        $media = Media::findOrFail($kontrak->media_id);

        $daftar = collect(preg_split('/\R/', $data['tautan']))
            ->map(fn (string $b) => trim($b))
            ->filter()
            ->unique()
            // Batas yang sama dengan aturan penyimpanan. Menampilkan pratinjau
            // untuk 200 tautan lalu menolaknya saat kirim adalah pemborosan
            // waktu media dan kuota unduhan situsnya.
            ->take(50);

        $hasil = $daftar
            ->map(fn (string $url) => $pemeriksa->periksa($url, $media, $kontrak))
            ->values()
            ->all();

        return back()->with('hasilPeriksa', [
            'kontrak_id' => $kontrak->id,
            'baris' => $hasil,
        ]);
    }

    public function store(SimpanLaporanPemuatanRequest $request, PemeriksaUrlLaporan $pemeriksa): RedirectResponse
    {
        $kontrak = Kontrak::findOrFail($request->integer('kontrak_id'));
        $media = Media::findOrFail($kontrak->media_id);

        $tersimpan = 0;
        $dilewati = 0;
        $ditolak = [];

        foreach ($request->validated('baris') as $indeks => $baris) {
            $periksa = $pemeriksa->periksa($baris['url'], $media, $kontrak);

            // Diperiksa ulang di sini, bukan dipercaya dari pratinjau. Yang
            // dikirim peramban bisa disunting, dan F-50 adalah batas antar
            // media, bukan sekadar bantuan pengisian form.
            if ($periksa['status'] === PemeriksaUrlLaporan::DOMAIN_SALAH) {
                $ditolak[] = $periksa['pesan'];

                continue;
            }

            if ($periksa['status'] === PemeriksaUrlLaporan::SUDAH_TERCATAT) {
                $dilewati++;

                continue;
            }

            $pemuatan = $this->simpan($kontrak, $periksa, $baris, $request, $indeks);

            if ($pemuatan === null) {
                $dilewati++;

                continue;
            }

            $tersimpan++;

            // Bukti diambil di latar belakang (F-52). Media tidak menunggu
            // proses ini, dan tidak perlu tahu bahwa ia ada.
            ArsipkanBuktiPemuatan::dispatch($pemuatan->id);
        }

        return to_route('portal.kontrak')->with(
            $tersimpan > 0 ? 'sukses' : 'galat',
            $this->ringkasan($tersimpan, $dilewati, $ditolak),
        );
    }

    private function simpan(
        Kontrak $kontrak,
        array $periksa,
        array $baris,
        SimpanLaporanPemuatanRequest $request,
        int $indeks,
    ): ?Pemuatan {
        $jalurBukti = null;

        if ($request->hasFile("baris.{$indeks}.bukti")) {
            // Di luar public/: bukti pemuatan menyangkut pembayaran kontrak
            // dan tidak boleh terbuka dengan menebak URL (dokumen 06 bagian 7).
            $jalurBukti = $request->file("baris.{$indeks}.bukti")->store('bukti-pemuatan', 'local');
        }

        try {
            // Transaksi bersarang, bukan try/catch telanjang: di PostgreSQL,
            // menangkap pelanggaran unique di dalam transaksi tanpa savepoint
            // meracuni seluruh transaksi dan baris berikutnya ikut gagal.
            return DB::transaction(fn () => Pemuatan::withoutGlobalScopes()->create([
                'kontrak_id' => $kontrak->id,
                'media_id' => $kontrak->media_id,
                'artikel_id' => $periksa['artikel_id'],
                'url' => $periksa['url_kanonik'],
                'judul' => mb_substr($baris['judul'], 0, 500),
                'tanggal_muat' => $baris['tanggal'],
                'sumber_catatan' => 'laporan_media',
                'bukti_path' => $jalurBukti,
                'status_ekstraksi' => $periksa['status'] === PemeriksaUrlLaporan::GAGAL ? 'gagal' : 'menunggu',
                // Laporan media selalu menunggu verifikasi manusia. Yang
                // langsung terverifikasi hanya temuan crawler, karena di situ
                // tidak ada pihak berkepentingan yang mengklaim apa pun.
                'status_verifikasi' => StatusVerifikasi::Menunggu,
                'dilaporkan_oleh' => $request->user()->id,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Dua tab, atau tombol kirim ditekan dua kali.
            return null;
        }
    }

    /** @param  list<string>  $ditolak */
    private function ringkasan(int $tersimpan, int $dilewati, array $ditolak): string
    {
        $bagian = [];

        if ($tersimpan > 0) {
            $bagian[] = "{$tersimpan} laporan terkirim dan menunggu verifikasi.";
        }

        if ($dilewati > 0) {
            $bagian[] = "{$dilewati} tautan dilewati karena sudah tercatat.";
        }

        if ($ditolak !== []) {
            $bagian[] = count($ditolak).' tautan ditolak: '.$ditolak[0];
        }

        return $bagian === [] ? 'Tidak ada yang tersimpan.' : implode(' ', $bagian);
    }

    /**
     * F-48: yang sudah ditemukan crawler dan sudah dihitung ke kontrak.
     *
     * Ditampilkan sebelum form, bukan sesudah. Fungsinya ganda: mengurangi
     * laporan ganda, dan menunjukkan bahwa sistem bekerja untuk media alih-alih
     * menambah beban mereka.
     */
    private function sudahTercatatOtomatis(): array
    {
        return Pemuatan::query()
            ->where('sumber_catatan', 'otomatis')
            ->where('tanggal_muat', '>=', Waktu::awalHariIni()->subDays(30)->toDateString())
            ->orderByDesc('tanggal_muat')
            ->limit(50)
            ->get(['id', 'judul', 'url', 'tanggal_muat'])
            ->map(fn (Pemuatan $p) => [
                ...$p->only(['id', 'judul', 'url']),
                'tanggal_muat' => $p->tanggal_muat?->toDateString(),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function kontrakAktif(): array
    {
        return Kontrak::query()
            ->where('status', 'aktif')
            ->orderBy('tanggal_akhir')
            ->get(['id', 'nomor', 'judul', 'tanggal_mulai', 'tanggal_akhir'])
            ->map(fn (Kontrak $k) => [
                ...$k->only(['id', 'nomor', 'judul']),
                'tanggal_mulai' => $k->tanggal_mulai?->toDateString(),
                'tanggal_akhir' => $k->tanggal_akhir?->toDateString(),
            ])
            ->all();
    }
}
