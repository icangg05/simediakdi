<?php

namespace Tests\Feature;

use App\Models\Artikel;
use App\Models\Media;
use App\Services\Agregasi\PenghitungKataKunci;
use App\Support\Waktu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Penghitungan ulang kata kunci harus menghasilkan keadaan yang sama seperti
 * penghitungan pertama, bukan menumpuk di atasnya.
 *
 * Versi lama memakai upsert, sehingga istilah yang tidak lagi lolos saringan
 * tetap duduk di peringkat teratas halaman isu hangat selamanya. Halaman yang
 * melaporkan "melalui" sebagai isu yang sedang naik tidak akan dipercaya untuk
 * hal lain apa pun.
 */
class KataKunciPerhitunganUlangTest extends TestCase
{
    use RefreshDatabase;

    private function artikel(string $isi): void
    {
        $media = Media::firstOrCreate(
            ['slug' => 'kp'],
            ['nama' => 'Kendari Pos', 'domain' => 'kp.test'],
        );

        Artikel::withoutGlobalScopes()->create([
            'media_id' => $media->id,
            'judul' => 'Berita drainase',
            'url' => 'https://kp.test/'.uniqid(),
            'url_kanonik' => 'https://kp.test/'.uniqid(),
            'isi' => $isi,
            'diambil_at' => now(),
            'status_proses' => 'selesai',
        ]);
    }

    public function test_baris_usang_dihapus_saat_dihitung_ulang(): void
    {
        $tanggal = Waktu::tanggalWita(now());

        // Tiga artikel: ambang MINIMAL_ARTIKEL memang tiga.
        for ($i = 0; $i < 3; $i++) {
            $this->artikel('Pembangunan drainase berjalan lancar di Kadia.');
        }

        app(PenghitungKataKunci::class)->hitung($tanggal, null);

        $this->assertDatabaseHas('kata_kunci_periode', ['istilah' => 'drainase']);

        // Baris palsu yang mewakili istilah yang tidak akan pernah muncul lagi,
        // persis seperti stopword yang baru ditambahkan ke daftar.
        DB::table('kata_kunci_periode')->insert([
            'konteks_pantauan_id' => null,
            'granularitas' => 'harian',
            'periode_mulai' => $tanggal,
            'periode_akhir' => $tanggal,
            'istilah' => 'melalui',
            'frekuensi' => 999,
            'jumlah_artikel' => 999,
            'created_at' => now(),
        ]);

        app(PenghitungKataKunci::class)->hitung($tanggal, null);

        $this->assertDatabaseMissing('kata_kunci_periode', ['istilah' => 'melalui']);
        $this->assertDatabaseHas('kata_kunci_periode', ['istilah' => 'drainase']);
    }

    public function test_angka_murni_tidak_pernah_jadi_istilah(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->artikel('Anggaran 2026 dipakai membangun drainase di Kadia.');
        }

        app(PenghitungKataKunci::class)->hitung(Waktu::tanggalWita(now()), null);

        // Tahun muncul di hampir setiap berita dan selalu naik ke peringkat
        // teratas, padahal tahun bukan isu.
        $this->assertDatabaseMissing('kata_kunci_periode', ['istilah' => '2026']);
        $this->assertDatabaseHas('kata_kunci_periode', ['istilah' => 'anggaran']);
    }

    public function test_kata_sambung_tidak_ikut_terhitung(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->artikel('Bantuan disalurkan melalui kelurahan serta seluruh kecamatan.');
        }

        app(PenghitungKataKunci::class)->hitung(Waktu::tanggalWita(now()), null);

        foreach (['melalui', 'serta', 'seluruh'] as $sambung) {
            $this->assertDatabaseMissing('kata_kunci_periode', ['istilah' => $sambung]);
        }

        $this->assertDatabaseHas('kata_kunci_periode', ['istilah' => 'kelurahan']);
    }
}
