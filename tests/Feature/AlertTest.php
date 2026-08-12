<?php

namespace Tests\Feature;

use App\Enums\LabelSentimen;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\AturanAlert;
use App\Models\Media;
use App\Models\PengaturanAlert;
use App\Models\RiwayatAlert;
use App\Models\User;
use App\Services\Alert\PemeriksaAturan;
use App\Services\Alert\PengirimTelegram;
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
        $this->kredensialTelegram();
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
        $this->kredensialTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['description' => 'chat not found'], 400)]);

        $this->artikelNegatif(6, jamLalu: 1);
        $this->aturan();

        $this->artisan('alert:periksa');
        $this->artisan('alert:periksa');

        $this->assertSame(1, RiwayatAlert::count());
    }

    /**
     * Notifikasi uji membawa satu berita sungguhan, sama seperti alert
     * berita negatif yang akan datang nanti, bukan kalimat tetap.
     */
    public function test_notifikasi_uji_memuat_berita_negatif_terakhir(): void
    {
        $this->kredensialTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->artikelNegatif(1, jamLalu: 5);
        $this->artikelNegatif(1, jamLalu: 1);

        $this->actingAs(User::factory()->create())
            ->post('/admin/alert/uji-telegram')
            ->assertSessionHas('sukses');

        Http::assertSent(function ($request) {
            // Satu berita saja, yang jam 1 lalu, bukan yang jam 5 lalu.
            return str_contains($request['text'], 'Berita negatif 1-0')
                && ! str_contains($request['text'], 'Berita negatif 5-0');
        });
    }

    /** Arsip kosong tetap mengirim, hanya pesannya pendek. */
    public function test_notifikasi_uji_jatuh_ke_pesan_singkat_saat_tidak_ada_berita_negatif(): void
    {
        $this->kredensialTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->actingAs(User::factory()->create())
            ->post('/admin/alert/uji-telegram')
            ->assertSessionHas('sukses');

        Http::assertSent(fn ($request) => str_contains($request['text'], 'Belum ada berita negatif di arsip'));
    }

    /**
     * Pesan uji harus tidak mungkin dikira alert sungguhan.
     *
     * Isinya sengaja sama persis, karena itulah yang sedang diuji. Yang
     * membedakan hanya satu baris penanda, dan baris itu wajib ada di kepala
     * pesan supaya terbaca di pratinjau notifikasi tanpa membuka aplikasinya.
     */
    public function test_notifikasi_uji_ditandai_sebagai_uji_coba(): void
    {
        $this->kredensialTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->artikelNegatif(1, jamLalu: 1);

        $this->actingAs(User::factory()->create())->post('/admin/alert/uji-telegram');

        Http::assertSent(function ($request) {
            // Penanda hanya satu baris di kepala, dan isinya di bawahnya
            // adalah alert apa adanya, tanpa satu kata tambahan pun.
            // Namanya sengaja tidak ikut diperiksa. Nama aturan adalah data
            // yang boleh diganti admin kapan saja, dan tes yang terikat
            // padanya pecah setiap kali kalimatnya diperhalus.
            return str_starts_with($request['text'], "🧪 <b>UJI COBA</b>\n\n🚨 <b>")
                && str_contains($request['text'], 'Berita negatif 1-0');
        });
    }

    /**
     * Alert sungguhan membawa berita yang memicunya, bukan angkanya saja.
     *
     * "12 berita negatif dalam 6 jam" tidak memberitahu apa yang sedang
     * terjadi, dan penerimanya tetap harus membuka dashboard untuk tahu apakah
     * ini satu peristiwa besar atau dua belas hal kecil.
     */
    public function test_alert_sungguhan_memuat_judul_berita_pemicunya(): void
    {
        $this->kredensialTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->artikelNegatif(6, jamLalu: 1);
        $this->aturan();

        $this->artisan('alert:periksa')->assertSuccessful();

        Http::assertSent(function ($request) {
            return str_starts_with($request['text'], '📈 <b>Lonjakan negatif Pemkot</b>')
                && str_contains($request['text'], 'Berita negatif 1-')
                && ! str_contains($request['text'], 'UJI COBA');
        });
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

    /**
     * Kredensial yang diisi dari layar itulah yang dipakai pengirim.
     *
     * Satu-satunya sumbernya sekarang database. Kalau jalur ini pernah putus,
     * admin yang mengganti chat ID karena grup Diskominfo dibuat ulang akan
     * melihat form tersimpan rapi sementara alert tetap dikirim ke grup lama.
     */
    public function test_kredensial_telegram_dari_layar_dipakai_pengirim(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->actingAs(User::factory()->create())
            ->put('/admin/pengaturan/telegram', [
                'telegram_token' => '987654321:token-dari-layar',
                'telegram_chat_id' => '-1009999999999',
            ])
            ->assertSessionHas('sukses');

        app(PengirimTelegram::class)->kirim('halo');

        Http::assertSent(fn ($request) => str_contains($request->url(), '987654321:token-dari-layar')
            && $request['chat_id'] === '-1009999999999');
    }

    /**
     * Token yang dibiarkan kosong berarti "biarkan yang tersimpan".
     *
     * Token tidak pernah dikirim kembali ke layar, jadi kotaknya selalu kosong
     * saat halaman dibuka. Kalau kosong diartikan "hapus", admin yang hanya
     * ingin mengganti chat ID akan mematikan seluruh alert tanpa satu pun
     * pesan yang menyebutkan sebabnya.
     */
    public function test_token_kosong_tidak_menghapus_token_yang_tersimpan(): void
    {
        $pengguna = User::factory()->create();

        $this->actingAs($pengguna)->put('/admin/pengaturan/telegram', [
            'telegram_token' => '111111111:token-pertama',
            'telegram_chat_id' => '-100111',
        ]);

        $this->actingAs($pengguna)->put('/admin/pengaturan/telegram', [
            'telegram_token' => '',
            'telegram_chat_id' => '-100222',
        ]);

        $pengaturan = PengaturanAlert::aktif();

        $this->assertSame('111111111:token-pertama', $pengaturan->token());
        $this->assertSame('-100222', $pengaturan->chatId());
    }

    /**
     * Chat ID yang dikosongkan benar-benar mengosongkan kanal.
     *
     * Tidak ada lagi cadangan `.env` yang diam-diam mengambil alih, jadi
     * kanalnya terbaca belum siap sampai ada yang mengisinya lagi.
     */
    public function test_chat_id_kosong_mengosongkan_kanal(): void
    {
        $pengguna = User::factory()->create();

        $this->actingAs($pengguna)->put('/admin/pengaturan/telegram', [
            'telegram_token' => '222222222:token-kedua',
            'telegram_chat_id' => '-100333',
        ]);

        $this->actingAs($pengguna)->put('/admin/pengaturan/telegram', [
            'telegram_token' => '',
            'telegram_chat_id' => '',
        ]);

        $this->assertSame('', PengaturanAlert::aktif()->chatId());
        $this->assertFalse(PengaturanAlert::aktif()->siap());
    }

    /** Salah tempel yang paling sering terjadi ditahan sebelum tersimpan. */
    public function test_token_yang_bukan_bentuk_token_bot_ditolak(): void
    {
        $this->actingAs(User::factory()->create())
            ->put('/admin/pengaturan/telegram', [
                'telegram_token' => 'https://t.me/BotDiskominfoKendari',
                'telegram_chat_id' => '-100444',
            ])
            ->assertSessionHasErrors('telegram_token');

        $this->assertNull(PengaturanAlert::aktif()->telegram_token);
    }

    /**
     * Kredensial Telegram hanya ada di database, jadi pengujian yang butuh
     * kanal hidup mengisinya di sana, bukan lewat config.
     */
    private function kredensialTelegram(): void
    {
        PengaturanAlert::aktif()->update([
            'telegram_token' => '123456789:palsu',
            'telegram_chat_id' => '-100',
        ]);
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
