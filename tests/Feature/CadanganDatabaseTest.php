<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Halaman cadangan database.
 *
 * Yang diuji di sini hanya bagian yang bisa rusak diam-diam, yaitu penjaga
 * nama berkas dan batas peran. Pembuatan cadangannya sendiri tidak diuji:
 * ia memanggil pg_dump sungguhan terhadap basis data sungguhan, dan tes yang
 * hasilnya bergantung pada paket yang terpasang di mesin penjalannya adalah
 * tes yang cepat atau lambat dimatikan orang.
 */
class CadanganDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        // Disk palsu, supaya tes tidak menulis berkas ke storage sungguhan.
        // `path()` tetap mengembalikan jalur nyata di folder sementara, jadi
        // response()->download() di controller tetap terlayani apa adanya.
        Storage::fake('local');

        $this->superadmin = User::factory()->create(['peran' => 'superadmin']);
    }

    public function test_halaman_terbuka_untuk_superadmin(): void
    {
        $this->actingAs($this->superadmin)
            ->get('/admin/cadangan')
            ->assertOk();
    }

    /**
     * Walikota tidak boleh menyentuh halaman ini sama sekali.
     *
     * Satu berkas cadangan berisi seluruh isi basis data, termasuk kredensial
     * media partner. Batasnya lebih ketat daripada halaman admin lain yang
     * memang boleh dibaca walikota.
     */
    public function test_walikota_ditolak(): void
    {
        $walikota = User::factory()->create(['peran' => 'walikota']);

        $this->actingAs($walikota)
            ->get('/admin/cadangan')
            ->assertForbidden();
    }

    /**
     * Nama berkas datang dari URL, jadi ia masukan yang tidak dipercaya.
     *
     * Ini penjaga yang paling penting di seluruh fitur ini: tanpa polanya,
     * rute unduh berubah menjadi pembaca berkas apa pun di dalam storage,
     * termasuk arsip bukti pemuatan milik media lain.
     */
    public function test_nama_berkas_di_luar_pola_ditolak(): void
    {
        $nakal = [
            '../../../.env',
            '..%2F..%2F.env',
            'simedia-2026-08-12-101500.sql',
            'berkas-lain.sql.gz',
        ];

        foreach ($nakal as $nama) {
            $this->actingAs($this->superadmin)
                ->get('/admin/cadangan/'.$nama.'/unduh')
                ->assertNotFound();

            $this->actingAs($this->superadmin)
                ->delete('/admin/cadangan/'.$nama)
                ->assertNotFound();
        }
    }

    public function test_berkas_yang_sah_bisa_diunduh_lalu_dihapus(): void
    {
        $nama = 'simedia-2026-08-12-101500.sql.gz';

        Storage::disk('local')->put('cadangan/'.$nama, gzencode('-- isi cadangan'));

        $this->actingAs($this->superadmin)
            ->get('/admin/cadangan/'.$nama.'/unduh')
            ->assertOk()
            ->assertDownload($nama);

        $this->actingAs($this->superadmin)
            ->delete('/admin/cadangan/'.$nama)
            ->assertRedirect();

        $this->assertFalse(Storage::disk('local')->exists('cadangan/'.$nama));
    }

    /** Berkas asing di folder yang sama tidak boleh muncul sebagai baris arsip. */
    public function test_daftar_hanya_memuat_berkas_berpola_benar(): void
    {
        Storage::disk('local')->put('cadangan/simedia-2026-08-12-101500.sql.gz', gzencode('-- isi'));
        Storage::disk('local')->put('cadangan/catatan.txt', 'bukan cadangan');

        $this->actingAs($this->superadmin)
            ->get('/admin/cadangan')
            ->assertInertia(fn ($halaman) => $halaman
                ->component('admin/Cadangan')
                ->has('berkas', 1)
                ->where('berkas.0.nama', 'simedia-2026-08-12-101500.sql.gz')
                ->where('total.jumlah', 1));
    }
}
