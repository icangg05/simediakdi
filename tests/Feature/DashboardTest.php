<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    /** /dashboard hanya pengalih; berandanya ditentukan peran. */
    public function test_dashboard_mengalihkan_ke_beranda_peran()
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertRedirect('/admin');

        $this->actingAs(User::factory()->walikota()->create())
            ->get('/dashboard')
            ->assertRedirect('/eksekutif');
    }
}
