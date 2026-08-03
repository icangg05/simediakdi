<?php

namespace Tests\Feature;

use App\Models\Artikel;
use App\Models\Entitas;
use App\Models\Media;
use App\Models\User;
use App\Services\Nlp\PencocokEntitas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EntitasTest extends TestCase
{
    use RefreshDatabase;

    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();

        $this->media = Media::create(['nama' => 'Contoh', 'slug' => 'contoh', 'domain' => 'contoh.test']);
    }

    public function test_alias_ikut_terhitung_sebagai_sebutan_entitas_yang_sama(): void
    {
        $entitas = $this->entitas('Dinas Pekerjaan Umum', ['Dinas PU', 'DPU Kendari']);

        $artikel = $this->artikel(
            'Dinas PU perbaiki jalan',
            'Dinas Pekerjaan Umum menargetkan perbaikan selesai bulan ini. DPU Kendari menyebut anggarannya cukup.',
        );

        app(PencocokEntitas::class)->cocokkan($artikel);

        $this->assertSame(3, (int) DB::table('artikel_entitas')
            ->where('entitas_id', $entitas->id)->value('jumlah_sebutan'));
    }

    /**
     * Tanpa batas kata, "Kendari" ikut terhitung di dalam "Kendarian" dan
     * entitas lokasi tampak jauh lebih ramai daripada kenyataannya.
     */
    public function test_pencocokan_berhenti_di_batas_kata(): void
    {
        $entitas = $this->entitas('Kendari', []);

        $artikel = $this->artikel('Kendarian warga', 'Kendarian itu terparkir di depan. Tidak ada kaitannya.');

        app(PencocokEntitas::class)->cocokkan($artikel);

        $this->assertDatabaseMissing('artikel_entitas', ['entitas_id' => $entitas->id]);
    }

    /** Bentuk sangat pendek mencocoki apa saja, jadi dibuang dari kamus. */
    public function test_alias_di_bawah_tiga_huruf_diabaikan(): void
    {
        $entitas = $this->entitas('Dinas Pendidikan', ['PU']);

        $artikel = $this->artikel('Kabar lain', 'Kalimat ini memuat PU sebagai kebetulan belaka.');

        app(PencocokEntitas::class)->cocokkan($artikel);

        $this->assertDatabaseMissing('artikel_entitas', ['entitas_id' => $entitas->id]);
    }

    /**
     * Pencocokan ulang menulis ulang seluruh barisnya. Kalau ditambahkan,
     * sebutan yang sudah tidak berlaku setelah alias diperbaiki akan menumpuk.
     */
    public function test_pencocokan_ulang_tidak_menumpuk(): void
    {
        $entitas = $this->entitas('Wali Kota Kendari', []);
        $artikel = $this->artikel('Wali Kota Kendari meninjau pasar', 'Wali Kota Kendari hadir pagi tadi.');

        $pencocok = app(PencocokEntitas::class);
        $pencocok->cocokkan($artikel);
        $pencocok->cocokkan($artikel);

        $this->assertSame(1, DB::table('artikel_entitas')->where('entitas_id', $entitas->id)->count());
        $this->assertSame(2, (int) DB::table('artikel_entitas')
            ->where('entitas_id', $entitas->id)->value('jumlah_sebutan'));
    }

    /** F-18. Sebutan pindah ke induk, ejaan lama tersimpan sebagai alias. */
    public function test_penggabungan_memindahkan_sebutan_dan_menyimpan_ejaan_lama(): void
    {
        $induk = $this->entitas('Dinas Pekerjaan Umum', []);
        $duplikat = $this->entitas('Dinas PUPR Kendari', ['PUPR Kendari']);

        $artikel = $this->artikel('Judul', 'Isi berita.');
        DB::table('artikel_entitas')->insert([
            'artikel_id' => $artikel->id, 'entitas_id' => $duplikat->id, 'jumlah_sebutan' => 4,
        ]);

        $this->actingAs(User::factory()->create())
            ->post("/admin/entitas/{$duplikat->id}/gabungkan", ['induk_id' => $induk->id]);

        $this->assertSame(4, (int) DB::table('artikel_entitas')
            ->where('entitas_id', $induk->id)->value('jumlah_sebutan'));
        $this->assertSame(0, DB::table('artikel_entitas')->where('entitas_id', $duplikat->id)->count());

        $induk->refresh();
        $duplikat->refresh();

        $this->assertContains('Dinas PUPR Kendari', $induk->alias);
        $this->assertContains('PUPR Kendari', $induk->alias);
        $this->assertSame($induk->id, $duplikat->digabung_ke);
    }

    /** Entitas yang sudah dilebur tidak boleh ikut kamus, atau terhitung dua kali. */
    public function test_entitas_yang_sudah_digabungkan_keluar_dari_kamus(): void
    {
        $induk = $this->entitas('Dinas Pekerjaan Umum', []);
        $duplikat = $this->entitas('Dinas PUPR', []);
        $duplikat->update(['digabung_ke' => $induk->id]);

        $kamus = app(PencocokEntitas::class)->kamus();

        $this->assertSame([$induk->id], $kamus->pluck('id')->all());
    }

    /**
     * Nama yang sama dengan ejaan berbeda membelah hitungan sebutan jadi dua,
     * dan grafik entitas kehilangan artinya tanpa ada yang menyadarinya.
     */
    public function test_nama_yang_sama_setelah_dinormalkan_ditolak(): void
    {
        $this->entitas('Wali Kota Kendari', []);

        $this->actingAs(User::factory()->create())->post('/admin/entitas', [
            'nama' => 'wali kota kendari',
            'jenis' => 'orang',
        ])->assertSessionHasErrors('nama_normal');
    }

    /** @param  list<string>  $alias */
    private function entitas(string $nama, array $alias): Entitas
    {
        return Entitas::create([
            'nama' => $nama,
            'nama_normal' => app(PencocokEntitas::class)->normalkan($nama),
            'jenis' => 'opd',
            'alias' => $alias,
        ]);
    }

    private function artikel(string $judul, string $isi): Artikel
    {
        return Artikel::withoutGlobalScopes()->create([
            'media_id' => $this->media->id,
            'judul' => $judul,
            'isi' => $isi,
            'url' => 'https://contoh.test/'.md5($judul),
            'url_kanonik' => 'https://contoh.test/'.md5($judul),
            'diambil_at' => now(),
            'status_proses' => 'selesai',
        ]);
    }
}
