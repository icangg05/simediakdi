<?php

namespace Tests\Feature;

use App\Enums\StatusDedup;
use App\Models\Artikel;
use App\Models\Media;
use App\Services\Dedup\PencariDuplikat;
use App\Services\Dedup\PenghitungSimhash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tanpa deduplikasi seluruh angka salah, dan kepercayaan hilang lebih cepat
 * daripada karena fitur yang kurang.
 */
class DeduplikasiTest extends TestCase
{
    use RefreshDatabase;

    private const RILIS = 'Pemerintah Kota Kendari menuntaskan perbaikan drainase di Kecamatan Kadia pekan ini. '
        .'Wali Kota menyatakan pengerjaan berlangsung selama dua bulan dan menelan biaya empat miliar rupiah. '
        .'Warga sekitar menyebut genangan yang biasa muncul saat hujan deras kini jauh berkurang. '
        .'Dinas Pekerjaan Umum akan melanjutkan pekerjaan serupa di tiga kecamatan lain pada tahun depan.';

    private PencariDuplikat $dedup;

    private PenghitungSimhash $simhash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dedup = app(PencariDuplikat::class);
        $this->simhash = app(PenghitungSimhash::class);
    }

    public function test_isi_identik_dari_tiga_media_hanya_menyisakan_satu_asli(): void
    {
        // Didedup satu per satu begitu masuk, persis seperti pipeline: tiap
        // artikel dicek saat job AmbilIsiArtikel-nya sendiri selesai.
        $artikel = collect(['a', 'b', 'c'])->map(function (string $kode) {
            $satu = $this->buatArtikel($kode, self::RILIS);

            if ($induk = $this->dedup->cariInduk($satu)) {
                $this->dedup->tandaiSalinan($satu, $induk);
            }

            return $satu;
        });

        $this->assertSame(1, Artikel::withoutGlobalScopes()->where('status_dedup', StatusDedup::Asli)->count());
        $this->assertSame(2, Artikel::withoutGlobalScopes()->where('status_dedup', StatusDedup::Salinan)->count());

        // Semua salinan menunjuk artikel pertama, bukan berantai.
        $indukIds = Artikel::withoutGlobalScopes()
            ->where('status_dedup', StatusDedup::Salinan)
            ->pluck('artikel_induk_id')
            ->unique();

        $this->assertCount(1, $indukIds);
        $this->assertSame($artikel->first()->id, $indukIds->first());
    }

    public function test_isi_yang_diubah_sedikit_tetap_terdeteksi_sebagai_salinan(): void
    {
        $asli = $this->buatArtikel('asli', self::RILIS);

        // Kasus nyata: satu rilis dimuat ulang dengan satu kalimat ditambah.
        $tiruan = $this->buatArtikel(
            'tiruan',
            self::RILIS.' Kegiatan itu didanai APBD Kota Kendari tahun anggaran berjalan.',
        );

        $induk = $this->dedup->cariInduk($tiruan);

        $this->assertNotNull($induk, 'Artikel dengan satu kalimat tambahan seharusnya terdeteksi mirip.');
        $this->assertSame($asli->id, $induk->id);
    }

    public function test_berita_yang_benar_benar_berbeda_tidak_dianggap_salinan(): void
    {
        $this->buatArtikel('asli', self::RILIS);

        $lain = $this->buatArtikel(
            'lain',
            'Harga cabai di Pasar Baruga Kendari naik dua kali lipat sejak awal bulan. '
            .'Pedagang menyebut pasokan dari Sulawesi Selatan tersendat karena cuaca buruk. '
            .'Dinas Perdagangan berencana menggelar operasi pasar pekan depan untuk menekan harga. '
            .'Ibu rumah tangga mengaku mengurangi pembelian karena harganya dianggap tidak wajar.',
        );

        $this->assertNull($this->dedup->cariInduk($lain));
    }

    public function test_rantai_salinan_dipendekkan_ke_induk_pertama(): void
    {
        $a = $this->buatArtikel('a', self::RILIS);
        $b = $this->buatArtikel('b', self::RILIS);
        $this->dedup->tandaiSalinan($b, $a);

        $c = $this->buatArtikel('c', self::RILIS);
        // Sengaja ditautkan ke B yang sudah salinan.
        $this->dedup->tandaiSalinan($c, $b->refresh());

        $this->assertSame($a->id, $c->refresh()->artikel_induk_id);
    }

    public function test_artikel_di_luar_jendela_waktu_tidak_dianggap_induk(): void
    {
        $lama = $this->buatArtikel('lama', self::RILIS);
        $lama->update(['diambil_at' => now()->subDays(30)]);

        $baru = $this->buatArtikel('baru', self::RILIS);

        // Tanpa batas jendela, berita banjir tahun lalu jadi induk berita hari ini.
        $this->assertNull($this->dedup->cariInduk($baru));
    }

    private function buatArtikel(string $kode, string $isi): Artikel
    {
        $media = Media::create([
            'nama' => "Media {$kode}", 'slug' => "media-{$kode}", 'domain' => "{$kode}.test",
        ]);

        return Artikel::withoutGlobalScopes()->create([
            'media_id' => $media->id,
            'judul' => 'Drainase Kadia tuntas',
            'url' => "https://{$kode}.test/berita/drainase",
            'url_kanonik' => "https://{$kode}.test/berita/drainase",
            'isi' => $isi,
            'hash_isi' => $this->dedup->hashIsi($isi),
            'simhash' => $this->simhash->hitung("Drainase Kadia tuntas {$isi}"),
            'diambil_at' => now(),
        ]);
    }
}
