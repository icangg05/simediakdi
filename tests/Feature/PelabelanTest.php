<?php

namespace Tests\Feature;

use App\Enums\LabelSentimen;
use App\Models\Artikel;
use App\Models\GoldSet;
use App\Models\KonteksPantauan;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PelabelanTest extends TestCase
{
    use RefreshDatabase;

    private KonteksPantauan $konteks;

    private User $pelabel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pelabel = User::factory()->create();
        $this->konteks = KonteksPantauan::create([
            'nama' => 'Pemerintah Kota Kendari', 'slug' => 'pemkot', 'utama' => true,
        ]);

        $media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kendari-pos', 'domain' => 'kp.test']);

        foreach (range(1, 3) as $n) {
            Artikel::withoutGlobalScopes()->create([
                'media_id' => $media->id,
                'judul' => "Berita {$n}",
                'url' => "https://kp.test/{$n}",
                'url_kanonik' => "https://kp.test/{$n}",
                'isi' => "Isi berita nomor {$n} tentang Pemkot Kendari.",
                'diambil_at' => now(),
            ]);
        }
    }

    private function labeli(Artikel $artikel, string $label = 'netral'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->pelabel)->post('/admin/pelabelan', [
            'artikel_id' => $artikel->id,
            'konteks_pantauan_id' => $this->konteks->id,
            'relevan_gold' => true,
            'label_gold' => $label,
            'ronde' => 1,
        ]);
    }

    /**
     * Memuat ulang halaman tidak boleh mengganti artikel yang sedang dibaca.
     *
     * inRandomOrder() tidak bisa dipakai untuk ini: seed-nya diabaikan di
     * PostgreSQL, jadi tiap muat ulang menghasilkan artikel yang berbeda.
     */
    public function test_antrean_menyodorkan_artikel_yang_sama_saat_dimuat_ulang(): void
    {
        $ambilId = fn () => $this->actingAs($this->pelabel)
            ->get('/admin/pelabelan')
            ->viewData('page')['props']['tugas']['artikel']['id'];

        $pertama = $ambilId();

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($pertama, $ambilId(), 'Artikel berubah tanpa ada yang dilabeli.');
        }
    }

    public function test_urutan_antrean_tidak_mengikuti_urutan_masuk(): void
    {
        // Kalau antrean mengikuti id, gold set berisi artikel dari periode dan
        // media yang berdekatan, dan angka akurasinya tidak mewakili apa pun.
        //
        // Sepuluh artikel, bukan tiga: dengan tiga, urutan acak punya peluang
        // satu berbanding enam kebetulan sama dengan urutan id, dan tesnya
        // lolos tanpa membuktikan apa pun.
        $media = Media::first();

        foreach (range(10, 19) as $n) {
            Artikel::withoutGlobalScopes()->create([
                'media_id' => $media->id,
                'judul' => "Berita {$n}",
                'url' => "https://kp.test/{$n}",
                'url_kanonik' => "https://kp.test/{$n}",
                'isi' => "Isi berita nomor {$n}.",
                'diambil_at' => now(),
            ]);
        }

        $urutan = [];

        for ($i = 0; $i < 10; $i++) {
            $artikel = Artikel::withoutGlobalScopes()->find(
                $this->actingAs($this->pelabel)
                    ->get('/admin/pelabelan')
                    ->viewData('page')['props']['tugas']['artikel']['id'],
            );

            $urutan[] = $artikel->id;
            $this->labeli($artikel);
        }

        $berurutan = $urutan;
        sort($berurutan);

        $this->assertNotSame($berurutan, $urutan, 'Antrean ternyata mengikuti urutan id.');
    }

    public function test_artikel_yang_sudah_dilabeli_tidak_disodorkan_lagi(): void
    {
        $pertama = Artikel::withoutGlobalScopes()->first();

        $this->labeli($pertama);

        $this->actingAs($this->pelabel)
            ->get('/admin/pelabelan')
            ->assertInertia(fn ($page) => $page->where('tugas.artikel.id', fn ($id) => $id !== $pertama->id));
    }

    /** Salah tekan harus bisa diperbaiki: label ditimpa, bukan ditambah. */
    public function test_melabeli_ulang_artikel_yang_sama_menimpa_label_lama(): void
    {
        $artikel = Artikel::withoutGlobalScopes()->first();

        $this->labeli($artikel, 'negatif');
        $this->labeli($artikel, 'positif');

        $this->assertSame(1, GoldSet::where('artikel_id', $artikel->id)->count());
        $this->assertSame(LabelSentimen::Positif, GoldSet::where('artikel_id', $artikel->id)->first()->label_gold);
    }

    public function test_panah_kiri_memuat_artikel_tertentu_untuk_diperbaiki(): void
    {
        $artikel = Artikel::withoutGlobalScopes()->first();

        $this->labeli($artikel);

        $this->actingAs($this->pelabel)
            ->get("/admin/pelabelan?konteks={$this->konteks->id}&ronde=1&artikel={$artikel->id}")
            ->assertInertia(fn ($page) => $page->where('tugas.artikel.id', $artikel->id));
    }

    /**
     * Setelah memperbaiki label lama, pelabel harus lanjut ke artikel berikutnya.
     * Kalau redirect membawa kembali parameter `artikel`, ia tersangkut pada
     * artikel yang sama dan pelabelan berhenti di situ.
     */
    public function test_setelah_memperbaiki_label_lama_lanjut_ke_artikel_berikutnya(): void
    {
        $artikel = Artikel::withoutGlobalScopes()->first();

        $this->labeli($artikel);

        $tanggapan = $this->actingAs($this->pelabel)
            ->from("/admin/pelabelan?konteks={$this->konteks->id}&ronde=1&artikel={$artikel->id}")
            ->post('/admin/pelabelan', [
                'artikel_id' => $artikel->id,
                'konteks_pantauan_id' => $this->konteks->id,
                'relevan_gold' => true,
                'label_gold' => 'positif',
                'ronde' => 1,
            ]);

        $tujuan = $tanggapan->headers->get('Location');

        $this->assertStringNotContainsString(
            'artikel=',
            $tujuan,
            'Redirect masih membawa parameter artikel — pelabel akan tersangkut pada artikel yang sama.',
        );
    }

    public function test_artikel_tidak_relevan_disimpan_tanpa_menuntut_label_nada(): void
    {
        $artikel = Artikel::withoutGlobalScopes()->first();

        $this->actingAs($this->pelabel)->post('/admin/pelabelan', [
            'artikel_id' => $artikel->id,
            'konteks_pantauan_id' => $this->konteks->id,
            'relevan_gold' => false,
            'ronde' => 1,
        ])->assertSessionHasNoErrors();

        $baris = GoldSet::where('artikel_id', $artikel->id)->first();

        $this->assertFalse($baris->relevan_gold);
        $this->assertSame($this->pelabel->id, $baris->dilabeli_oleh);
    }
}
