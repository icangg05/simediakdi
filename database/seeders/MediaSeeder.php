<?php

namespace Database\Seeders;

use App\Models\Media;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * 30 media partner dari lampiran A dokumen 01.
 *
 * Kolom `tier` masih dugaan awal dari cakupan situs. Verifikasi ulang di sprint 0;
 * kalau salah, yang terpengaruh hanya pembobotan peringkat media.
 */
class MediaSeeder extends Seeder
{
    /** @var array<int, array{0: string, 1: string, 2: string}> nama, url, tier */
    private const DAFTAR = [
        ['Sultra TV', 'https://www.sultratv.id/', 'regional'],
        ['Sultra Demo', 'https://sultrademo.or.id/', 'regional'],
        ['Kendari Pos', 'https://kendaripos.fajar.co.id/', 'lokal'],
        ['Radar Kendari', 'https://radarkendari.com', 'lokal'],
        ['Kolom Rakyat', 'https://kolomrakyat.com', 'lokal'],
        ['Tempo', 'https://www.tempo.id/', 'nasional'],
        ['Trijaya Kendari', 'https://www.trijayakendari.com', 'lokal'],
        ['Telisik', 'https://telisik.id/', 'regional'],
        ['Kendari Info', 'https://kendariinfo.com/', 'lokal'],
        ['Detikcom', 'https://www.detik.com', 'nasional'],
        ['Britakita', 'https://britakita.net/', 'lokal'],
        ['Perdetik News', 'https://perdetiknews.com/', 'lokal'],
        ['Galeri Sultra', 'https://galerisultra.com/', 'regional'],
        ['Radar Sultra', 'https://radarsultra.co/', 'regional'],
        ['Figur Sultra', 'https://figursultra.com/', 'regional'],
        ['Lensa Timur', 'https://www.lensatimur.id/', 'regional'],
        ['Koran Headline', 'https://koranheadline.com/', 'lokal'],
        ['Mediatama Sultra', 'https://mediatamasultra.com/', 'regional'],
        ['Kisahan', 'https://kisahan.id/', 'lokal'],
        ['Sultranesia', 'https://sultranesia.com/', 'regional'],
        ['Sibernas', 'https://sibernas.id', 'lokal'],
        ['Tajuk Info', 'https://tajukinfo.com', 'lokal'],
        ['Teras Sultra', 'https://www.terassultra.com', 'regional'],
        ['Lontara Sultra', 'https://www.lontarasultra.com', 'regional'],
        ['Sultra Merdeka', 'https://www.sultramerdeka.com', 'regional'],
        ['Metro Kendari', 'https://metrokendari.com', 'lokal'],
        ['Portal.id', 'https://portal.id/', 'nasional'],
        ['Informasi Sultra', 'https://informasisultra.com', 'regional'],
        ['Kongkrit Post', 'https://kongkritpost.com/', 'lokal'],
        ['Mitra Nusantara', 'https://mitranusantara.id/', 'regional'],
    ];

    /**
     * Media nasional: jangan tarik feed utuh, isinya didominasi berita di luar
     * Kendari dan akan menenggelamkan angka volume.
     */
    private const CATATAN_NASIONAL = 'Nasional. Jangan pakai feed utuh — pakai feed kategori daerah bila ada, '
        .'atau Google News RSS dengan kata kunci Kendari.';

    public function run(): void
    {
        foreach (self::DAFTAR as [$nama, $url, $tier]) {
            Media::updateOrCreate(
                ['slug' => Str::slug($nama)],
                [
                    'nama' => $nama,
                    'jenis' => 'online',
                    'tier' => $tier,
                    'url_website' => $url,
                    // Subdomain lengkap dipertahankan (kendaripos.fajar.co.id)
                    // agar artikel Fajar lain tidak ikut tercocokkan.
                    'domain' => self::domain($url),
                    'partner' => true,
                    'aktif' => true,
                    'catatan' => $tier === 'nasional' ? self::CATATAN_NASIONAL : null,
                ],
            );
        }
    }

    /** Host tanpa `www.`, huruf kecil. Dipakai mencocokkan artikel ke media. */
    private static function domain(string $url): string
    {
        return Str::of(parse_url($url, PHP_URL_HOST))->lower()->after('www.')->toString();
    }
}
