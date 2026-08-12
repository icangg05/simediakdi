<?php

namespace App\Services\Alert;

use App\Enums\LabelSentimen;
use App\Models\AnalisisSentimen;
use App\Models\AturanAlert;
use App\Models\SumberFeed;
use Illuminate\Support\Facades\DB;

/**
 * Menilai satu aturan alert dan menyusun pesannya.
 *
 * Tidak mengirim apa pun dan tidak menulis riwayat. Pemisahan ini yang membuat
 * aturan bisa diuji tanpa memanggil Telegram, dan itu penting: aturan yang
 * salah ambang akan membanjiri grup dengan pesan, dan orang berhenti membaca
 * alert jauh sebelum mereka mematikannya.
 *
 * Mengembalikan null berarti tidak ada yang perlu dikirim.
 */
class PemeriksaAturan
{
    /** @return array{ringkasan: string, payload: array<string, mixed>}|null */
    public function nilai(AturanAlert $aturan): ?array
    {
        return match ($aturan->jenis) {
            'lonjakan_negatif' => $this->lonjakanNegatif($aturan),
            'kata_kunci_muncul' => $this->kataKunciMuncul($aturan),
            'sumber_mati' => $this->sumberMati($aturan),
            default => null,
        };
    }

    /**
     * Jumlah berita negatif di jendela ini dibandingkan rata-rata jendela
     * sepanjang sama sebelumnya.
     *
     * Dua syarat, bukan satu. Kelipatan saja membuat kenaikan 1 ke 3 artikel
     * terbaca sebagai lonjakan 300%, dan alert seperti itu menyala hampir tiap
     * hari di kota sebesar Kendari. `minimal_artikel` yang menahannya.
     */
    private function lonjakanNegatif(AturanAlert $aturan): ?array
    {
        $jendela = max(1, (int) $aturan->jendela_jam);
        $kondisi = $aturan->kondisi ?? [];
        $minimal = (int) ($kondisi['minimal_artikel'] ?? 3);
        $kelipatan = (float) ($kondisi['kelipatan_dari_rata_rata'] ?? $aturan->ambang ?? 2.0);
        $abaikanRagu = (bool) ($kondisi['abaikan_perlu_review'] ?? true);

        $sekarang = now();
        $mulai = $sekarang->copy()->subHours($jendela);

        $dasar = fn () => AnalisisSentimen::query()
            ->join('artikel', 'artikel.id', '=', 'analisis_sentimen.artikel_id')
            ->where('analisis_sentimen.relevan', true)
            ->where('analisis_sentimen.label_efektif', LabelSentimen::Negatif)
            // Artikel yang model sendiri ragukan biasanya bukan bahan yang
            // pantas membangunkan orang di luar jam kerja.
            ->when($abaikanRagu, fn ($q) => $q->where('analisis_sentimen.perlu_review', false));

        $kini = (clone $dasar())->where('artikel.diambil_at', '>=', $mulai)->count();

        if ($kini < $minimal) {
            return null;
        }

        // Empat jendela sebelumnya, bukan satu: satu jendela pembanding membuat
        // hasilnya bergantung pada kebetulan satu periode saja.
        $sebelum = (clone $dasar())
            ->whereBetween('artikel.diambil_at', [
                $mulai->copy()->subHours($jendela * 4),
                $mulai,
            ])
            ->count();

        $rata = $sebelum / 4;

        // Tanpa pembanding sama sekali, satu-satunya syarat yang masuk akal
        // adalah `minimal_artikel` yang sudah lolos di atas.
        if ($rata > 0 && $kini < $rata * $kelipatan) {
            return null;
        }

        $banding = $rata > 0
            ? sprintf('rata-rata %s jendela sebelumnya %.1f', 4, $rata)
            : 'tidak ada pembanding di jendela sebelumnya';

        // Tiga berita terbaru dari lonjakan itu sendiri, dibawa ikut ke dalam
        // pesan. Angka "12 berita negatif dalam 6 jam" tidak memberitahu apa
        // yang sedang terjadi, dan penerimanya tetap harus membuka dashboard
        // untuk tahu apakah ini satu peristiwa besar atau dua belas hal kecil.
        $contoh = (clone $dasar())
            ->where('artikel.diambil_at', '>=', $mulai)
            ->leftJoin('media', 'media.id', '=', 'artikel.media_id')
            ->orderByDesc('artikel.diambil_at')
            ->limit(3)
            ->get(['artikel.judul', 'artikel.url', 'artikel.diambil_at', 'media.nama as media'])
            ->toArray();

        return [
            'ringkasan' => "{$kini} berita negatif dalam {$jendela} jam terakhir ({$banding}).",
            'payload' => [
                'jumlah' => $kini,
                'rata_sebelumnya' => round($rata, 2),
                'jendela_jam' => $jendela,
                'contoh' => $contoh,
            ],
        ];
    }

    /** Kata kunci tertentu muncul di judul dalam jendela waktu (F-37). */
    private function kataKunciMuncul(AturanAlert $aturan): ?array
    {
        $istilah = array_filter(array_map('trim', (array) ($aturan->kondisi['istilah'] ?? [])));

        if ($istilah === []) {
            return null;
        }

        $mulai = now()->subHours(max(1, (int) $aturan->jendela_jam));

        $artikel = DB::table('artikel')
            ->where('diambil_at', '>=', $mulai)
            ->where(function ($q) use ($istilah) {
                foreach ($istilah as $satu) {
                    $q->orWhere('judul', 'ilike', '%'.$satu.'%');
                }
            })
            ->orderByDesc('diambil_at')
            ->limit(10)
            ->get(['id', 'judul', 'url']);

        if ($artikel->isEmpty()) {
            return null;
        }

        return [
            'ringkasan' => $artikel->count().' berita memuat kata kunci pantauan: '
                .implode(', ', $istilah).'.',
            'payload' => ['istilah' => array_values($istilah), 'artikel' => $artikel->all()],
        ];
    }

    /**
     * Feed yang sudah lama tidak menghasilkan apa pun (F-39).
     *
     * Diukur dari `berhasil_terakhir_at`, bukan dari `gagal_berturut`. Feed
     * bisa menjawab 200 dengan daftar kosong setiap kali, dan kegagalan diam
     * seperti itu yang paling lama tidak ketahuan.
     */
    private function sumberMati(AturanAlert $aturan): ?array
    {
        $jam = (int) ($aturan->kondisi['jam'] ?? config('alert.sumber_mati_jam'));
        $batas = now()->subHours($jam);

        $mati = SumberFeed::withoutGlobalScopes()
            ->where('aktif', true)
            ->where(fn ($q) => $q->whereNull('berhasil_terakhir_at')->orWhere('berhasil_terakhir_at', '<', $batas))
            ->orderBy('nama')
            ->get(['id', 'nama', 'berhasil_terakhir_at', 'pesan_error_terakhir']);

        if ($mati->isEmpty()) {
            return null;
        }

        return [
            'ringkasan' => $mati->count()." sumber feed tidak menghasilkan berita dalam {$jam} jam terakhir: "
                .$mati->take(5)->pluck('nama')->implode(', ').($mati->count() > 5 ? ', dan lainnya' : ''),
            'payload' => ['jam' => $jam, 'sumber' => $mati->all()],
        ];
    }
}
