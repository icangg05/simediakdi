<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CadanganDatabase;
use App\Support\Waktu;
use Carbon\CarbonImmutable;
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
            'simak-2026-08-12-101500.sql',
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
        $nama = 'simak-2026-08-12-101500.sql.gz';

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

    /**
     * Satu berkas per minggu, Senin sampai Minggu WITA.
     *
     * Ini logika yang paling mudah rusak diam-diam di seluruh fitur ini, dan
     * kerusakannya baru terlihat berminggu-minggu kemudian saat ada yang
     * mencari cadangan yang ternyata sudah terhapus. Batas mingguannya diuji
     * dari dua sisi sekaligus: berkas Minggu malam milik minggu sebelumnya
     * harus selamat, berkas Senin milik minggu yang sama harus dibuang.
     */
    public function test_cadangan_baru_menimpa_cadangan_lain_di_minggu_yang_sama(): void
    {
        $mingguLalu = 'simak-2026-08-16-235900.sql.gz';   // Minggu, minggu sebelumnya
        $senin = 'simak-2026-08-17-030000.sql.gz';        // Senin, hasil jadwal
        $rabu = 'simak-2026-08-19-101500.sql.gz';         // Rabu, hasil tombol manual

        foreach ([$mingguLalu, $senin, $rabu] as $nama) {
            Storage::disk('local')->put('cadangan/'.$nama, gzencode('-- isi'));
        }

        $dibuang = app(CadanganDatabase::class)->buangSatuMinggu(
            CarbonImmutable::create(2026, 8, 19, 10, 15, 0, Waktu::ZONA),
            $rabu,
        );

        $this->assertSame(1, $dibuang);
        $this->assertTrue(Storage::disk('local')->exists('cadangan/'.$rabu));
        $this->assertTrue(Storage::disk('local')->exists('cadangan/'.$mingguLalu));
        $this->assertFalse(Storage::disk('local')->exists('cadangan/'.$senin));
    }

    /** Berkas asing di folder yang sama tidak boleh muncul sebagai baris arsip. */
    public function test_daftar_hanya_memuat_berkas_berpola_benar(): void
    {
        Storage::disk('local')->put('cadangan/simak-2026-08-12-101500.sql.gz', gzencode('-- isi'));
        Storage::disk('local')->put('cadangan/catatan.txt', 'bukan cadangan');

        $this->actingAs($this->superadmin)
            ->get('/admin/cadangan')
            ->assertInertia(fn ($halaman) => $halaman
                ->component('admin/Cadangan')
                ->has('berkas', 1)
                ->where('berkas.0.nama', 'simak-2026-08-12-101500.sql.gz')
                ->where('total.jumlah', 1));
    }
}
