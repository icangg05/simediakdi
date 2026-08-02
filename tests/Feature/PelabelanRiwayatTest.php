<?php

namespace Tests\Feature;

use App\Models\Artikel;
use App\Models\KonteksPantauan;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Riwayat pelabelan harus bertahan melewati muat ulang halaman.
 *
 * Versi pertama menyimpannya di tumpukan browser, dan pelabel kehilangan jalan
 * kembali ke keputusan sebelumnya begitu halaman disegarkan — persis di tengah
 * pekerjaan 400 baris yang dikerjakan dalam beberapa sesi.
 */
class PelabelanRiwayatTest extends TestCase
{
    use RefreshDatabase;

    private KonteksPantauan $konteks;

    private User $pelabel;

    /** @var array<int, Artikel> */
    private array $artikel = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->pelabel = User::factory()->create();
        $this->konteks = KonteksPantauan::create(['nama' => 'Pemkot', 'slug' => 'pemkot', 'utama' => true]);

        $media = Media::create(['nama' => 'KP', 'slug' => 'kp', 'domain' => 'kp.test']);

        foreach (range(1, 4) as $n) {
            $this->artikel[$n] = Artikel::withoutGlobalScopes()->create([
                'media_id' => $media->id,
                'judul' => "Berita {$n}",
                'url' => "https://kp.test/{$n}",
                'url_kanonik' => "https://kp.test/{$n}",
                'isi' => "Isi berita {$n}.",
                'diambil_at' => now(),
            ]);
        }
    }

    private function labeli(int $nomor, string $label = 'netral'): void
    {
        $this->actingAs($this->pelabel)->post('/admin/pelabelan', [
            'artikel_id' => $this->artikel[$nomor]->id,
            'konteks_pantauan_id' => $this->konteks->id,
            'relevan_gold' => true,
            'label_gold' => $label,
            'ronde' => 1,
        ]);

        // Urutan riwayat memakai dilabeli_at; tanpa jeda, tiga baris bisa
        // punya stempel waktu identik dan urutannya jadi acak.
        $this->travel(1)->seconds();
    }

    private function bukaHalaman(?int $artikelId = null): \Illuminate\Testing\TestResponse
    {
        $kueri = ['konteks' => $this->konteks->id, 'ronde' => 1];

        if ($artikelId !== null) {
            $kueri['artikel'] = $artikelId;
        }

        return $this->actingAs($this->pelabel)->get('/admin/pelabelan?'.http_build_query($kueri));
    }

    public function test_riwayat_tetap_ada_setelah_halaman_dimuat_ulang(): void
    {
        $this->labeli(1);
        $this->labeli(2);

        // Muat ulang bersih, tanpa membawa keadaan apa pun dari sisi klien.
        $this->bukaHalaman()->assertInertia(
            fn ($page) => $page
                ->where('riwayat.sebelumnya', $this->artikel[2]->id)
                ->has('riwayat.terakhir', 2),
        );
    }

    public function test_sebelumnya_dihitung_relatif_terhadap_artikel_yang_dibuka(): void
    {
        $this->labeli(1);
        $this->labeli(2);
        $this->labeli(3);

        // Saat membuka artikel 2, "sebelumnya" harus artikel 1 — bukan artikel 3
        // yang paling akhir dilabeli. Kalau tidak, panah kiri hanya bolak-balik.
        $this->bukaHalaman($this->artikel[2]->id)->assertInertia(
            fn ($page) => $page->where('riwayat.sebelumnya', $this->artikel[1]->id),
        );
    }

    public function test_artikel_pertama_tidak_punya_sebelumnya(): void
    {
        $this->labeli(1);

        $this->bukaHalaman($this->artikel[1]->id)->assertInertia(
            fn ($page) => $page->where('riwayat.sebelumnya', null),
        );
    }

    public function test_berikutnya_menelusuri_riwayat_ke_arah_maju(): void
    {
        $this->labeli(1);
        $this->labeli(2);
        $this->labeli(3);

        $this->bukaHalaman($this->artikel[1]->id)->assertInertia(
            fn ($page) => $page->where('riwayat.berikutnya', $this->artikel[2]->id),
        );
    }

    /** Di ujung riwayat, maju berarti kembali ke antrean — bukan buntu. */
    public function test_label_terakhir_tidak_punya_berikutnya(): void
    {
        $this->labeli(1);
        $this->labeli(2);

        $this->bukaHalaman($this->artikel[2]->id)->assertInertia(
            fn ($page) => $page->where('riwayat.berikutnya', null),
        );
    }

    public function test_antrean_biasa_tidak_menawarkan_maju_mundur_riwayat(): void
    {
        $this->labeli(1);

        $this->bukaHalaman()->assertInertia(
            fn ($page) => $page->where('riwayat.berikutnya', null),
        );
    }

    public function test_progres_per_konteks_dihitung_terpisah(): void
    {
        $lain = KonteksPantauan::create(['nama' => 'Wali Kota', 'slug' => 'wali-kota']);

        $this->labeli(1);
        $this->labeli(2);

        $this->actingAs($this->pelabel)->post('/admin/pelabelan', [
            'artikel_id' => $this->artikel[3]->id,
            'konteks_pantauan_id' => $lain->id,
            'relevan_gold' => true,
            'label_gold' => 'netral',
            'ronde' => 1,
        ]);

        $this->bukaHalaman()->assertInertia(
            fn ($page) => $page
                ->where('progres.selesai', 3)
                ->where('progres.perKonteks', 2),
        );
    }

    public function test_membuka_artikel_lama_menampilkan_label_yang_dulu_dipilih(): void
    {
        $this->labeli(1, 'negatif');

        $this->bukaHalaman($this->artikel[1]->id)->assertInertia(
            fn ($page) => $page
                ->where('sedangMengulang', true)
                ->where('tugas.labelTersimpan.label', 'negatif')
                ->where('tugas.labelTersimpan.relevan', true),
        );
    }

    public function test_antrean_biasa_tidak_ditandai_sedang_mengulang(): void
    {
        $this->labeli(1);

        $this->bukaHalaman()->assertInertia(
            fn ($page) => $page
                ->where('sedangMengulang', false)
                ->where('tugas.labelTersimpan', null),
        );
    }

    public function test_daftar_riwayat_membawa_judul_dan_label_untuk_diklik(): void
    {
        $this->labeli(1, 'positif');

        $this->bukaHalaman()->assertInertia(
            fn ($page) => $page
                ->where('riwayat.terakhir.0.artikel_id', $this->artikel[1]->id)
                ->where('riwayat.terakhir.0.judul', 'Berita 1')
                ->where('riwayat.terakhir.0.label', 'positif')
                ->where('riwayat.terakhir.0.relevan', true),
        );
    }
}
