<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_requires_authentication(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_admin_can_sign_in(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'test-password',
        ]);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $user->assignRole('admin');

        $this->post(route('login.post'), [
            'email' => 'admin@example.com',
            'password' => 'test-password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_can_sign_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_public_registration_form_is_available_and_validates_required_fields(): void
    {
        $this->get('/register')->assertOk();
        $this->post(route('register.post'), [])->assertSessionHasErrors(['name', 'email', 'password', 'terms']);
    }
}
