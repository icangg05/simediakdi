<?php

namespace Tests\Feature;

use App\Enums\PeranPengguna;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Halaman ini satu-satunya jalan akun dibuat, registrasi mandiri tidak dibuka.
 * Aturan peran dan media_id ditegakkan constraint database; yang diuji di sini
 * bahwa admin mendapat pesan yang bisa dibaca, bukan galat SQL.
 */
class PenggunaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);
    }

    public function test_membuat_akun_media_wajib_menautkan_media(): void
    {
        $this->actingAs($this->admin)->post('/admin/pengguna', [
            'name' => 'PIC Media',
            'email' => 'pic@kp.test',
            'password' => 'rahasia-panjang',
            'peran' => 'media',
        ])->assertSessionHasErrors('media_id');

        $this->assertSame(1, User::count());
    }

    public function test_akun_media_tersimpan_beserta_tautannya(): void
    {
        $this->actingAs($this->admin)->post('/admin/pengguna', [
            'name' => 'PIC Media',
            'email' => 'pic@kp.test',
            'password' => 'rahasia-panjang',
            'peran' => 'media',
            'media_id' => $this->media->id,
        ])->assertSessionHasNoErrors();

        $pengguna = User::where('email', 'pic@kp.test')->first();

        $this->assertSame(PeranPengguna::Media, $pengguna->peran);
        $this->assertSame($this->media->id, $pengguna->media_id);
    }

    /**
     * Constraint database menolak superadmin yang punya media_id. Form harus
     * membuangnya sendiri, bukan meneruskannya lalu gagal dengan galat SQL.
     */
    public function test_media_id_dibuang_untuk_peran_selain_media(): void
    {
        $this->actingAs($this->admin)->post('/admin/pengguna', [
            'name' => 'Admin Baru',
            'email' => 'admin2@simedia.test',
            'password' => 'rahasia-panjang',
            'peran' => 'superadmin',
            'media_id' => $this->media->id,
        ])->assertSessionHasNoErrors();

        $this->assertNull(User::where('email', 'admin2@simedia.test')->first()->media_id);
    }

    public function test_kata_sandi_kosong_saat_menyunting_tidak_mengubahnya(): void
    {
        $pengguna = User::factory()->create(['password' => Hash::make('sandi-lama-sekali')]);

        $this->actingAs($this->admin)->put("/admin/pengguna/{$pengguna->id}", [
            'name' => 'Nama Baru',
            'username' => $pengguna->username,
            'email' => $pengguna->email,
            'password' => '',
            'peran' => 'superadmin',
        ])->assertSessionHasNoErrors();

        $pengguna->refresh();

        $this->assertSame('Nama Baru', $pengguna->name);
        $this->assertTrue(Hash::check('sandi-lama-sekali', $pengguna->password));
    }

    /** F-46: dinonaktifkan, bukan dihapus. */
    public function test_menonaktifkan_tidak_menghapus_akun(): void
    {
        $lain = User::factory()->create();

        $this->actingAs($this->admin)->delete("/admin/pengguna/{$lain->id}");

        $lain->refresh();

        $this->assertFalse($lain->aktif);
        $this->assertNull($lain->deleted_at);
    }

    public function test_tidak_bisa_menonaktifkan_akun_sendiri(): void
    {
        $this->actingAs($this->admin)
            ->delete("/admin/pengguna/{$this->admin->id}")
            ->assertSessionHas('galat');

        $this->assertTrue($this->admin->refresh()->aktif);
    }

    /**
     * Form ubah bisa menyetel `aktif` menjadi false, jadi ia jalur penonaktifan
     * kedua. Sebelum diperbaiki, penjagaan hanya ada di tombol nonaktifkan dan
     * admin bisa mengunci dirinya sendiri keluar lewat form.
     */
    public function test_form_ubah_tidak_bisa_menonaktifkan_akun_sendiri(): void
    {
        User::factory()->create();

        $this->actingAs($this->admin)->put("/admin/pengguna/{$this->admin->id}", [
            'name' => $this->admin->name,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'peran' => 'superadmin',
            'aktif' => false,
        ])->assertSessionHas('galat');

        $this->assertTrue($this->admin->refresh()->aktif);
    }

    /**
     * Menurunkan peran sendiri punya akibat yang sama dengan menonaktifkan
     * diri: kehilangan akses panel admin, tanpa jalan kembali lewat aplikasi.
     */
    public function test_tidak_bisa_menurunkan_peran_sendiri(): void
    {
        $media = Media::first();

        $this->actingAs($this->admin)->put("/admin/pengguna/{$this->admin->id}", [
            'name' => $this->admin->name,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'peran' => 'media',
            'media_id' => $media->id,
            'aktif' => true,
        ])->assertSessionHas('galat');

        $this->assertSame(PeranPengguna::Superadmin, $this->admin->refresh()->peran);
    }

    public function test_superadmin_lain_tetap_bisa_menurunkan_peran_seseorang(): void
    {
        $kedua = User::factory()->create();

        $this->actingAs($this->admin)->put("/admin/pengguna/{$kedua->id}", [
            'name' => $kedua->name,
            'username' => $kedua->username,
            'email' => $kedua->email,
            'peran' => 'walikota',
            'aktif' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame(PeranPengguna::Walikota, $kedua->refresh()->peran);
    }

    public function test_email_ganda_ditolak(): void
    {
        $this->actingAs($this->admin)->post('/admin/pengguna', [
            'name' => 'Kembar',
            'email' => $this->admin->email,
            'password' => 'rahasia-panjang',
            'peran' => 'superadmin',
        ])->assertSessionHasErrors('email');
    }

    /**
     * Username dibentuk sistem saat isiannya dikosongkan pada akun baru.
     *
     * Jalur inilah yang membuat 30 akun media bisa didaftarkan tanpa memikirkan
     * username satu per satu, dan ia harus tetap terbuka sesudah isian username
     * muncul di form. Kalau tertutup, form yang tadinya bisa diisi empat kolom
     * berubah menjadi lima tanpa alasan.
     */
    public function test_username_dibentuk_otomatis_saat_dikosongkan(): void
    {
        $this->actingAs($this->admin)->post('/admin/pengguna', [
            'name' => 'PIC Media',
            'username' => '',
            'email' => 'humas.setda@kp.test',
            'password' => 'rahasia-panjang',
            'peran' => 'media',
            'media_id' => $this->media->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame('humas.setda', User::where('email', 'humas.setda@kp.test')->first()->username);
    }

    public function test_username_bisa_diubah_lewat_form_ubah(): void
    {
        $pengguna = User::factory()->create();

        $this->actingAs($this->admin)->put("/admin/pengguna/{$pengguna->id}", [
            'name' => $pengguna->name,
            'username' => 'humas.kendari',
            'email' => $pengguna->email,
            'peran' => 'superadmin',
            'aktif' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame('humas.kendari', $pengguna->refresh()->username);
    }

    /**
     * Username adalah kredensial masuk, jadi mengosongkannya pada akun yang
     * sudah ada bukan "biarkan apa adanya" melainkan "cabut cara masuknya".
     * Berbeda dari kata sandi, yang memang dirancang boleh dikosongkan.
     */
    public function test_username_kosong_saat_menyunting_ditolak(): void
    {
        $pengguna = User::factory()->create();
        $lama = $pengguna->username;

        $this->actingAs($this->admin)->put("/admin/pengguna/{$pengguna->id}", [
            'name' => $pengguna->name,
            'username' => '',
            'email' => $pengguna->email,
            'peran' => 'superadmin',
            'aktif' => true,
        ])->assertSessionHasErrors('username');

        $this->assertSame($lama, $pengguna->refresh()->username);
    }

    /** Huruf besar dan spasi dirapikan sebelum diperiksa, bukan ditolak mentah. */
    public function test_username_dirapikan_menjadi_huruf_kecil(): void
    {
        $pengguna = User::factory()->create();

        $this->actingAs($this->admin)->put("/admin/pengguna/{$pengguna->id}", [
            'name' => $pengguna->name,
            'username' => '  Humas.Kendari  ',
            'email' => $pengguna->email,
            'peran' => 'superadmin',
            'aktif' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame('humas.kendari', $pengguna->refresh()->username);
    }

    public function test_username_berbentuk_salah_ditolak(): void
    {
        $pengguna = User::factory()->create();

        $this->actingAs($this->admin)->put("/admin/pengguna/{$pengguna->id}", [
            'name' => $pengguna->name,
            'username' => 'humas kendari',
            'email' => $pengguna->email,
            'peran' => 'superadmin',
            'aktif' => true,
        ])->assertSessionHasErrors('username');
    }

    /**
     * Uniknya diperiksa termasuk terhadap akun yang sudah dihapus lunak.
     *
     * Indeks unik di database tidak mengenal soft delete. Validasi yang
     * melewatkan baris terhapus akan lolos di sini lalu gagal sebagai galat SQL
     * mentah di layar admin, dan itu persis jenis galat yang tidak bisa
     * ditindaklanjuti siapa pun yang membacanya.
     */
    public function test_username_ganda_ditolak_termasuk_terhadap_akun_terhapus(): void
    {
        $terhapus = User::factory()->create(['username' => 'humas.lama']);
        $terhapus->delete();

        $pengguna = User::factory()->create();

        $this->actingAs($this->admin)->put("/admin/pengguna/{$pengguna->id}", [
            'name' => $pengguna->name,
            'username' => 'humas.lama',
            'email' => $pengguna->email,
            'peran' => 'superadmin',
            'aktif' => true,
        ])->assertSessionHasErrors('username');
    }
}
