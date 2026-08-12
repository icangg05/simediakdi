<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LabelSentimen;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanAturanAlertRequest;
use App\Models\AnalisisSentimen;
use App\Models\AturanAlert;
use App\Models\PengaturanAlert;
use App\Models\RiwayatAlert;
use App\Services\Alert\PemeriksaAturan;
use App\Services\Alert\PengirimTelegram;
use App\Services\Alert\PesanAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AturanAlertController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/alert/Index', [
            'aturan' => AturanAlert::query()
                ->withCount('riwayat')
                ->orderBy('nama')
                ->get()
                ->map(fn (AturanAlert $a) => [
                    ...$a->only(['id', 'nama', 'jenis', 'kanal', 'aktif', 'jendela_jam', 'jeda_minimal_jam', 'riwayat_count']),
                    'dipicu_terakhir_at' => $a->dipicu_terakhir_at,
                ])
                ->all(),
            'riwayat' => RiwayatAlert::query()
                ->with('aturan:id,nama')
                ->orderByDesc('dipicu_at')
                ->limit(50)
                ->get()
                ->map(fn (RiwayatAlert $r) => [
                    ...$r->only(['id', 'ringkasan', 'status_kirim', 'pesan_error']),
                    'aturan' => $r->aturan?->nama,
                    'dipicu_at' => $r->dipicu_at,
                    'dibaca_at' => $r->dibaca_at,
                ])
                ->all(),
            // Telegram belum terkonfigurasi adalah kegagalan diam: aturan
            // tersimpan rapi, terpicu benar, dan tidak seorang pun menerima
            // apa pun. Ditampilkan di halaman, bukan hanya di log.
            'telegramSiap' => PengaturanAlert::aktif()->siap(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/alert/Form', [
            'aturan' => null,
        ]);
    }

    public function store(SimpanAturanAlertRequest $request): RedirectResponse
    {
        $aturan = AturanAlert::create($this->data($request));

        return to_route('admin.alert.index')->with('sukses', "Aturan {$aturan->nama} ditambahkan.");
    }

    public function edit(AturanAlert $alert): Response
    {
        return Inertia::render('admin/alert/Form', [
            'aturan' => $alert,
        ]);
    }

    public function update(SimpanAturanAlertRequest $request, AturanAlert $alert): RedirectResponse
    {
        $alert->update($this->data($request));

        return to_route('admin.alert.index')->with('sukses', "Aturan {$alert->nama} diperbarui.");
    }

    public function destroy(AturanAlert $alert): RedirectResponse
    {
        $alert->delete();

        return to_route('admin.alert.index')->with('sukses', "Aturan {$alert->nama} dihapus.");
    }

    /**
     * Uji satu aturan tanpa menunggu scheduler dan tanpa menulis riwayat.
     *
     * Ada karena ambang alert hampir tidak pernah benar pada percobaan pertama.
     * Tanpa tombol ini, menyetel ambang berarti menunggu 15 menit per percobaan
     * sambil mengirimi grup Diskominfo pesan uji.
     */
    public function uji(AturanAlert $alert, PemeriksaAturan $pemeriksa): RedirectResponse
    {
        $hasil = $pemeriksa->nilai($alert);

        return back()->with('sukses', $hasil === null
            ? "Aturan {$alert->nama} tidak terpicu dengan data saat ini."
            : "Terpicu: {$hasil['ringkasan']} (uji coba, tidak dikirim dan tidak masuk riwayat).");
    }

    /**
     * Simpan kredensial Telegram dari halaman Pengaturan sistem.
     *
     * Dua bidang dengan perlakuan yang sengaja berbeda.
     *
     * Chat ID dikirim apa adanya: kosong berarti kosongkan, dan setelah itu
     * kanal berhenti sampai diisi lagi. Ia bukan rahasia, ditampilkan penuh di
     * layar, jadi mengetiknya ulang tidak menyusahkan siapa pun.
     *
     * Token tidak pernah ditampilkan kembali, jadi bidang yang kosong berarti
     * "biarkan yang tersimpan", bukan "hapus". Tanpa aturan itu, admin yang
     * hanya ingin mengganti chat ID akan menghapus tokennya sendiri hanya
     * karena kotak token memang tidak bisa terisi otomatis, dan alert berhenti
     * tanpa satu pun pesan yang menyebutkan sebabnya.
     */
    public function simpanTelegram(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // Token bot berbentuk `<id bot>:<rahasia>`, sekitar 46 karakter,
            // tanpa spasi. Pola ini menahan salah tempel yang paling sering
            // terjadi, yaitu menempel nama bot atau tautan undangannya.
            'telegram_token' => ['nullable', 'string', 'min:20', 'max:200', 'regex:/^\d+:[A-Za-z0-9_-]+$/'],
            // Grup memakai angka negatif panjang, kanal publik memakai
            // @namakanal. Keduanya sah, dan tidak ada bentuk ketiga.
            'telegram_chat_id' => ['nullable', 'string', 'max:40', 'regex:/^(-?\d+|@[A-Za-z0-9_]{5,32})$/'],
        ], [
            'telegram_token.regex' => 'Token bot berbentuk angka, titik dua, lalu rahasianya. Contoh: 123456789:AAH...',
            'telegram_token.min' => 'Token terlihat terlalu pendek. Periksa lagi hasil salinnya dari BotFather.',
            'telegram_chat_id.regex' => 'Chat ID berupa angka, biasanya negatif untuk grup, atau @namakanal untuk kanal publik.',
        ]);

        $pengaturan = PengaturanAlert::aktif();

        $ubahan = ['telegram_chat_id' => $data['telegram_chat_id'] ?? null];

        if (($data['telegram_token'] ?? '') !== '') {
            $ubahan['telegram_token'] = $data['telegram_token'];
        }

        $pengaturan->update($ubahan);

        return back()->with('sukses', $pengaturan->refresh()->siap()
            ? 'Pengaturan Telegram disimpan. Tekan Kirim uji untuk memastikan pesannya sampai ke grup.'
            : 'Pengaturan Telegram disimpan, tetapi belum lengkap. Alert tidak akan terkirim sampai token dan chat ID keduanya terisi.');
    }

    /**
     * Kirim satu notifikasi uji ke Telegram, berisi berita negatif terakhir.
     *
     * Kalimat tetap hanya membuktikan token dan chat ID benar. Yang perlu
     * dilihat sebelum alert sungguhan menyala adalah bentuk pesannya di layar
     * ponsel: panjang judul, nama media, dan tautan yang tidak melebar jadi
     * kartu. Karena itu yang dikirim berita sungguhan.
     */
    public function ujiTelegram(PengirimTelegram $telegram): RedirectResponse
    {
        $hasil = $telegram->kirim($this->pesanUji());

        return back()->with(
            $hasil['terkirim'] ? 'sukses' : 'galat',
            $hasil['terkirim']
                ? 'Notifikasi uji terkirim ke grup Telegram.'
                : "Telegram menolak: {$hasil['error']}",
        );
    }

    /**
     * Isi notifikasi uji: alert berita negatif dengan data arsip yang nyata.
     *
     * Disusun PesanAlert yang sama dengan `alert:periksa`, dan isinya tidak
     * diberi satu kata tambahan pun. Yang membedakannya dari alert sungguhan
     * hanya satu baris penanda di kepala pesan. Tombol ini ada untuk
     * memperlihatkan teks yang akan terkirim nanti, dan kalimat "ini cuma
     * contoh" yang disisipkan ke dalamnya membuat yang terlihat di layar bukan
     * lagi yang akan datang saat aturan benar-benar menyala.
     *
     * Angkanya dihitung dari arsip, tidak dikarang. Preview yang menampilkan
     * angka palsu akan dibaca sebagai keadaan sungguhan oleh orang yang
     * kebetulan melihat layar itu.
     *
     * Aturannya tidak disimpan ke database. Ia hanya wadah nama dan jenis, dan
     * namanya diambil dari aturan lonjakan negatif yang benar-benar ada supaya
     * pesannya persis seperti nanti.
     */
    private function pesanUji(): string
    {
        // Join, bukan relasi Eloquent. Tabel artikel memakai scope global
        // MilikMedia, dan isi notifikasi uji tidak boleh berubah tergantung
        // siapa yang menekan tombolnya.
        $berita = AnalisisSentimen::query()
            ->join('artikel', 'artikel.id', '=', 'analisis_sentimen.artikel_id')
            ->leftJoin('media', 'media.id', '=', 'artikel.media_id')
            ->where('analisis_sentimen.relevan', true)
            ->where('analisis_sentimen.label_efektif', LabelSentimen::Negatif)
            ->orderByDesc('artikel.diambil_at')
            ->first([
                'analisis_sentimen.reason_summary',
                'artikel.judul',
                'artikel.url',
                'artikel.diambil_at',
                'media.nama as media',
            ]);

        $aturan = new AturanAlert;
        $aturan->jenis = 'berita_negatif';
        $aturan->nama = AturanAlert::query()->where('jenis', 'berita_negatif')->value('nama')
            ?? 'Peringatan berita negatif';

        if ($berita === null) {
            return PesanAlert::uji(PesanAlert::alert(
                $aturan,
                'Belum ada berita negatif di arsip. Kalau pesan ini terbaca, kanalnya sudah benar.',
            ));
        }

        return PesanAlert::uji(PesanAlert::berita(
            $aturan,
            $berita->only(['judul', 'url', 'diambil_at', 'media']),
            $berita->reason_summary,
        ));
    }

    /** @return array<string, mixed> */
    private function data(SimpanAturanAlertRequest $request): array
    {
        $data = $request->validated();

        $data['penerima'] = $data['penerima'] ?? [];
        $data['kondisi'] = $data['kondisi'] ?? [];
        $data['aktif'] = $data['aktif'] ?? true;

        return $data;
    }
}
