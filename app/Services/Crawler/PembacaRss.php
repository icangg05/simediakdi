<?php

namespace App\Services\Crawler;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Membaca RSS 2.0 dan Atom dengan SimpleXMLElement bawaan PHP.
 *
 * Paket pihak ketiga tidak memberi nilai tambah untuk dua format ini dan
 * menambah dependensi yang harus dirawat.
 */
class PembacaRss
{
    public function __construct(private NormalisasiUrl $normalisasi) {}

    /**
     * @return list<ItemFeed>
     *
     * @throws GagalMengunduh feed tidak dapat diurai
     */
    public function baca(string $xml, string $urlFeed): array
    {
        // Deklarasi XML harus ada di byte pertama, dan sejumlah situs WordPress
        // menyisipkan baris kosong atau BOM sebelumnya karena plugin yang
        // mencetak sesuatu lebih dulu. Feed-nya sah, hanya kotor di depan —
        // membuangnya jauh lebih baik daripada kehilangan seluruh media.
        $xml = preg_replace('/^[\x{FEFF}\s]+/u', '', $xml) ?? $xml;

        $sebelumnya = libxml_use_internal_errors(true);

        try {
            // LIBXML_NOENT sengaja tidak dipakai: mengaktifkan entity substitution
            // pada XML dari luar membuka XXE.
            $dokumen = new \SimpleXMLElement($xml, LIBXML_NOCDATA | LIBXML_NONET);
        } catch (Throwable $e) {
            throw new GagalMengunduh("Feed {$urlFeed} bukan XML yang sah: {$e->getMessage()}", previous: $e);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($sebelumnya);
        }

        $item = $dokumen->channel->item ?? null;

        if ($item !== null && count($item) > 0) {
            return $this->dariRss($item, $urlFeed);
        }

        // Atom: <feed><entry>. Blogger memakai ini kalau ?alt=rss dilupakan.
        if (isset($dokumen->entry) && count($dokumen->entry) > 0) {
            return $this->dariAtom($dokumen->entry, $urlFeed);
        }

        return [];
    }

    /** @return list<ItemFeed> */
    private function dariRss(\SimpleXMLElement $daftar, string $urlFeed): array
    {
        $hasil = [];

        foreach ($daftar as $item) {
            $url = trim((string) $item->link);

            if ($url === '' || ($judul = trim((string) $item->title)) === '') {
                continue;
            }

            $dublinCore = $item->children('http://purl.org/dc/elements/1.1/');

            $hasil[] = new ItemFeed(
                judul: $this->rapikan($judul),
                url: $this->normalisasi->absolutkan($url, $urlFeed),
                dipublikasikanAt: $this->waktu((string) $item->pubDate ?: (string) ($dublinCore->date ?? '')),
                ringkasan: $this->ringkasan((string) $item->description),
                penulis: $this->rapikan((string) $item->author ?: (string) ($dublinCore->creator ?? '')) ?: null,
            );
        }

        return $hasil;
    }

    /** @return list<ItemFeed> */
    private function dariAtom(\SimpleXMLElement $daftar, string $urlFeed): array
    {
        $hasil = [];

        foreach ($daftar as $entri) {
            $url = '';

            // Atom punya banyak <link>; yang dicari rel="alternate".
            foreach ($entri->link as $tautan) {
                $rel = (string) $tautan['rel'];

                if ($rel === '' || $rel === 'alternate') {
                    $url = (string) $tautan['href'];
                    break;
                }
            }

            if ($url === '' || ($judul = trim((string) $entri->title)) === '') {
                continue;
            }

            $hasil[] = new ItemFeed(
                judul: $this->rapikan($judul),
                url: $this->normalisasi->absolutkan($url, $urlFeed),
                dipublikasikanAt: $this->waktu((string) ($entri->published ?: $entri->updated)),
                ringkasan: $this->ringkasan((string) ($entri->summary ?: $entri->content)),
                penulis: $this->rapikan((string) ($entri->author->name ?? '')) ?: null,
            );
        }

        return $hasil;
    }

    private function waktu(string $mentah): ?CarbonImmutable
    {
        $mentah = trim($mentah);

        if ($mentah === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($mentah)->utc();
        } catch (Throwable) {
            // Tanggal feed sering salah format. Bukan alasan membuang artikelnya;
            // diambil_at tetap terisi dan itu yang dipakai grafik harian.
            return null;
        }
    }

    private function ringkasan(string $mentah): ?string
    {
        $bersih = $this->rapikan(strip_tags(html_entity_decode($mentah, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $bersih === '' ? null : mb_substr($bersih, 0, 600);
    }

    private function rapikan(string $teks): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($teks, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
