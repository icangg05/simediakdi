<?php

namespace Tests\Feature;

use App\Enums\LabelSentimen;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\AturanAlert;
use App\Models\Media;
use App\Models\RiwayatAlert;
use App\Models\User;
use App\Services\Alert\PemeriksaAturan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Alert punya dua cara gagal, dan yang kedua jauh lebih mahal.
 *
 * Gagal pertama: peringatan tidak terkirim saat seharusnya. Gagal kedua:
 * peringatan terkirim terus-menerus sampai orang mematikan notifikasi grup,
 * dan setelah itu tidak ada alert yang pernah sampai lagi. Tes di bawah
 * menjaga keduanya.
 */
class AlertTest extends TestCase
{
    use RefreshDatabase;

    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->media = Media::create(['nama' => 'Contoh', 'slug' => 'contoh', 'domain' => 'contoh.test']);
    }

    public function test_lonjakan_negatif_terpicu_saat_melewati_kedua_syarat(): void
    {
        $this->artikelNegatif(6, jamLalu: 1);

        $hasil = app(PemeriksaAturan::class)->nilai($this->aturan());

        $this->assertNotNull($hasil);
        $this->assertStringContainsString('6 berita negatif', $hasil['ringkasan']);
    }

    /**
     * Kelipatan saja tidak cukup. Tanpa minimal_artikel, kenaikan 1 ke 3
     * artikel terbaca sebagai lonjakan 300% dan alert menyala hampir tiap hari
     * di kota sebesar Kendari.
     */
    public function test_jumlah_di_bawah_minimal_tidak_memicu_meski_kelipatannya_besar(): void
    {
        $this->artikelNegatif(2, jamLalu: 1);

        $this->assertNull(app(PemeriksaAturan::class)->nilai($this->aturan(['minimal_artikel' => 5])));
    }

    /** Volume yang tinggi tapi memang biasanya segitu bukan lonjakan. */
    public function test_tidak_terpicu_saat_jumlahnya_setara_periode_sebelumnya(): void
    {
        $this->artikelNegatif(4, jamLalu: 1);
        // Empat jendela sebelumnya, masing-masing empat artikel.
        foreach ([8, 14, 20, 26] as $jam) {
            $this->artikelNegatif(4, jamLalu: $jam);
        }

        $this->assertNull(app(PemeriksaAturan::class)->nilai($this->aturan()));
    }

    /** F-40: pembatas pengiriman berulang. */
    public function test_aturan_yang_baru_dikirim_dilewati_sampai_jedanya_habis(): void
    {
        $this->artikelNegatif(6, jamLalu: 1);

        $aturan = $this->aturan();
        $aturan->update(['dipicu_terakhir_at' => now()->subHours(2), 'jeda_minimal_jam' => 6]);

        $this->artisan('alert:periksa')->assertSuccessful();

        $this->assertSame(0, RiwayatAlert::count());
    }

    /**
     * Alert yang gagal terkirim tanpa jejak adalah kegagalan yang tidak
     * diketahui siapa pun sampai ada yang bertanya kenapa tidak ada kabar.
     */
    public function test_kegagalan_pengiriman_tetap_tercatat_di_riwayat(): void
    {
        config(['alert.telegram.token' => 'palsu', 'alert.telegram.chat_id' => '-100']);
        Http::fake(['api.telegram.org/*' => Http::response(['description' => 'chat not found'], 400)]);

        $this->artikelNegatif(6, jamLalu: 1);
        $this->aturan();

        $this->artisan('alert:periksa')->assertSuccessful();

        $riwayat = RiwayatAlert::firstOrFail();

        $this->assertSame('gagal', $riwayat->status_kirim);
        $this->assertSame('chat not found', $riwayat->pesan_error);
    }

    /**
     * Ditandai terpicu meskipun pengiriman gagal. Kalau tidak, aturan yang
     * gagal kirim mencoba lagi tiap 15 menit dan membanjiri riwayat dengan
     * baris gagal yang sama.
     */
    public function test_pengiriman_gagal_tetap_menahan_percobaan_berikutnya(): void
    {
        config(['alert.telegram.token' => 'palsu', 'alert.telegram.chat_id' => '-100']);
        Http::fake(['api.telegram.org/*' => Http::response(['description' => 'chat not found'], 400)]);

        $this->artikelNegatif(6, jamLalu: 1);
        $this->aturan();

        $this->artisan('alert:periksa');
        $this->artisan('alert:periksa');

        $this->assertSame(1, RiwayatAlert::count());
    }

    public function test_aturan_kata_kunci_tanpa_istilah_ditolak_saat_disimpan(): void
    {
        $this->actingAs(User::factory()->create())->post('/admin/alert', [
            'nama' => 'Pantau demo',
            'jenis' => 'kata_kunci_muncul',
            'jendela_jam' => 6,
            'jeda_minimal_jam' => 6,
            'kanal' => 'telegram',
            'kondisi' => ['istilah' => []],
        ])->assertSessionHasErrors('kondisi.istilah');
    }

    /** @param  array<string, mixed>  $kondisi */
    private function aturan(array $kondisi = []): AturanAlert
    {
        return AturanAlert::create([
            'nama' => 'Lonjakan negatif Pemkot',
            'jenis' => 'lonjakan_negatif',
            'kondisi' => [
                'minimal_artikel' => 3,
                'kelipatan_dari_rata_rata' => 2.0,
                'abaikan_perlu_review' => true,
                ...$kondisi,
            ],
            'jendela_jam' => 6,
            'jeda_minimal_jam' => 6,
            'kanal' => 'telegram',
            'penerima' => [],
            'aktif' => true,
        ]);
    }

    private function artikelNegatif(int $jumlah, int $jamLalu): void
    {
        for ($i = 0; $i < $jumlah; $i++) {
            $artikel = Artikel::withoutGlobalScopes()->create([
                'media_id' => $this->media->id,
                'judul' => "Berita negatif {$jamLalu}-{$i}",
                'url' => "https://contoh.test/n-{$jamLalu}-{$i}",
                'url_kanonik' => "https://contoh.test/n-{$jamLalu}-{$i}",
                'diambil_at' => now()->subHours($jamLalu),
                'status_proses' => 'selesai',
            ]);

            AnalisisSentimen::create([
                'artikel_id' => $artikel->id,
                'relevan' => true,
                'label_model' => LabelSentimen::Negatif,
                'perlu_review' => false,
                'model_versi' => 'uji',
                'dianalisis_at' => now(),
            ]);
        }
    }
}
