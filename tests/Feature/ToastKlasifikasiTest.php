<?php

namespace Tests\Feature;

use App\Ai\Agents\RelevanceClassifier;
use App\Ai\Agents\SentimentClassifier;
use App\Models\Artikel;
use App\Models\KunciGemini;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Flash hasil klasifikasi harus sampai ke halaman, termasuk saat versi aset berubah.
 *
 * Kunjungan Inertia yang versinya sudah basi dijawab 409 dan berubah menjadi muat
 * penuh. Permintaan 409 itu sendiri ikut menua data flash, jadi tanpa `reflash`
 * pesannya habis di tengah jalan dan toast setelah menekan Klasifikasi tidak
 * pernah tampil. Itu terjadi setiap kali aset dibangun ulang, bukan sesekali.
 */
class ToastKlasifikasiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Relevansi dan sentimen adalah dua pemakaian Gemini terpisah. Sediakan
        // dua kunci agar keduanya dapat berjalan tanpa melanggar jeda per kunci.
        KunciGemini::create(['label' => 'Kunci uji 1', 'kunci' => 'kunci-uji-pertama-yang-cukup-panjang']);
        KunciGemini::create(['label' => 'Kunci uji 2', 'kunci' => 'kunci-uji-kedua-yang-cukup-panjang']);

        $this->actingAs(User::factory()->create());
    }

    public function test_flash_sampai_ke_halaman_setelah_klasifikasi(): void
    {
        $artikel = $this->artikel();

        $this->from('/admin/artikel')
            ->post("/admin/artikel/{$artikel->id}/klasifikasi")
            ->assertRedirect('/admin/artikel');

        $flash = $this->get('/admin/artikel')->viewData('page')['props']['flash'];

        $this->assertSame($artikel->judul, $flash['sukses']);
        $this->assertSame('relevan', $flash['nada']);
        $this->assertSame('positif', $flash['sentimen']);
    }

    public function test_flash_bertahan_saat_versi_aset_berubah(): void
    {
        $artikel = $this->artikel();

        $this->from('/admin/artikel')->post("/admin/artikel/{$artikel->id}/klasifikasi");

        // Kunjungan Inertia dengan versi basi. Dijawab 409, dan flash-nya harus
        // diteruskan ke muat penuh yang menyusul.
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => 'versi-basi'])
            ->get('/admin/artikel')
            ->assertStatus(409);

        // Muat penuh yang menyusul, tanpa header Inertia.
        $flash = $this->flushHeaders()->get('/admin/artikel')->viewData('page')['props']['flash'];

        $this->assertSame($artikel->judul, $flash['sukses']);
    }

    /** Artikel siap klasifikasi, dengan jawaban Gemini yang dipalsukan. */
    private function artikel(): Artikel
    {
        $media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);

        $kutipan = 'Pemerintah Kota Kendari memperbaiki drainase di Kecamatan Kadia';

        $artikel = Artikel::create([
            'media_id' => $media->id,
            'judul' => 'Pemkot Kendari memperbaiki drainase di Kadia',
            'url' => 'https://kp.test/drainase',
            'url_kanonik' => 'https://kp.test/drainase',
            'isi' => $kutipan.'. Pekerjaan dimulai pekan ini.',
            'dipublikasikan_at' => now(),
            'diambil_at' => now(),
            'status_proses' => 'isi_diambil',
        ]);

        $jawaban = fn (string $label) => [
            'label' => $label,
            'reason_code' => 'program_pemkot',
            'reason_summary' => 'Artikel membahas drainase Pemkot Kendari.',
            'evidence' => [$kutipan],
            'requires_manual_review' => false,
        ];

        RelevanceClassifier::fake([$jawaban('relevan')]);
        SentimentClassifier::fake([$jawaban('positif')]);

        return $artikel;
    }
}
