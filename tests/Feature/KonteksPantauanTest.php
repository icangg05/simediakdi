<?php

namespace Tests\Feature;

use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\KonteksPantauan;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KonteksPantauanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function konteks(array $tambahan = []): KonteksPantauan
    {
        static $n = 0;
        $n++;

        return KonteksPantauan::create([
            'nama' => "Konteks {$n}",
            'slug' => "konteks-{$n}",
            ...$tambahan,
        ]);
    }

    public function test_halaman_ubah_terbuka_dan_menemukan_barisnya(): void
    {
        $konteks = $this->konteks();

        // Kalau route model binding meleset, ini 404, bukan 200 dengan data kosong.
        $this->get("/admin/konteks/{$konteks->id}/edit")->assertOk();
    }

    public function test_slug_sendiri_tidak_dianggap_bentrok_saat_update(): void
    {
        $konteks = $this->konteks(['slug' => 'pemkot-kendari']);

        $this->put("/admin/konteks/{$konteks->id}", [
            'nama' => 'Pemerintah Kota Kendari',
            'slug' => 'pemkot-kendari',
            'urutan' => 1,
            'aktif' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('Pemerintah Kota Kendari', $konteks->refresh()->nama);
    }

    /**
     * Index unique partial hanya mengizinkan satu baris utama. Tanpa pelepasan
     * otomatis, menandai konteks kedua sebagai utama gagal di level database.
     */
    public function test_menandai_konteks_utama_baru_melepas_yang_lama(): void
    {
        $lama = $this->konteks(['utama' => true]);
        $baru = $this->konteks();

        $this->put("/admin/konteks/{$baru->id}", [
            'nama' => $baru->nama,
            'slug' => $baru->slug,
            'utama' => true,
            'urutan' => 0,
            'aktif' => true,
        ])->assertSessionHasNoErrors();

        $this->assertFalse($lama->refresh()->utama);
        $this->assertTrue($baru->refresh()->utama);
        $this->assertSame(1, KonteksPantauan::where('utama', true)->count());
    }

    public function test_kata_kunci_dari_textarea_disimpan_sebagai_daftar_bersih(): void
    {
        $konteks = $this->konteks();

        $this->put("/admin/konteks/{$konteks->id}", [
            'nama' => $konteks->nama,
            'slug' => $konteks->slug,
            'kata_kunci' => "Pemkot Kendari\n  wali kota kendari  \n\nPEMKOT KENDARI\n",
            'urutan' => 0,
            'aktif' => true,
        ])->assertSessionHasNoErrors();

        // Huruf kecil, tanpa spasi berlebih, tanpa duplikat, tanpa baris kosong.
        $this->assertSame(['pemkot kendari', 'wali kota kendari'], $konteks->refresh()->kata_kunci);
    }

    /**
     * Menghapus konteks akan ikut menghapus seluruh analisisnya lewat cascade,
     * dan angka historis dashboard berubah surut tanpa jejak.
     */
    public function test_menonaktifkan_konteks_tidak_menghapus_analisis(): void
    {
        $media = Media::create(['nama' => 'A', 'slug' => 'a', 'domain' => 'a.test']);
        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => $media->id, 'judul' => 'Berita', 'url' => 'https://a.test/1',
            'url_kanonik' => 'https://a.test/1', 'diambil_at' => now(),
        ]);

        $konteks = $this->konteks();
        AnalisisSentimen::create([
            'artikel_id' => $artikel->id, 'konteks_pantauan_id' => $konteks->id, 'relevan' => true,
        ]);

        $this->delete("/admin/konteks/{$konteks->id}")->assertRedirect();

        $this->assertFalse($konteks->refresh()->aktif);
        $this->assertSame(1, AnalisisSentimen::where('konteks_pantauan_id', $konteks->id)->count());
    }

    public function test_konteks_utama_tidak_bisa_dinonaktifkan(): void
    {
        $utama = $this->konteks(['utama' => true]);

        $this->delete("/admin/konteks/{$utama->id}")->assertSessionHas('galat');

        $this->assertTrue($utama->refresh()->aktif);
    }
}
