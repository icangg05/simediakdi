<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get('/login');

        $this->assertSame('id', app()->getLocale());
        $response
            ->assertStatus(200)
            ->assertSee('<html lang="id">', false);
    }

    public function test_pesan_validasi_login_menggunakan_bahasa_indonesia(): void
    {
        $this->from('/login')
            ->post('/login', [])
            ->assertRedirect('/login')
            ->assertSessionHasErrors([
                'username' => 'Kolom username wajib diisi.',
                'password' => 'Kolom kata sandi wajib diisi.',
            ]);
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_username_dibentuk_dari_nama_email()
    {
        $user = User::factory()->create(['email' => 'humas.setda@kendarikota.go.id']);

        $this->assertSame('humas.setda', $user->username);
    }

    public function test_username_yang_bentrok_memakai_nama_domain()
    {
        User::factory()->create(['email' => 'portal@britakita.net']);
        $kedua = User::factory()->create(['email' => 'portal@telisik.id']);

        $this->assertSame('portal.telisik', $kedua->username);
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_login_terakhir_dicatat_saat_pengguna_masuk(): void
    {
        $user = User::factory()->create(['login_terakhir_at' => null]);

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();

        $user->refresh();

        $this->assertNotNull($user->login_terakhir_at);
        $this->assertNotNull($user->ip_login_terakhir);
    }
}
