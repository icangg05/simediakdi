<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_tamu_di_halaman_akar_diarahkan_ke_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_pengguna_yang_sudah_login_diarahkan_ke_beranda_perannya(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect('/admin');
    }
}
