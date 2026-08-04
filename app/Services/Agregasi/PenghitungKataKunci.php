<?php

namespace App\Services\Agregasi;

use App\Models\KonteksPantauan;
use App\Support\Waktu;
use Illuminate\Support\Facades\DB;

/**
 * Frekuensi istilah per periode beserta skor lonjakannya (F-16).
 *
 * Tabel praperhitungan, bukan dihitung saat request. Halaman isu hangat harus
 * terbuka cepat, dan menghitung n-gram dari ribuan artikel setiap kali halaman
 * dibuka membuatnya lambat tanpa alasan.
 */
class PenghitungKataKunci
{
    /**
     * Kata yang terlalu umum untuk jadi isu.
     *
     * Bukan daftar stopword bahasa Indonesia lengkap, hanya yang benar-benar
     * mengotori hasil pada berita pemerintahan daerah. Daftar penuh justru
     * membuang istilah yang bermakna seperti "warga" atau "kota".
     *
     * @var list<string>
     */
    private const KATA_UMUM = [
        'yang', 'dan', 'di', 'ke', 'dari', 'itu', 'ini', 'untuk', 'pada', 'dengan',
        'akan', 'telah', 'sudah', 'juga', 'dalam', 'tidak', 'ada', 'agar', 'oleh',
        'atau', 'sebagai', 'karena', 'saat', 'bahwa', 'para', 'lebih', 'dapat',
        'kata', 'ujar', 'kami', 'kita', 'mereka', 'dia', 'kepada', 'adalah',
        'tersebut', 'sangat', 'hingga', 'setelah', 'sebelum', 'antara', 'per',
        'bisa', 'harus', 'masih', 'baru', 'saya', 'anda', 'yakni', 'yaitu',
        // Ditambahkan setelah korpus tumbuh ke 4.800 artikel. Enam istilah
        // teratas halaman isu hangat semuanya kata sambung, dan halaman yang
        // melaporkan "melalui" sebagai isu yang sedang naik tidak akan
        // dipercaya untuk hal lain apa pun.
        'melalui', 'serta', 'seluruh', 'merupakan', 'sehingga', 'namun',
        'tetapi', 'bagi', 'atas', 'sekitar', 'terhadap', 'saja', 'lain',
        'lainnya', 'banyak', 'semua', 'setiap', 'sementara', 'kemudian',
        'ketika', 'sedangkan', 'maupun', 'jika', 'kalau', 'demi', 'sesuai',
        'guna', 'secara', 'hanya', 'sendiri', 'belum', 'pernah', 'sempat',
        'tetap', 'langsung', 'terus', 'bahkan', 'apabila', 'selain', 'menjadi',
        'melakukan', 'memberikan', 'dilakukan', 'terdapat', 'berbagai',
        'sejumlah', 'beberapa', 'tentang', 'mengatakan', 'menyampaikan',
    ];

    /**
     * Kata yang panjangnya di bawah ini dibuang tanpa dicek ke daftar.
     *
     * Sengaja tidak dinaikkan lebih jauh: "opd", "apbd", dan "pdam" adalah
     * istilah yang justru paling ingin dilihat, dan menaikkan batasnya ke lima
     * akan membuang semuanya diam-diam.
     */
    private const MINIMAL_HURUF = 4;

    /** Istilah harus muncul di minimal sekian artikel agar bukan kebetulan. */
    private const MINIMAL_ARTIKEL = 3;

    /** Berapa istilah teratas yang disimpan per periode. */
    private const BATAS_ISTILAH = 200;

