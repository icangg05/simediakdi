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

    private function artikel(string $isi, ?string $diambil = null): void
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
            'diambil_at' => $diambil ?? now(),
            'status_proses' => 'selesai',
        ]);
    }

    public function test_skor_lonjakan_dihitung_dari_periode_sebelumnya(): void
    {
        $kemarin = now()->subDay();

        for ($i = 0; $i < 3; $i++) {
            $this->artikel('Pembangunan drainase berjalan lancar di Kadia.', $kemarin->toDateTimeString());
        }

        app(PenghitungKataKunci::class)->hitung(Waktu::tanggalWita($kemarin));

        for ($i = 0; $i < 3; $i++) {
            $this->artikel('Pembangunan drainase berjalan lancar di Kadia.');
        }

        app(PenghitungKataKunci::class)->hitung(Waktu::tanggalWita(now()));

        // Istilah yang sudah muncul kemarin dinilai sebagai rasio terhadap
        // kemarin, bukan sebagai frekuensi mentah. Frekuensi yang sama persis
        // dua hari berturut-turut berarti skor 1, bukan lonjakan.
        //
        // Jalur ini sempat mati sama sekali di Postgres: rata-rata periode
        // sebelumnya diambil dengan pluck tanpa alias, dan galatnya hanya
        // muncul kalau memang ada periode sebelumnya yang cocok. Seluruh test
        // lain di berkas ini menghitung satu hari saja, jadi tidak ada yang
        // pernah menyentuhnya.
        $baris = DB::table('kata_kunci_periode')
            ->where('istilah', 'drainase')
            ->where('periode_mulai', Waktu::tanggalWita(now()))
            ->first();

        $this->assertNotNull($baris);
        $this->assertSame(1.0, (float) $baris->skor_lonjakan);
    }

    public function test_baris_usang_dihapus_saat_dihitung_ulang(): void
    {
        $tanggal = Waktu::tanggalWita(now());

        // Tiga artikel: ambang MINIMAL_ARTIKEL memang tiga.
        for ($i = 0; $i < 3; $i++) {
            $this->artikel('Pembangunan drainase berjalan lancar di Kadia.');
        }

        app(PenghitungKataKunci::class)->hitung($tanggal);

        $this->assertDatabaseHas('kata_kunci_periode', ['istilah' => 'drainase']);

        // Baris palsu yang mewakili istilah yang tidak akan pernah muncul lagi,
        // persis seperti stopword yang baru ditambahkan ke daftar.
        DB::table('kata_kunci_periode')->insert([
            'granularitas' => 'harian',
            'periode_mulai' => $tanggal,
            'periode_akhir' => $tanggal,
            'istilah' => 'melalui',
            'frekuensi' => 999,
            'jumlah_artikel' => 999,
            'created_at' => now(),
        ]);

        app(PenghitungKataKunci::class)->hitung($tanggal);

        $this->assertDatabaseMissing('kata_kunci_periode', ['istilah' => 'melalui']);
        $this->assertDatabaseHas('kata_kunci_periode', ['istilah' => 'drainase']);
    }

    public function test_angka_murni_tidak_pernah_jadi_istilah(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->artikel('Anggaran 2026 dipakai membangun drainase di Kadia.');
        }

        app(PenghitungKataKunci::class)->hitung(Waktu::tanggalWita(now()));

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

        app(PenghitungKataKunci::class)->hitung(Waktu::tanggalWita(now()));

        foreach (['melalui', 'serta', 'seluruh'] as $sambung) {
            $this->assertDatabaseMissing('kata_kunci_periode', ['istilah' => $sambung]);
        }

        $this->assertDatabaseHas('kata_kunci_periode', ['istilah' => 'kelurahan']);
    }
}
