<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LabelSentimen;
use App\Http\Controllers\Controller;
use App\Models\Artikel;
use App\Models\GoldSet;
use App\Models\KonteksPantauan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ruang kerja gold set (F-19).
 *
 * Halaman ini yang menentukan gold set jadi atau tidak. Melabeli 400 artikel
 * lewat halaman admin biasa memakan waktu tiga kali lebih lama, dan pekerjaan
 * berhenti di baris ke-120.
 */
class PelabelanController extends Controller
{
    /** Target gold set sesuai dokumen 07. */
    private const TARGET = 400;

    /**
     * Mode pengambilan artikel.
     *
     * Hanya `acak` yang menghasilkan perkiraan akurasi tanpa bias — sampelnya
     * mewakili keseluruhan artikel. Tiga mode lain memilih artikel berdasarkan
     * tebakan model, jadi berguna untuk mengukur F1 per kelas tapi tidak boleh
     * dipakai menghitung akurasi keseluruhan. Pemisahan ini disebut di UI,
     * bukan hanya di sini, supaya tidak tercampur tanpa sadar.
     *
     * @var array<string, string>
     */
    private const MODE = [
        'acak' => 'Acak — untuk akurasi keseluruhan',
        'relevan' => 'Kemungkinan relevan — hemat tekanan "tidak relevan"',
        'negatif' => 'Ditebak negatif — untuk mengukur F1 negatif',
        'ragu' => 'Perlu review — keyakinan model rendah',
    ];