    /**
     * @return int jumlah baris yang ditulis
     */
    public function hitung(string $tanggalWita, ?int $konteksId, string $granularitas = 'harian'): int
    {
        [$mulai, $akhir, $periodeMulai, $periodeAkhir] = $this->periode($tanggalWita, $granularitas);

        $artikel = $this->artikel($mulai, $akhir, $konteksId);

        if ($artikel === []) {
            return 0;
        }

        $hitungan = $this->hitungIstilah($artikel);

        if ($hitungan === []) {
            return 0;
        }

        arsort($hitungan);
        $hitungan = array_slice($hitungan, 0, self::BATAS_ISTILAH, preserve_keys: true);

        $sentimenDominan = $this->sentimenDominan($artikel, array_keys($hitungan));
        $rataSebelumnya = $this->rataSebelumnya(array_keys($hitungan), $konteksId, $granularitas, $periodeMulai);

        $baris = [];

        foreach ($hitungan as $istilah => $data) {
            $rata = $rataSebelumnya[$istilah] ?? 0.0;

            $baris[] = [
                'konteks_pantauan_id' => $konteksId,
                'granularitas' => $granularitas,
                'periode_mulai' => $periodeMulai,
                'periode_akhir' => $periodeAkhir,
                'istilah' => $istilah,
                'frekuensi' => $data['frekuensi'],
                'jumlah_artikel' => $data['artikel'],
                // Rasio terhadap rata-rata empat periode sebelumnya. Istilah
                // yang belum pernah muncul diberi skor sebesar frekuensinya,
                // kemunculan pertama memang lonjakan, bukan pembagian nol.
                'skor_lonjakan' => $rata > 0 ? round($data['frekuensi'] / $rata, 3) : (float) $data['frekuensi'],
                'sentimen_dominan' => $sentimenDominan[$istilah] ?? null,
                'created_at' => now(),
            ];
        }

        // Baris periode ini dihapus lebih dulu, bukan hanya ditimpa.
        //
        // Upsert saja meninggalkan istilah yang tidak lagi lolos saringan:
        // saat daftar kata umum diperpanjang, "melalui" dan "serta" tetap
        // duduk di peringkat teratas halaman isu hangat selamanya karena tidak
        // ada baris baru yang menimpanya. Penghitungan ulang harus benar-benar
        // menghasilkan keadaan yang sama seperti penghitungan pertama.
        DB::table('kata_kunci_periode')
            ->where('granularitas', $granularitas)
            ->where('periode_mulai', $periodeMulai)
            ->when(
                $konteksId === null,
                fn ($q) => $q->whereNull('konteks_pantauan_id'),
                fn ($q) => $q->where('konteks_pantauan_id', $konteksId),
            )
            ->delete();

        DB::table('kata_kunci_periode')->insert($baris);

        return count($baris);
    }

    /** @return array{0: \Carbon\CarbonImmutable, 1: \Carbon\CarbonImmutable, 2: string, 3: string} */
    private function periode(string $tanggalWita, string $granularitas): array
    {
        $tanggal = \Carbon\CarbonImmutable::parse($tanggalWita);

        if ($granularitas === 'mingguan') {
            $awal = $tanggal->startOfWeek();
            $akhir = $tanggal->endOfWeek();
        } else {
            $awal = $tanggal;
            $akhir = $tanggal;
        }

        return [
            Waktu::awalHari($awal->toDateString()),
            Waktu::akhirHari($akhir->toDateString()),
            $awal->toDateString(),
            $akhir->toDateString(),
        ];
    }

    /**
     * Artikel asli pada periode, beserta label efektifnya untuk konteks itu.
     *
     * @return list<array{teks: string, label: ?string}>
     */
    private function artikel(\Carbon\CarbonImmutable $mulai, \Carbon\CarbonImmutable $akhir, ?int $konteksId): array
    {
        return DB::table('artikel as a')
            ->when(
                $konteksId,
                fn ($q) => $q->join('analisis_sentimen as s', function ($j) use ($konteksId) {
                    $j->on('s.artikel_id', '=', 'a.id')
                        ->where('s.konteks_pantauan_id', '=', $konteksId)
                        ->where('s.relevan', '=', true);
                })->addSelect('s.label_efektif as label'),
                fn ($q) => $q->selectRaw('NULL AS label'),
            )
            ->where('a.status_dedup', 'asli')
            ->whereNotNull('a.isi')
            ->whereBetween('a.diambil_at', [$mulai, $akhir])
            ->addSelect(DB::raw("a.judul || ' ' || a.isi AS teks"))
            ->get()
            ->map(fn ($b) => ['teks' => $b->teks, 'label' => $b->label ?? null])
            ->all();
    }

