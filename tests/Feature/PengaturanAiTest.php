<?php

namespace Tests\Feature;

use App\Models\KunciGemini;
use App\Models\PengaturanAi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penyuntingan pengaturan Gemini dari halaman Pengaturan.
 *
 * Yang diuji di sini penjaga yang kegagalannya tidak menimbulkan galat sampai
 * ada yang menekan tombol Klasifikasi: kunci terakhir yang terhapus membuat
 * seluruh sistem berhenti menilai, dan halaman yang menyembunyikan tombolnya
 * tidak menahan permintaan yang dikirim langsung.
 */
class PengaturanAiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['peran' => 'superadmin']);
    }

    public function test_kunci_terakhir_tidak_bisa_dihapus(): void
    {
        $kunci = $this->kunci('Satu-satunya');

        $this->actingAs($this->admin)
            ->delete("/admin/pengaturan/kunci/{$kunci->id}")
            ->assertRedirect()
            ->assertSessionHas('galat');

        $this->assertDatabaseCount('kunci_gemini', 1);
    }

    public function test_kunci_terakhir_tidak_bisa_dimatikan(): void
    {
        $kunci = $this->kunci('Satu-satunya');

        $this->actingAs($this->admin)
            ->put("/admin/pengaturan/kunci/{$kunci->id}", ['aktif' => false])
            ->assertSessionHas('galat');

        $this->assertTrue($kunci->fresh()->aktif);
    }

    /**
     * Kunci tidak bisa dimatikan satu per satu sampai habis.
     *
     * Penjaga yang menghitung jumlah baris, bukan jumlah kunci menyala, akan
     * meloloskan keduanya: setiap langkah lolos karena barisnya masih dua.
     * Akibatnya klasifikasi berhenti tanpa satu pun galat, dan baru ketahuan
     * saat ada yang menekan tombol Klasifikasi.
     */
    public function test_kunci_tidak_bisa_dimatikan_semua_satu_per_satu(): void
    {
        $pertama = $this->kunci('Kunci A');
        $kedua = $this->kunci('Kunci B');

        $this->actingAs($this->admin)
            ->put("/admin/pengaturan/kunci/{$pertama->id}", ['aktif' => false])
            ->assertSessionHas('sukses');

        $this->actingAs($this->admin)
            ->put("/admin/pengaturan/kunci/{$kedua->id}", ['aktif' => false])
            ->assertSessionHas('galat');

        $this->assertTrue($kedua->fresh()->aktif);
        $this->assertSame(1, KunciGemini::query()->where('aktif', true)->count());
    }

    /** Kunci menyala terakhir juga tidak bisa dihapus, sekalipun barisnya lebih dari satu. */
    public function test_kunci_menyala_terakhir_tidak_bisa_dihapus(): void
    {
        $mati = $this->kunci('Kunci A');
        $mati->update(['aktif' => false]);

        $menyala = $this->kunci('Kunci B');

        $this->actingAs($this->admin)
            ->delete("/admin/pengaturan/kunci/{$menyala->id}")
            ->assertSessionHas('galat');

        $this->assertDatabaseHas('kunci_gemini', ['id' => $menyala->id]);

        // Yang sudah mati boleh dihapus: menghapusnya tidak mengurangi kunci
        // menyala satu pun.
        $this->actingAs($this->admin)
            ->delete("/admin/pengaturan/kunci/{$mati->id}")
            ->assertSessionHas('sukses');
    }

    public function test_kunci_bisa_dihapus_selama_masih_ada_penggantinya(): void
    {
        $kunci = $this->kunci('Kunci A');
        $this->kunci('Kunci B');

        $this->actingAs($this->admin)
            ->delete("/admin/pengaturan/kunci/{$kunci->id}")
            ->assertSessionHas('sukses');

        $this->assertDatabaseCount('kunci_gemini', 1);
    }

    /** Prompt yang disunting langsung berlaku pada klasifikasi berikutnya. */
    public function test_prompt_yang_disimpan_langsung_dipakai(): void
    {
        // Tanpa spasi di ujung: middleware TrimStrings memangkasnya, dan
        // perbandingan di bawah membandingkan yang benar-benar tersimpan.
        $prompt = trim(str_repeat('Nilai relevansi artikel ini. ', 5));

        $this->actingAs($this->admin)
            ->put('/admin/pengaturan/ai', [
                'model' => 'gemini-uji',
                'versi_prompt_relevansi' => 'relevance-v3',
                'prompt_relevansi' => $prompt,
                'versi_prompt_sentimen' => PengaturanAi::aktif()->versi_prompt_sentimen,
                'prompt_sentimen' => PengaturanAi::aktif()->prompt_sentimen,
            ])
            ->assertSessionHas('sukses');

        $pengaturan = PengaturanAi::aktif();

        $this->assertSame('gemini-uji', $pengaturan->model);
        $this->assertSame($prompt, $pengaturan->prompt_relevansi);
        $this->assertSame('relevance-v3.'.substr(sha1($prompt), 0, 8), $pengaturan->versiRelevansi());
    }

    /** Prompt kosong ditolak, bukan disimpan lalu dikirim ke Gemini apa adanya. */
    public function test_prompt_kosong_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->put('/admin/pengaturan/ai', [
                'model' => 'gemini-uji',
                'versi_prompt_relevansi' => 'relevance-v3',
                'prompt_relevansi' => 'nilai saja',
                'versi_prompt_sentimen' => 'sentiment-v3',
                'prompt_sentimen' => '',
            ])
            ->assertSessionHasErrors(['prompt_relevansi', 'prompt_sentimen']);
    }

    private function kunci(string $label): KunciGemini
    {
        return KunciGemini::create([
            'label' => $label,
            'kunci' => str($label)->slug()->prepend('kunci-')->value(),
        ]);
    }
}