    public function index(Request $request): Response
    {
        $ronde = (int) $request->query('ronde', 1);
        $konteks = $this->konteks($request);
        $mode = array_key_exists((string) $request->query('mode'), self::MODE)
            ? (string) $request->query('mode')
            : 'acak';

        return Inertia::render('admin/Pelabelan', [
            'konteksTersedia' => KonteksPantauan::query()->aktif()->get(['id', 'nama', 'deskripsi', 'utama']),
            'konteksAktif' => $konteks,
            'ronde' => $ronde,
            'mode' => $mode,
            'modeTersedia' => self::MODE,
            'sisaPerMode' => $konteks ? $this->sisaPerMode($konteks, $ronde) : null,
            // `artikel` diisi saat pelabel membuka kembali keputusan lama.
            'tugas' => $konteks ? $this->tugasBerikutnya($konteks, $ronde, $request->query('artikel'), $mode) : null,
            'sedangMengulang' => $request->query('artikel') !== null,
            // Riwayat dibaca dari database, bukan dari tumpukan di browser.
            // Riwayat di browser hilang begitu halaman dimuat ulang, dan
            // pelabel kehilangan jalan kembali ke keputusan sebelumnya.
            'riwayat' => $konteks ? $this->riwayat($konteks, $ronde, $request->query('artikel')) : null,
            'progres' => [
                'selesai' => GoldSet::where('ronde', $ronde)->count(),
                'target' => self::TARGET,
                // Per konteks, supaya terlihat pembagiannya belum merata dan
                // konteks utama tidak tertinggal tanpa disadari.
                'perKonteks' => $konteks === null ? 0 : GoldSet::where('ronde', $ronde)
                    ->where('konteks_pantauan_id', $konteks->id)
                    ->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'artikel_id' => ['required', 'exists:artikel,id'],
            'konteks_pantauan_id' => ['required', 'exists:konteks_pantauan,id'],
            'relevan_gold' => ['required', 'boolean'],
            // Artikel yang tidak relevan tidak punya nada terhadap konteks itu;
            // netral dipakai sebagai nilai penyimpanan, bukan sebagai penilaian.
            'label_gold' => ['required_if:relevan_gold,true', 'nullable', new Enum(LabelSentimen::class)],
            'catatan' => ['nullable', 'string', 'max:1000'],
            'ronde' => ['required', 'integer', 'in:1,2'],
        ]);

        GoldSet::updateOrCreate(
            [
                'artikel_id' => $data['artikel_id'],
                'konteks_pantauan_id' => $data['konteks_pantauan_id'],
                'ronde' => $data['ronde'],
            ],
            [
                'relevan_gold' => $data['relevan_gold'],
                'label_gold' => $data['relevan_gold'] ? $data['label_gold'] : LabelSentimen::Netral->value,
                'catatan' => $data['catatan'] ?? null,
                'dilabeli_oleh' => $request->user()->id,
                'dilabeli_at' => now(),
            ],
        );

        // Bukan back(): saat pelabel baru memperbaiki label lama, URL asalnya
        // masih memuat ?artikel=<id> dan ia akan disodori artikel yang sama
        // terus-menerus. Selalu kembali ke antrean, tanpa parameter itu.
        return to_route('admin.pelabelan.index', [
            'konteks' => $data['konteks_pantauan_id'],
            'ronde' => $data['ronde'],
            'mode' => $request->query('mode', 'acak'),
        ]);
    }

    /**
     * Jalan kembali ke keputusan yang sudah dibuat.
     *
     * `sebelumnya` dihitung relatif terhadap artikel yang sedang dibuka, bukan
     * selalu yang terakhir dilabeli — tanpa itu, menekan panah kiri dua kali
     * berturut-turut hanya bolak-balik di satu artikel yang sama.
     *
     * @return array{sebelumnya: int|null, berikutnya: int|null, terakhir: list<array<string, mixed>>}
     */
    private function riwayat(KonteksPantauan $konteks, int $ronde, ?string $artikelDibuka): array
    {
        $dasar = fn () => GoldSet::query()
            ->where('gold_set.konteks_pantauan_id', $konteks->id)
            ->where('gold_set.ronde', $ronde)
            ->join('artikel', 'artikel.id', '=', 'gold_set.artikel_id');

        $waktuDibuka = $artikelDibuka === null
            ? null
            : GoldSet::query()
                ->where('konteks_pantauan_id', $konteks->id)
                ->where('ronde', $ronde)
                ->where('artikel_id', $artikelDibuka)
                ->value('dilabeli_at');

        $sebelumnya = $dasar()
            ->when($waktuDibuka, fn ($q) => $q->where('gold_set.dilabeli_at', '<', $waktuDibuka))
            ->orderByDesc('gold_set.dilabeli_at')
            ->value('gold_set.artikel_id');

        // Maju hanya masuk akal saat sedang menelusuri riwayat. Di ujung depan
        // antrean, "berikutnya" adalah artikel yang belum dilabeli — itu sudah
        // jadi tugas yang tampil dengan sendirinya.
        $berikutnya = $waktuDibuka === null
            ? null
            : $dasar()
                ->where('gold_set.dilabeli_at', '>', $waktuDibuka)
                ->orderBy('gold_set.dilabeli_at')
                ->value('gold_set.artikel_id');

        return [
            'sebelumnya' => $sebelumnya === null ? null : (int) $sebelumnya,
            'berikutnya' => $berikutnya === null ? null : (int) $berikutnya,
            'terakhir' => $dasar()
                ->orderByDesc('gold_set.dilabeli_at')
                ->limit(20)
                ->get([
                    'gold_set.artikel_id',
                    'gold_set.label_gold',
                    'gold_set.relevan_gold',
                    'artikel.judul',
                ])
                ->map(fn ($baris) => [
                    'artikel_id' => (int) $baris->artikel_id,
                    'judul' => $baris->judul,
                    'label' => $baris->relevan_gold ? $baris->label_gold : null,
                    'relevan' => (bool) $baris->relevan_gold,
                ])
                ->all(),
        ];
    }

    /**
     * Artikel berikutnya yang belum dilabeli pada ronde ini.
     *
     * Urutannya diacak tapi tetap: `md5(id || ronde)` menghasilkan susunan yang
     * tidak berhubungan dengan waktu masuk maupun media, dan sama persis setiap
     * kali dijalankan.
     *
     * Keduanya diperlukan. Mengambil berurutan membuat gold set berisi artikel
     * dari periode dan media yang berdekatan, dan angka akurasinya tidak
     * mewakili apa pun. Sedangkan inRandomOrder() tidak bisa dipakai: seed-nya
     * diabaikan di PostgreSQL — Grammar::compileRandom() mengembalikan RANDOM()
     * apa adanya — sehingga tiap muat ulang menyodorkan artikel berbeda dan
     * pelabel kehilangan artikel yang sedang dibacanya.
     *
     * @return array<string, mixed>|null
     */
    /**
     * Antrean artikel yang belum dilabeli, disaring menurut mode.
     *
     * Mode selain `acak` menyaring berdasarkan tebakan model. Itu disengaja
     * untuk mengumpulkan cukup contoh kelas langka — berita negatif hanya 4,5%
     * dari pasangan relevan, jadi sampel acak murni tidak akan pernah memuat
     * cukup banyak untuk mengukur F1 negatif. Konsekuensinya sampel itu bias
     * dan tidak boleh dipakai menghitung akurasi keseluruhan.
     */
    private function antrean(KonteksPantauan $konteks, int $ronde, string $mode): \Illuminate\Database\Eloquent\Builder
    {
        $kueri = Artikel::query()
            ->asli()
            ->whereNotNull('artikel.isi')
            ->whereNotExists(function ($sub) use ($konteks, $ronde) {
                $sub->selectRaw(1)
                    ->from('gold_set')
                    ->whereColumn('gold_set.artikel_id', 'artikel.id')
                    ->where('gold_set.konteks_pantauan_id', $konteks->id)
                    ->where('gold_set.ronde', $ronde);
            })
            ->orderByRaw('md5(artikel.id::text || ?)', [(string) $ronde]);

        if ($mode === 'acak') {
            return $kueri;
        }

        return $kueri->whereExists(function ($sub) use ($konteks, $mode) {
            $sub->selectRaw(1)
                ->from('analisis_sentimen')
                ->whereColumn('analisis_sentimen.artikel_id', 'artikel.id')
                ->where('analisis_sentimen.konteks_pantauan_id', $konteks->id)
                ->where('analisis_sentimen.relevan', true)
                ->when($mode === 'negatif', fn ($q) => $q->where('analisis_sentimen.label_model', 'negatif'))
                ->when($mode === 'ragu', fn ($q) => $q->where('analisis_sentimen.perlu_review', true));
        });
    }

    /**
     * Sisa artikel per mode, supaya pelabel tahu mode mana yang masih ada isinya
     * sebelum berpindah dan menemukannya kosong.
     *
     * @return array<string, int>
     */
    private function sisaPerMode(KonteksPantauan $konteks, int $ronde): array
    {
        $sisa = [];

        foreach (array_keys(self::MODE) as $mode) {
            $sisa[$mode] = $this->antrean($konteks, $ronde, $mode)->count();
        }

        return $sisa;
    }

    private function tugasBerikutnya(
        KonteksPantauan $konteks,
        int $ronde,
        ?string $paksaId = null,
        string $mode = 'acak',
    ): ?array {
        $kolom = ['artikel.id', 'artikel.media_id', 'artikel.judul', 'artikel.ringkasan',
            'artikel.isi', 'artikel.url', 'artikel.dipublikasikan_at'];

        // Membuka kembali artikel yang sudah dilabeli tidak boleh lewat antrean:
        // saringan "belum dilabeli" di sana justru menyingkirkannya.
        $artikel = $paksaId !== null
            ? Artikel::query()->with('media:id,nama')->find($paksaId, $kolom)
            : $this->antrean($konteks, $ronde, $mode)->with('media:id,nama')->first($kolom);

        if ($artikel === null) {
            return null;
        }

        return [
            'artikel' => [
                'id' => $artikel->id,
                'judul' => $artikel->judul,
                // Isi dipotong: pelabel butuh cukup konteks untuk memutuskan,
                // bukan membaca berita utuh. Target 20 detik per artikel.
                'kutipan' => mb_substr($artikel->isi ?? '', 0, 1200),
                'url' => $artikel->url,
                'media' => $artikel->media?->nama,
                'dipublikasikan_at' => $artikel->dipublikasikan_at,
            ],
            // Jawaban model sengaja ikut dikirim tapi tidak boleh dirender
            // sebelum pelabel memutuskan. Lihat catatan di komponen Vue-nya.
            'tebakanModel' => $this->tebakanModel($artikel->id, $konteks->id),
            // Terisi hanya saat artikel lama dibuka kembali, supaya pelabel
            // tahu apa yang dulu ia pilih sebelum mengubahnya.
            'labelTersimpan' => $this->labelTersimpan($artikel->id, $konteks->id, $ronde),
        ];
    }

    /** @return array<string, mixed>|null */
    private function labelTersimpan(int $artikelId, int $konteksId, int $ronde): ?array
    {
        $baris = GoldSet::query()
            ->where('artikel_id', $artikelId)
            ->where('konteks_pantauan_id', $konteksId)
            ->where('ronde', $ronde)
            ->first(['label_gold', 'relevan_gold', 'catatan']);

        return $baris === null ? null : [
            'label' => $baris->relevan_gold ? $baris->label_gold->value : null,
            'relevan' => (bool) $baris->relevan_gold,
            'catatan' => $baris->catatan,
        ];
    }

    /** @return array<string, mixed>|null */
    private function tebakanModel(int $artikelId, int $konteksId): ?array
    {
        $analisis = \App\Models\AnalisisSentimen::query()
            ->where('artikel_id', $artikelId)
            ->where('konteks_pantauan_id', $konteksId)
            ->first(['relevan', 'label_model', 'keyakinan']);

        return $analisis === null ? null : [
            'relevan' => $analisis->relevan,
            'label' => $analisis->label_model?->value,
            'keyakinan' => $analisis->keyakinan,
        ];
    }

    private function konteks(Request $request): ?KonteksPantauan
    {
        $id = $request->query('konteks');

        return $id
            ? KonteksPantauan::find($id)
            : KonteksPantauan::query()->aktif()->first();
    }
}
