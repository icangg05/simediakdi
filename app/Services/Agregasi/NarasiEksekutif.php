<?php

declare(strict_types=1);

namespace App\Services\Agregasi;

use App\Ai\Agents\AnalisEksekutif;
use App\Models\Artikel;
use App\Models\NarasiEksekutif as Baris;
use App\Models\PengaturanAi;
use App\Services\Ai\RotasiKunciGemini;
use App\Support\Waktu;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use RuntimeException;

/**
 * Membuat bagian naratif dashboard eksekutif dengan Gemini.
 *
 * Pembagian kerjanya tegas dan tidak boleh kabur. Postgres yang menghitung,
 * Gemini yang menjelaskan. Angka yang dikirim ke Gemini berstatus fakta yang
 * sudah jadi, dan statistik tiap topik ditambahkan di sini setelah `artikel_ids`
 * dari model divalidasi. Model tidak pernah diminta menjumlahkan apa pun, karena
 * angka yang salah dari model tetap terbaca sebagai angka yang wajar di layar
 * dan tidak menimbulkan galat apa pun yang bisa ditangkap.
 *
 * Dipanggil scheduler, bukan controller. Wali Kota yang membuka dashboard tidak
 * pernah menunggu Gemini, dan me-refresh halaman tidak menambah satu pun
 * permintaan ke Google.
 */
class NarasiEksekutif
{
    /**
     * Pilihan periode, dalam jumlah hari ke belakang dari hari ini WITA.
     *
     * Sama persis dengan pintasan di PemilihRentangTanggal.vue. Kalau kedua
     * daftar ini berbeda, pengguna menekan pintasan "30 hari" lalu mendapat
     * halaman tanpa ringkasan tanpa penjelasan apa pun.
     */
    public const PRESET = ['today' => 0, '7d' => 6, '30d' => 29, '90d' => 89];

    /**
     * Batas jumlah topik per periode.
     *
     * Rentang yang lebih panjang boleh punya lebih banyak topik, tetapi tidak
     * banyak lebih. Yang membatasi bukan datanya, melainkan satu layar ponsel.
     */
    private const MAKS_TOPIK = ['today' => 5, '7d' => 7, '30d' => 8, '90d' => 8];

    /**
     * Batas artikel yang dikirim sebagai bahan topik.
     *
     * Rentang 90 hari bisa memuat ribuan artikel, dan mengirim semuanya berarti
     * satu prompt raksasa tiap hari untuk hasil yang tidak lebih baik. Yang
     * dikirim adalah artikel yang paling menjelaskan periode itu, lihat
     * bahanArtikel().
     */
    private const MAKS_ARTIKEL = 80;

    public function __construct(
        private RingkasanEksekutif $ringkasan,
        private RotasiKunciGemini $rotasi,
    ) {}

    /** @return array{CarbonImmutable, CarbonImmutable} rentang WITA untuk satu preset */
    public function rentang(string $periode): array
    {
        $sampai = CarbonImmutable::parse(Waktu::tanggalWita(now()));

        return [$sampai->subDays(self::PRESET[$periode] ?? 6), $sampai];
    }

    /**
     * Nama preset untuk rentang yang sedang dilihat, kalau memang salah satunya.
     *
     * Pemilih rentang mengizinkan tanggal bebas, sedangkan narasi hanya dibuat
     * untuk empat preset. Rentang di luar itu mengembalikan null, dan halaman
     * menampilkan statistiknya saja tanpa ringkasan. Membuat narasi untuk setiap
     * kombinasi tanggal yang mungkin diketik berarti satu panggilan Gemini per
     * penekanan tombol Terapkan.
     */
    public function preset(CarbonImmutable $dari, CarbonImmutable $sampai): ?string
    {
        $hariIni = Waktu::tanggalWita(now());

        if ($sampai->toDateString() !== $hariIni) {
            return null;
        }

        foreach (self::PRESET as $nama => $mundur) {
            if ($dari->toDateString() === $sampai->subDays($mundur)->toDateString()) {
                return $nama;
            }
        }

        return null;
    }

