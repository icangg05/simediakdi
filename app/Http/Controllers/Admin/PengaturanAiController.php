<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KunciGemini;
use App\Models\PelatihanModelRelevansi;
use App\Models\PengaturanAi;
use App\Services\Ai\RotasiKunciGemini;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Penyuntingan pengaturan klasifikasi Gemini, `/admin/pengaturan`.
 *
 * Berbeda dari sisa halaman Pengaturan yang sengaja hanya menampilkan nilai:
 * model, prompt, dan daftar kunci memang dirancang untuk disetel dari layar.
 * Prompt disetel berkali-kali sampai hasilnya benar, dan kunci ditambah tepat
 * pada saat kuota habis, yaitu saat paling buruk untuk menunggu deploy.
 *
 * Perubahannya tetap tercatat: kedua model memakai activity log, jadi siapa
 * yang mengubah prompt dan menjadi apa tetap bisa ditelusuri.
 */
class PengaturanAiController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'model' => ['required', 'string', 'max:100'],
            'penyedia_relevansi' => ['required', 'in:gemini,indobert'],
            'versi_prompt_relevansi' => ['required', 'string', 'max:30'],
            'prompt_relevansi' => ['required', 'string', 'min:50'],
            'versi_prompt_sentimen' => ['required', 'string', 'max:30'],
            'prompt_sentimen' => ['required', 'string', 'min:50'],
        ], [
            'prompt_relevansi.min' => 'Prompt relevansi terlalu pendek untuk bisa dipakai menilai.',
            'prompt_sentimen.min' => 'Prompt sentimen terlalu pendek untuk bisa dipakai menilai.',
        ]);

        // Ditolak di sini, bukan dibiarkan lalu ditangani saat klasifikasi
        // berjalan. Pengaturan yang tersimpan terbaca sebagai pengaturan yang
        // berlaku, dan admin yang memilih IndoBERT tanpa model aktif tidak akan
        // pernah tahu kenapa antreannya berhenti sampai ia membuka halaman
        // Antrean AI dan membaca pesan galat per baris.
        if ($data['penyedia_relevansi'] === 'indobert'
            && ! PelatihanModelRelevansi::query()->where('aktif', true)->exists()) {
            return back()->with('galat', 'Belum ada model relevansi yang diaktifkan. '
                .'Latih lalu aktifkan satu model di halaman Model Relevansi sebelum memilih IndoBERT.');
        }

        $pengaturan = PengaturanAi::query()->first();

        if ($pengaturan === null) {
            PengaturanAi::query()->create($data);
        } else {
            $pengaturan->update($data);
        }

        return back()->with('sukses', 'Pengaturan klasifikasi disimpan. Artikel yang sudah dinilai '
            .'tidak ikut berubah, jalankan ulang klasifikasinya bila perlu.');
    }

    public function simpanKunci(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60', 'unique:kunci_gemini,label'],
            // Kunci Gemini berupa string panjang tanpa spasi. Batas bawah ini
            // menahan salah tempel yang paling sering terjadi, yaitu menempel
            // potongan kunci atau menempel nama proyek.
            'kunci' => ['required', 'string', 'min:20', 'max:200'],
        ], [
            'label.unique' => 'Sudah ada kunci dengan label itu.',
            'kunci.min' => 'Kunci terlihat terlalu pendek. Periksa lagi hasil salinnya.',
        ]);

        KunciGemini::query()->create($data);

        return back()->with('sukses', "Kunci {$data['label']} ditambahkan.");
    }

    /**
     * Dua hal yang boleh diubah dari layar: sakelar aktif dan batas harian.
     *
     * Penandaan limit tetap tidak bisa dihapus dari sini. Kuota Gemini pulih
     * menurut jam Google, bukan menurut tombol, dan kunci yang dipaksa dipakai
     * lebih awal hanya menghasilkan 429 yang sama sambil membakar satu
     * permintaan.
     *
     * Batas harian boleh diisi karena angkanya tidak selalu bisa ditemukan
     * sendiri. Google hanya menyebutkannya di badan galat 429 harian, sedangkan
     * penjaga kuota lokal menahan kunci sebelum galat itu terjadi, jadi kunci
     * berbayar tidak punya jalan untuk memberi tahu jatahnya yang sebenarnya.
     * Lihat RotasiKunciGemini::batasHarian().
     *
     * Keduanya opsional dan dikerjakan terpisah, karena datang dari dua kendali
     * yang berbeda di layar. Tombol Nyalakan mengirim `aktif` saja, dan kotak
     * batas harian mengirim `rpd_manual` saja.
     */
    public function ubahKunci(Request $request, KunciGemini $kunci): RedirectResponse
    {
        // Kehadiran bidang diperiksa lewat `has()`, bukan lewat aturan
        // `required_without`. Aturan itu menganggap nilai null sebagai tidak
        // ada, jadi kotak batas yang dikosongkan justru berbalik menuntut
        // bidang `aktif` yang memang tidak dikirim tombolnya, dan permintaan
        // pengosongan berakhir sebagai galat validasi yang tidak menjelaskan
        // apa pun kepada admin.
        if ($request->has('rpd_manual')) {
            $rpd = $request->validate([
                // Batas atas ada supaya salah ketik satu digit tidak
                // melumpuhkan penjaga kuota diam-diam. Batas harian sejuta
                // berarti jarak antar artikel jatuh ke nol, dan antrean
                // menembak Gemini secepat yang sanggup dikirim worker sampai
                // Google memutusnya.
                'rpd_manual' => ['nullable', 'integer', 'min:1', 'max:100000'],
            ], [
                'rpd_manual.min' => 'Batas harian minimal 1. Kosongkan kalau ingin kembali memakai angka bawaan.',
                'rpd_manual.max' => 'Batas harian terlihat terlalu besar. Periksa lagi angkanya di Google AI Studio.',
            ])['rpd_manual'] ?? null;

            $kunci->update(['rpd_manual' => $rpd]);

            return back()->with('sukses', $rpd === null
                ? "Batas harian kunci {$kunci->label} dikembalikan ke angka bawaan."
                : "Batas harian kunci {$kunci->label} disetel ke {$rpd} permintaan per hari.");
        }

        $aktif = (bool) $request->validate(['aktif' => ['required', 'boolean']])['aktif'];

        if (! $aktif && $galat = $this->penolakan($kunci, 'dimatikan')) {
            return back()->with('galat', $galat);
        }

        $kunci->update(['aktif' => $aktif]);

        return back()->with('sukses', "Kunci {$kunci->label} ".($aktif ? 'dinyalakan.' : 'dimatikan.'));
    }

    public function hapusKunci(KunciGemini $kunci): RedirectResponse
    {
        if ($galat = $this->penolakan($kunci, 'dihapus')) {
            return back()->with('galat', $galat);
        }

        $kunci->delete();

        return back()->with('sukses', "Kunci {$kunci->label} dihapus.");
    }

    /**
     * Mengetuk Gemini dengan satu kunci tertentu, lalu melaporkan apa adanya.
     *
     * Hasilnya dikirim lewat session, bukan lewat toast biasa, karena yang
     * dicari admin justru isinya: jawaban model membuktikan paket benar-benar
     * sampai dan kembali, dan kalimat galat dari Google menyebut sebabnya jauh
     * lebih tepat daripada tebakan mana pun, misalnya "API key not valid" untuk
     * kunci yang salah ketik.
     *
     * Uji ini memakai kuota. Satu ketukan adalah satu permintaan yang dihitung
     * Google sama seperti permintaan penilaian, jadi throttle rutenya sengaja
     * ketat dan pemakaiannya ikut tercatat pada penjaga kuota.
     */
    public function ujiKunci(KunciGemini $kunci, RotasiKunciGemini $rotasi): RedirectResponse
    {
        $hasil = $rotasi->uji($kunci, (string) PengaturanAi::aktif()->model);

        return back()->with('ujiKunci', [
            'id' => $kunci->id,
            'label' => $kunci->label,
            ...$hasil,
        ]);
    }

    /**
     * Sistem harus selalu menyisakan satu kunci yang menyala.
     *
     * Yang dihitung kunci **aktif**, bukan jumlah barisnya. Menghitung baris
     * membuat dua kunci bisa dimatikan satu per satu sampai tidak ada yang
     * tersisa: setiap langkahnya lolos karena barisnya masih dua, dan
     * klasifikasi berhenti tanpa satu pun galat yang menyebutkan sebabnya.
     *
     * Penjaganya di sini, bukan hanya di layar. Tombol yang disembunyikan
     * bukan aturan, permintaannya tetap bisa dikirim langsung.
     */
    private function penolakan(KunciGemini $kunci, string $tindakan): ?string
    {
        if (KunciGemini::query()->count() <= 1) {
            return "Kunci terakhir tidak bisa {$tindakan}. Klasifikasi akan berhenti sama sekali. "
                .'Tambahkan kunci pengganti lebih dulu.';
        }

        if ($kunci->aktif && KunciGemini::query()->where('aktif', true)->count() <= 1) {
            return "Ini satu-satunya kunci yang menyala, jadi tidak bisa {$tindakan}. "
                .'Nyalakan kunci lain lebih dulu.';
        }

        return null;
    }
}
