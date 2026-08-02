<?php

namespace Tests\Feature;

use App\Models\KonteksPantauan;
use App\Services\Nlp\PenyaringKataKunci;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PenyaringKataKunciTest extends TestCase
{
    use RefreshDatabase;

    private PenyaringKataKunci $penyaring;

    protected function setUp(): void
    {
        parent::setUp();

        $this->penyaring = new PenyaringKataKunci;
    }

    private function konteks(?array $kataKunci): KonteksPantauan
    {
        static $n = 0;
        $n++;

        return KonteksPantauan::create([
            'nama' => "Konteks {$n}",
            'slug' => "konteks-{$n}",
            'kata_kunci' => $kataKunci,
        ]);
    }

    public function test_meloloskan_artikel_yang_memuat_kata_kunci(): void
    {
        $konteks = $this->konteks(['pemkot kendari', 'wali kota kendari']);

        $this->assertTrue($this->penyaring->lolos(
            'Pemkot Kendari meresmikan jembatan baru pekan ini.',
            $konteks,
        ));
    }

    public function test_membuang_artikel_yang_jelas_tidak_nyambung(): void
    {
        $konteks = $this->konteks(['wali kota kendari']);

        // Inilah yang dihemat: satu panggilan model untuk artikel seperti ini.
        $this->assertFalse($this->penyaring->lolos(
            'Harga cabai di Pasar Baruga naik dua kali lipat.',
            $konteks,
        ));
    }

    public function test_pencocokan_mengabaikan_besar_kecil_huruf_dan_spasi_ganda(): void
    {
        $konteks = $this->konteks(['wali kota kendari']);

        $this->assertTrue($this->penyaring->lolos('WALI  KOTA   KENDARI membuka acara.', $konteks));
    }

    /**
     * Konteks tanpa kata kunci berarti belum disetel, bukan berarti tidak ada
     * yang cocok. Membuang semuanya akan membuat konteks itu diam-diam mati.
     */
    public function test_konteks_tanpa_kata_kunci_selalu_diteruskan(): void
    {
        $this->assertTrue($this->penyaring->lolos('Teks apa pun.', $this->konteks(null)));
        $this->assertTrue($this->penyaring->lolos('Teks apa pun.', $this->konteks([])));
    }

    public function test_menyaring_daftar_konteks_sekaligus(): void
    {
        $cocok = $this->konteks(['drainase']);
        $tidak = $this->konteks(['sepak bola']);

        $lolos = $this->penyaring->saring(
            'Perbaikan drainase di Kecamatan Kadia selesai.',
            [$cocok, $tidak],
        );

        $this->assertCount(1, $lolos);
        $this->assertSame($cocok->id, $lolos[0]->id);
    }
}
