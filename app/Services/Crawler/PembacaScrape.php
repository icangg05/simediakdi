<?php

namespace App\Services\Crawler;

use App\Models\SumberFeed;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Jalur cadangan untuk situs tanpa RSS (F-08).
 *
 * Hanya mengambil judul dan tautan dari halaman indeks; isinya tetap diunduh
 * job AmbilIsiArtikel seperti sumber RSS. Selector disimpan per sumber di
 * kolom `selector`.
 */
class PembacaScrape
{
    public function __construct(private NormalisasiUrl $normalisasi) {}

    /**
     * @return list<ItemFeed>
     */
    public function baca(string $html, SumberFeed $sumber): array
    {
        $selector = $sumber->selector ?? [];

        if (! isset($selector['item'], $selector['judul'], $selector['tautan'])) {
            throw new GagalMengunduh("Sumber {$sumber->nama} bertipe scrape tapi selector-nya belum lengkap.");
        }

        $xpath = $this->xpath($html);
        $hasil = [];

        foreach ($xpath->query($this->keXpath($selector['item'])) ?: [] as $simpul) {
            $judul = $this->teksPertama($xpath, $selector['judul'], $simpul);
            $tautan = $this->atributPertama($xpath, $selector['tautan'], $simpul, 'href');

            if ($judul === null || $tautan === null) {
                continue;
            }

            $hasil[] = new ItemFeed(
                judul: $judul,
                url: $this->normalisasi->absolutkan($tautan, $sumber->url),
            );
        }

        return $hasil;
    }

    private function xpath(string $html): DOMXPath
    {
        $sebelumnya = libxml_use_internal_errors(true);

        $dokumen = new DOMDocument;
        // LIBXML_NONET mencegah pengambilan DTD dari luar saat parsing.
        $dokumen->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($sebelumnya);

        return new DOMXPath($dokumen);
    }

    private function teksPertama(DOMXPath $xpath, string $selector, DOMElement $konteks): ?string
    {
        $simpul = $xpath->query($this->keXpath($selector, relatif: true), $konteks)?->item(0);

        if ($simpul === null) {
            return null;
        }

        $teks = trim(preg_replace('/\s+/u', ' ', $simpul->textContent));

        return $teks === '' ? null : mb_substr($teks, 0, 500);
    }

    private function atributPertama(DOMXPath $xpath, string $selector, DOMElement $konteks, string $atribut): ?string
    {
        $simpul = $xpath->query($this->keXpath($selector, relatif: true), $konteks)?->item(0);

        if (! $simpul instanceof DOMElement) {
            return null;
        }

        // Selector boleh menunjuk elemen pembungkus; kalau begitu, cari <a> di dalamnya.
        $nilai = $simpul->getAttribute($atribut)
            ?: ($xpath->query('.//a[@href]', $simpul)?->item(0)?->getAttribute($atribut) ?? '');

        return $nilai === '' ? null : $nilai;
    }

    /**
     * Terjemahan CSS selector sederhana ke XPath.
     *
     * ponytail: hanya menangani `tag`, `.kelas`, `#id`, dan gabungannya yang
     * dipisah spasi — sudah menutup bentuk selector yang dipakai halaman indeks
     * berita. Kalau suatu saat butuh `>`, `:nth-child`, atau atribut, pasang
     * symfony/css-selector daripada memperbesar fungsi ini.
     */
    private function keXpath(string $selector, bool $relatif = false): string
    {
        $bagian = preg_split('/\s+/', trim($selector), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $xpath = $relatif ? '.' : '';

        foreach ($bagian as $satu) {
            preg_match('/^([a-zA-Z0-9-]*)((?:[.#][^.#]+)*)$/', $satu, $cocok);

            $tag = $cocok[1] ?: '*';
            $syarat = '';

            preg_match_all('/([.#])([^.#]+)/', $cocok[2] ?? '', $penanda, PREG_SET_ORDER);

            foreach ($penanda as [, $jenis, $nilai]) {
                $syarat .= $jenis === '#'
                    ? "[@id='{$nilai}']"
                    : "[contains(concat(' ', normalize-space(@class), ' '), ' {$nilai} ')]";
            }

            $xpath .= '//'.$tag.$syarat;
        }

        return $xpath;
    }
}
