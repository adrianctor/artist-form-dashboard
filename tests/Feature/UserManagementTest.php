<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'admin', 'guard_name' => 'web']);
        Role::create(['name' => 'user', 'guard_name' => 'web']);
    }

    public function test_guests_are_redirected_and_non_admin_users_are_forbidden(): void
    {
        $this->get('/users')->assertRedirect('/login');

        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)
            ->get('/users')
            ->assertForbidden();
    }

    public function test_user_role_can_view_self_but_cannot_manage_users(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $user->assignRole('user');

        $this->assertTrue($user->can('view', $user));
        $this->assertFalse($user->can('view', $other));
        $this->assertFalse($user->can('viewAny', User::class));
        $this->assertFalse($user->can('create', User::class));
        $this->assertFalse($user->can('update', $other));
        $this->assertFalse($user->can('delete', $other));
    }

    public function test_admin_can_view_the_user_management_pages(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->get('/users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('users/Index'));

        $this->get('/users/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('users/Create'));

        $this->get("/users/{$user->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('users/Edit'));
    }

    public function test_admin_can_create_a_user_with_the_user_role(): void
    {
        $this->actingAs($this->admin())
            ->post('/users', [
                'name' => 'New User',
                'email' => 'new@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertRedirect('/users')
            ->assertSessionHas('success', 'User created.');

        $user = User::where('email', 'new@example.com')->firstOrFail();

        $this->assertSame('New User', $user->name);
        $this->assertTrue($user->hasRole('user'));

        // Admin-created accounts skip email verification (S1) — the
        // email_verified_at attribute used to be silently dropped by
        // mass assignment, bouncing these users to /email/verify.
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_admin_can_update_a_user(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['email' => 'old@example.com']);
        $user->assignRole('user');

        $this->actingAs($admin)
            ->put("/users/{$user->id}", [
                'name' => 'Updated User',
                'email' => 'updated@example.com',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect('/users')
            ->assertSessionHas('success', 'User updated.');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated User',
            'email' => 'updated@example.com',
        ]);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_admin_can_delete_another_user(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($admin)
            ->delete("/users/{$user->id}")
            ->assertRedirect('/users')
            ->assertSessionHas('success', 'User deleted.');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->delete("/users/{$admin->id}")
            ->assertRedirect()
            ->assertSessionHas('error', 'You cannot delete your own account.');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_duplicate_email_is_rejected_without_creating_a_user(): void
    {
        $admin = $this->admin();
        User::factory()->create(['email' => 'taken@example.com']);
        $usersBefore = User::count();

        $this->actingAs($admin)
            ->postJson('/users', [
                'name' => 'Duplicate User',
                'email' => 'taken@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame($usersBefore, User::count());
        $this->assertDatabaseMissing('users', ['name' => 'Duplicate User']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_admin_can_toggle_user_active_status(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->put(route('users.update', $target), ['is_active' => false])
            ->assertRedirect(route('users.index'));

        $this->assertFalse($target->fresh()->is_active);

        $this->actingAs($admin)
            ->put(route('users.update', $target), ['is_active' => true])
            ->assertRedirect(route('users.index'));

        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_role_cannot_be_escalated_through_user_management(): void
    {
        // Spec L10: exactly one admin (seeded). The management UI can never
        // grant or change roles — a role payload is simply ignored.
        $admin = $this->admin();
        $target = User::factory()->create();
        $target->assignRole('user');

        $this->actingAs($admin)
            ->put(route('users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'role' => 'admin',
            ])
            ->assertRedirect(route('users.index'));

        $fresh = $target->fresh();
        $this->assertTrue($fresh->hasRole('user'));
        $this->assertFalse($fresh->hasRole('admin'));
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'password' => 'password123',
            'is_active' => false,
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->put(route('users.update', $admin), ['is_active' => false])
            ->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->is_active);
    }
}
