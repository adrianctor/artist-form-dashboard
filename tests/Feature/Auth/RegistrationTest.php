<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('user', 'web');
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->skipUnlessFortifyHas(Features::registration());

        $this->get(route('register'))->assertOk();
    }

    public function test_new_users_can_register_with_the_user_role(): void
    {
        $this->skipUnlessFortifyHas(Features::registration());

        $response = $this->post(route('register.store'), [
            'name' => 'New Person',
            'email' => 'newperson@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertAuthenticated();

        $user = User::where('email', 'newperson@example.com')->firstOrFail();
        $this->assertSame('New Person', $user->name);
        $this->assertTrue($user->hasRole('user'));
        $this->assertFalse($user->hasRole('admin'));

        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_login_page_links_to_registration(): void
    {
        $this->skipUnlessFortifyHas(Features::registration());

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/Login'));
    }
}