    /**
     * Unigram dan bigram beserta frekuensi dan jumlah artikel pemuatnya.
     *
     * @param  list<array{teks: string, label: ?string}>  $artikel
     * @return array<string, array{frekuensi: int, artikel: int}>
     */
    private function hitungIstilah(array $artikel): array
    {
        $total = [];

        foreach ($artikel as $satu) {
            $terlihat = [];

            foreach ($this->istilahDari($satu['teks']) as $istilah) {
                $total[$istilah]['frekuensi'] = ($total[$istilah]['frekuensi'] ?? 0) + 1;

                if (! isset($terlihat[$istilah])) {
                    $terlihat[$istilah] = true;
                    $total[$istilah]['artikel'] = ($total[$istilah]['artikel'] ?? 0) + 1;
                }
            }
        }

        return array_filter($total, fn (array $d) => $d['artikel'] >= self::MINIMAL_ARTIKEL);
    }

    /** @return list<string> */
    private function istilahDari(string $teks): array
    {
        $kata = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($teks), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        // Kata sangat pendek hampir selalu partikel; membuangnya lebih murah
        // daripada memperpanjang daftar kata umum.
        //
        // Angka murni ikut dibuang. "2026" muncul di hampir setiap berita dan
        // selalu naik ke peringkat teratas, padahal tahun bukan isu.
        $kata = array_values(array_filter(
            $kata,
            fn (string $k) => mb_strlen($k) >= self::MINIMAL_HURUF
                && ! ctype_digit($k)
                && ! in_array($k, self::KATA_UMUM, strict: true),
        ));

        $istilah = $kata;

        for ($i = 0; $i < count($kata) - 1; $i++) {
            $istilah[] = $kata[$i].' '.$kata[$i + 1];
        }

        return $istilah;
    }

    /**
     * Label yang paling sering muncul pada artikel yang memuat istilah itu.
     *
     * Kolom inilah yang membuat halaman isu berguna: "banjir" naik 300% tidak
     * bermakna sampai diketahui 85% artikel yang memuatnya bernada negatif.
     *
     * @param  list<array{teks: string, label: ?string}>  $artikel
     * @param  list<string>  $istilah
     * @return array<string, string>
     */
    private function sentimenDominan(array $artikel, array $istilah): array
    {
        $peta = array_fill_keys($istilah, []);
        $dicari = array_flip($istilah);

        foreach ($artikel as $satu) {
            if ($satu['label'] === null) {
                continue;
            }

            foreach (array_unique($this->istilahDari($satu['teks'])) as $satuIstilah) {
                if (isset($dicari[$satuIstilah])) {
                    $peta[$satuIstilah][$satu['label']] = ($peta[$satuIstilah][$satu['label']] ?? 0) + 1;
                }
            }
        }

        $hasil = [];

        foreach ($peta as $satuIstilah => $hitungan) {
            if ($hitungan === []) {
                continue;
            }

            arsort($hitungan);
            $hasil[$satuIstilah] = array_key_first($hitungan);
        }

        return $hasil;
    }

    /**
     * Rata-rata frekuensi empat periode sebelumnya, sebagai pembagi skor lonjakan.
     *
     * @param  list<string>  $istilah
     * @return array<string, float>
     */
    private function rataSebelumnya(array $istilah, ?int $konteksId, string $granularitas, string $periodeMulai): array
    {
        return DB::table('kata_kunci_periode')
            ->when(
                $konteksId,
                fn ($q) => $q->where('konteks_pantauan_id', $konteksId),
                fn ($q) => $q->whereNull('konteks_pantauan_id'),
            )
            ->where('granularitas', $granularitas)
            ->where('periode_mulai', '<', $periodeMulai)
            ->whereIn('istilah', $istilah)
            ->where('periode_mulai', '>=', \Carbon\CarbonImmutable::parse($periodeMulai)
                ->subDays($granularitas === 'mingguan' ? 28 : 4)->toDateString())
            ->groupBy('istilah')
            ->pluck(DB::raw('avg(frekuensi)'), 'istilah')
            ->map(fn ($n) => (float) $n)
            ->all();
    }

    /** @return list<KonteksPantauan> */
    public function konteksAktif(): array
    {
        return KonteksPantauan::query()->aktif()->get()->all();
    }
}