    /**
     * Narasi terakhir yang berhasil dibuat untuk satu preset.
     *
     * Diurutkan menurut tanggal, bukan disaring tepat pada rentang hari ini.
     * Saat Gemini gagal semalam, yang tampil adalah narasi kemarin lengkap
     * dengan waktu pembuatannya, dan itu jauh lebih berguna daripada bagian
     * kosong. Statistik di sekelilingnya tetap dari data terbaru.
     */
    public function terbaru(string $periode): ?Baris
    {
        return Baris::query()
            ->where('periode', $periode)
            ->orderByDesc('sampai')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Membuat ulang narasi satu preset, kecuali datanya belum berubah.
     *
     * @return Baris|null null berarti tidak ada yang dikerjakan, entah karena
     *                    periode itu belum punya berita berlabel atau karena
     *                    bahannya sama persis dengan generasi sebelumnya
     */
    public function perbarui(string $periode): ?Baris
    {
        [$dari, $sampai] = $this->rentang($periode);

        $artikel = $this->bahanArtikel($dari, $sampai);

        if ($artikel->isEmpty()) {
            return null;
        }

        $sidik = $this->sidik($periode, $dari, $sampai, $artikel);

        if ($this->terbaru($periode)?->sidik === $sidik) {
            return null;
        }

        $pengaturan = PengaturanAi::aktif();
        $model = (string) $pengaturan->model;

        $jawaban = $this->rotasi->jalankan(fn () => (new AnalisEksekutif)->prompt(
            $this->prompt($periode, $dari, $sampai, $artikel),
            provider: Lab::Gemini,
            model: $model,
            // Prompt narasi jauh lebih panjang daripada prompt klasifikasi satu
            // artikel, dan jawabannya memuat delapan topik sekaligus.
            timeout: (int) config('ai.gemini_timeout') * 3,
        ));

        $topik = $this->topik($jawaban['topik'] ?? [], $artikel, $periode);

        return Baris::updateOrCreate(
            ['periode' => $periode, 'dari' => $dari->toDateString(), 'sampai' => $sampai->toDateString()],
            [
                'nada' => (string) ($jawaban['nada'] ?? 'netral'),
                'judul' => $this->potong((string) ($jawaban['judul'] ?? ''), 300),
                'ringkasan' => (string) ($jawaban['ringkasan'] ?? ''),
                'penjelasan_tren' => (string) ($jawaban['penjelasan_tren'] ?? ''),
                'poin' => array_slice(array_map(strval(...), (array) ($jawaban['poin'] ?? [])), 0, 4),
                'perhatian' => $this->perhatian($jawaban['perhatian'] ?? []),
                'nada_ringkas' => array_map(strval(...), (array) ($jawaban['nada_ringkas'] ?? [])),
                'topik' => $topik,
                'jumlah_artikel' => $artikel->count(),
                'sidik' => $sidik,
                'model' => $model,
                'dibuat_at' => now(),
            ],
        );
    }

    /**
     * Artikel yang mewakili periode, dibatasi supaya prompt tetap masuk akal.
     *
     * Urutannya bukan sekadar terbaru. Artikel negatif didahulukan karena bagian
     * dashboard yang paling menentukan tindakan pimpinan adalah isu yang perlu
     * diperhatikan, dan pada rentang 90 hari berita negatif selalu kalah jumlah
     * sehingga urutan kronologis murni bisa membuangnya seluruhnya dari prompt.
     *
     * Judul yang sama persis disingkirkan. Berita kantor berita yang dimuat
     * ulang delapan portal daerah adalah pemandangan biasa di Kendari, dan
     * tanpa penyingkiran itu satu peristiwa bisa memakan sepersepuluh jatah
     * masukan lalu muncul sebagai topik yang terlihat jauh lebih besar daripada
     * keadaannya.
     *
     * ponytail: pencocokan judul persis, bukan kemiripan, dan tanpa batas
     * jumlah per media. Tambahkan kalau daftar topik mulai didominasi satu
     * media yang menulis ulang berita yang sama dengan judul berbeda.
     *
     * @return Collection<int, object>
     */
    private function bahanArtikel(CarbonImmutable $dari, CarbonImmutable $sampai): Collection
    {
        return DB::table('artikel as a')
            ->join('analisis_sentimen as s', 's.artikel_id', '=', 'a.id')
            ->leftJoin('media as m', 'm.id', '=', 'a.media_id')
            ->where('s.relevan', true)
            ->whereNotNull('s.label_efektif')
            ->whereRaw(Artikel::waktuTerbit('a').' BETWEEN ? AND ?', [
                Waktu::awalHari($dari->toDateString()),
                Waktu::akhirHari($sampai->toDateString()),
            ])
            ->orderByRaw("(s.label_efektif = 'negatif') DESC")
            ->orderByDesc('a.diambil_at')
            // Diambil lebih banyak daripada yang dikirim, karena penyingkiran
            // judul kembar terjadi setelah kueri. Tanpa kelonggaran ini, satu
            // periode yang penuh berita muatan ulang mengirim jauh di bawah
            // batas dan topiknya jadi tipis.
            ->limit(self::MAKS_ARTIKEL * 3)
            ->get([
                'a.id',
                'a.judul',
                'a.ringkasan',
                'a.diambil_at',
                'a.media_id',
                's.label_efektif AS label',
                'm.nama AS media',
            ])
            ->unique(fn ($a) => mb_strtolower(trim($a->judul)))
            ->take(self::MAKS_ARTIKEL)
            ->values();
    }

    /**
     * Sidik bahan masukan.
     *
     * Isinya cukup untuk menangkap empat perubahan yang benar-benar mengubah
     * narasi: artikel baru masuk, rentangnya bergeser sehari, label yang
     * dikoreksi admin, dan instruksi agen yang disunting. Kalau keempatnya sama,
     * kalimat yang dihasilkan Gemini juga akan sama, dan memanggilnya lagi hanya
     * membakar kuota.
     *
     * Sidik instruksi bukan kelebihan. Tanpa itu, menyunting prompt tidak
     * menghasilkan apa pun sampai ada berita baru terbit, dan pada rentang 90
     * hari yang datanya sudah mapan itu bisa berarti berhari-hari membaca
     * kalimat dari prompt yang sudah diganti.
     *
     * @param  Collection<int, object>  $artikel
     */
    private function sidik(string $periode, CarbonImmutable $dari, CarbonImmutable $sampai, Collection $artikel): string
    {
        return sha1(implode('|', [
            $periode,
            $dari->toDateString(),
            $sampai->toDateString(),
            $artikel->count(),
            $artikel->map(fn ($a) => $a->id.':'.$a->label)->implode(','),
            sha1((string) (new AnalisEksekutif)->instructions()),
        ]));
    }

    /** @param  Collection<int, object>  $artikel */
    private function prompt(string $periode, CarbonImmutable $dari, CarbonImmutable $sampai, Collection $artikel): string
    {
        $kpi = $this->ringkasan->kpi($dari, $sampai);
        $media = $this->ringkasan->peringkatMedia($dari, $sampai, 5);

        $daftar = $artikel->map(fn ($a) => [
            'id' => $a->id,
            'judul' => $a->judul,
            'ringkasan' => $a->ringkasan,
            'sentimen' => $a->label,
            'media' => $a->media,
            'tanggal' => Waktu::tanggalWita(CarbonImmutable::parse($a->diambil_at)),
        ])->values();

        $statistik = json_encode([
            'rentang' => ['dari' => $dari->toDateString(), 'sampai' => $sampai->toDateString()],
            'berita_relevan_berlabel' => $kpi['berlabel'],
            'selisih_dari_periode_sebelumnya' => $kpi['berlabel_selisih'],
            'positif' => $kpi['positif'],
            'positif_selisih' => $kpi['positif_selisih'],
            'netral' => $kpi['netral'],
            'negatif' => $kpi['negatif'],
            'negatif_selisih' => $kpi['negatif_selisih'],
            'negatif_persen' => $kpi['negatif_persen'],
            'media_aktif' => $kpi['media_aktif'],
            'media_teratas' => $media,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $maksTopik = self::MAKS_TOPIK[$periode] ?? 7;

        return <<<TEKS
        STATISTIK PERIODE, sudah dihitung sistem dari basis data. Pakai apa
        adanya, jangan dihitung ulang.

        {$statistik}

        DAFTAR ARTIKEL yang mewakili periode ini. Kelompokkan menjadi paling
        banyak {$maksTopik} topik. Pakai hanya id yang ada di daftar ini.

        {$daftar->toJson(JSON_UNESCAPED_UNICODE)}
        TEKS;
    }

    /**
     * Statistik tiap topik, dihitung di sini dan bukan oleh Gemini.
     *
     * @param  mixed  $mentah  daftar topik dari model
     * @param  Collection<int, object>  $artikel
     * @return list<array<string, mixed>>
     */
    private function topik(mixed $mentah, Collection $artikel, string $periode): array
    {
        $sah = $artikel->keyBy('id');
        $sudahDipakai = [];
        $hasil = [];

        foreach ((array) $mentah as $t) {
            $judul = trim((string) ($t['judul'] ?? ''));

            // Judul topik harus kalimat yang menjelaskan isunya, bukan satu
            // atau dua kata kunci. "Parkir" tidak memberi tahu pimpinan apa pun
            // yang belum diketahuinya, dan halaman isu sudah menyediakan daftar
            // kata kunci untuk keperluan itu.
            //
            // Dilewati, bukan menggagalkan seluruh narasi. Panggilan Gemini-nya
            // sudah dibayar, dan satu judul yang malas tidak sepadan dengan
            // mengosongkan ringkasan dan tujuh topik lain yang sudah benar.
            if (count(preg_split('/\s+/', $judul) ?: []) < 4) {
                Log::warning('Topik dari Gemini dilewati, judulnya bukan kalimat.', ['judul' => $judul]);

                continue;
            }

            $ids = array_values(array_unique(array_map(intval(...), (array) ($t['artikel_ids'] ?? []))));

            // Id yang tidak ada di daftar masukan berarti model mengarang atau
            // salah menyalin, dan topik yang dibangun di atasnya akan mengklaim
            // jumlah berita yang tidak pernah ada. Ditolak seluruhnya, bukan
            // disaring diam-diam, supaya kegagalannya terlihat di log.
            $asing = array_diff($ids, $sah->keys()->all());

            if ($asing !== []) {
                throw new RuntimeException(
                    'Gemini mengembalikan artikel_id yang tidak ada di daftar masukan: '
                    .implode(', ', $asing).'.'
                );
            }

            // Satu artikel satu topik. Model kadang menyalin id yang sama ke dua
            // topik, dan tanpa penjagaan ini jumlah berita per topik kalau
            // ditotal melebihi jumlah berita periodenya.
            $ids = array_values(array_diff($ids, $sudahDipakai));
            $sudahDipakai = array_merge($sudahDipakai, $ids);

            if ($ids === []) {
                continue;
            }

            $anggota = $sah->only($ids);

            $negatif = $anggota->where('label', 'negatif')->count();
            $netral = $anggota->where('label', 'netral')->count();
            $positif = $anggota->where('label', 'positif')->count();
            $jumlahMedia = $anggota->pluck('media_id')->filter()->unique()->count();
            $beruntun = $this->hariBeruntun($anggota);

            $skor = $this->skorPrioritas($negatif, $jumlahMedia, $beruntun);

            $hasil[] = [
                'judul' => $this->potong($judul, 300),
                'ringkasan' => (string) ($t['ringkasan'] ?? ''),
                'artikel_ids' => $ids,
                'jumlah_artikel' => count($ids),
                'jumlah_media' => $jumlahMedia,
                'negatif' => $negatif,
                'netral' => $netral,
                'positif' => $positif,
                'hari_beruntun' => $beruntun,
                'sentimen_dominan' => $this->dominan($negatif, $netral, $positif),
                'skor_prioritas' => $skor,
                'prioritas' => match (true) {
                    $skor >= 6 => 'tinggi',
                    $skor >= 3 => 'sedang',
                    default => 'rendah',
                },
                // Urutan tampil, dokumen bagian 51. Jumlah media dibobot dua
                // kali jumlah artikel: satu isu di lima media adalah keadaan
                // yang berbeda dari satu media yang menulis lima kali.
                'skor_urut' => count($ids) + ($jumlahMedia * 2) + ($negatif * 0.5),
            ];
        }

        usort($hasil, fn ($a, $b) => $b['skor_urut'] <=> $a['skor_urut']);

        return array_slice($hasil, 0, self::MAKS_TOPIK[$periode] ?? 7);
    }

    /**
     * Skor prioritas isu, dokumen bagian 19.
     *
     * ponytail: tanpa komponen pertumbuhan dibanding periode sebelumnya.
     * Menghitungnya menuntut pencocokan topik antar periode, dan tanpa embedding
     * pencocokan itu sendiri yang paling sering salah. Tambahkan setelah ada
     * data nyata yang menunjukkan pencocokan judulnya cukup stabil.
     */
    private function skorPrioritas(int $negatif, int $jumlahMedia, int $beruntun): int
    {
        return ($negatif >= 3 ? 2 : 0)
            + ($negatif >= 7 ? 2 : 0)
            + ($jumlahMedia >= 3 ? 2 : 0)
            + ($beruntun >= 2 ? 1 : 0)
            + ($beruntun >= 4 ? 1 : 0);
    }

    /**
     * Rentetan hari terpanjang yang memuat artikel topik ini.
     *
     * Isu yang muncul empat hari berturut-turut berbeda dari isu yang muncul
     * empat kali dalam satu hari lalu hilang.
     *
     * @param  Collection<int, object>  $anggota
     */
    private function hariBeruntun(Collection $anggota): int
    {
        $tanggal = $anggota
            ->map(fn ($a) => Waktu::tanggalWita(CarbonImmutable::parse($a->diambil_at)))
            ->unique()
            ->sort()
            ->values();

        $terpanjang = $beruntun = $tanggal->isEmpty() ? 0 : 1;

        for ($i = 1; $i < $tanggal->count(); $i++) {
            // Dibulatkan ke int: diffInDays mengembalikan float, dan
            // perbandingan ketat terhadap 1.0 tidak pernah cocok sehingga tiap
            // topik selalu terbaca satu hari saja.
            $selisih = (int) CarbonImmutable::parse($tanggal[$i - 1])->diffInDays(CarbonImmutable::parse($tanggal[$i]));

            $beruntun = $selisih === 1 ? $beruntun + 1 : 1;
            $terpanjang = max($terpanjang, $beruntun);
        }

        return $terpanjang;
    }

    private function dominan(int $negatif, int $netral, int $positif): string
    {
        $urut = ['negatif' => $negatif, 'netral' => $netral, 'positif' => $positif];
        arsort($urut);

        return array_key_first($urut);
    }

    /**
     * @return list<array<string, string>>
     */
    private function perhatian(mixed $mentah): array
    {
        return array_values(array_map(fn ($p) => [
            'topik' => $this->potong((string) ($p['topik'] ?? ''), 300),
            'alasan' => (string) ($p['alasan'] ?? ''),
        ], array_filter((array) $mentah, is_array(...))));
    }

    /**
     * Judul yang kepanjangan dipotong, bukan menggagalkan penyimpanan.
     *
     * Kolomnya varchar(300) dan Postgres menolak nilai yang lebih panjang. Satu
     * judul topik yang bertele-tele tidak boleh membatalkan seluruh narasi yang
     * panggilan Gemini-nya sudah dibayar.
     */
    private function potong(string $teks, int $maks): string
    {
        if (mb_strlen($teks) <= $maks) {
            return $teks;
        }

        Log::warning('Judul dari Gemini dipotong.', ['panjang' => mb_strlen($teks)]);

        return mb_substr($teks, 0, $maks);
    }
}
