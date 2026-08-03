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

    /**
     * Pengetat setelah model menjawab ya. Diukur terhadap 254 label manusia:
     * presisi 54,2% ke 80,0% pada data tahan, recall turun tipis ke 92,3%.
     */
    public function test_kata_kunci_di_judul_membuat_artikel_menonjol(): void
    {
        $konteks = $this->konteks(['pemkot kendari']);

        $this->assertTrue($this->penyaring->menonjol(
            'Pemkot Kendari Resmikan Pasar Baru',
            'Isi berita tanpa menyebut apa pun lagi.',
            $konteks,
        ));
    }

    /** Inilah kasus yang dulu lolos: penyebutan sekali lewat, bukan pembahasan. */
    public function test_sebut_sekali_di_badan_berita_tidak_dianggap_membahas(): void
    {
        $konteks = $this->konteks(['pemkot kendari']);

        $this->assertFalse($this->penyaring->menonjol(
            'Panen Raya di Konawe Selatan',
            'Petani memanen padi. Turut hadir perwakilan Pemkot Kendari. Acara berlangsung meriah.',
            $konteks,
        ));
    }

    public function test_disebut_tiga_kali_di_badan_berita_dianggap_membahas(): void
    {
        $konteks = $this->konteks(['pemkot kendari']);

        $this->assertTrue($this->penyaring->menonjol(
            'Pasar Baru Diresmikan',
            'Pemkot Kendari membangun pasar itu sejak tahun lalu. Anggarannya dari Pemkot Kendari. '
            .'Pemkot Kendari juga menyiapkan lahan parkir.',
            $konteks,
        ));
    }

    /**
     * Konteks tanpa kata kunci tidak bisa diketatkan, tidak ada yang dihitung.
     * Menolak semuanya akan mematikan konteks itu diam-diam.
     */
    public function test_konteks_tanpa_kata_kunci_tidak_diketatkan(): void
    {
        $this->assertTrue($this->penyaring->menonjol('Judul apa pun', 'Isi apa pun.', $this->konteks(null)));
    }

    public function test_ambang_sebutan_bisa_disetel(): void
    {
        config(['nlp.minimal_sebutan' => 2]);

        $konteks = $this->konteks(['drainase']);

        $this->assertTrue($this->penyaring->menonjol(
            'Proyek Kota Selesai',
            'Perbaikan drainase rampung. Drainase baru itu lebih lebar.',
            $konteks,
        ));
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
