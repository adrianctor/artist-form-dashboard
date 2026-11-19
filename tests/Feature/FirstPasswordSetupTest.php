<?php

namespace Tests\Feature;

use App\Mail\SetPasswordSetupLink;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Spec: odd/tasks/first-password-setup.md, T1-T3, S1-S3.
 *
 * A user who signed up socially (password_set_at = NULL) sets the first
 * password through an emailed signed link — no "current password" asked.
 * Users who already chose a password can only change it the old way.
 */
class FirstPasswordSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndUsersSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function socialUser(): User
    {
        $user = User::factory()->create(['password_set_at' => null]);
        $user->assignRole('user');

        return $user;
    }

    private function setupUrl(User $user, ?\DateTimeInterface $expiresAt = null): string
    {
        return URL::temporarySignedRoute(
            'password.setup',
            $expiresAt ?? now()->addMinutes(60),
            ['user' => $user->id],
        );
    }

    // --- S1/S3: security page ------------------------------------------

    public function test_security_page_offers_first_time_setup_without_password_confirmation(): void
    {
        // No password => no RequirePassword confirmation screen either.
        $user = $this->socialUser();

        $this->actingAs($user)
            ->get(route('security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Security')
                ->where('mustSetFirstPassword', true)
            );
    }

    public function test_security_page_keeps_the_update_form_for_users_with_a_password(): void
    {
        $user = User::factory()->create(); // password_set_at = now()
        $user->assignRole('user');

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mustSetFirstPassword', false)
            );
    }

    // --- S2: the emailed signed link -----------------------------------

    public function test_setup_link_is_emailed_to_the_user_with_a_signed_url(): void
    {
        Mail::fake();
        $user = $this->socialUser();

        $this->actingAs($user)
            ->post(route('password.setup.link'))
            ->assertRedirect();

        Mail::assertSent(SetPasswordSetupLink::class, function (SetPasswordSetupLink $mail) use ($user) {
            parse_str(parse_url($mail->setupUrl, PHP_URL_QUERY) ?? '', $query);

            return $mail->hasTo($user->email)
                && ($query['user'] ?? null) === (string) $user->id
                && isset($query['signature'], $query['expires']);
        });
    }

    public function test_setup_link_page_opens_without_authentication(): void
    {
        $user = $this->socialUser();

        $this->get($this->setupUrl($user))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/SetPassword')
                ->where('email', $user->email)
            );
    }

    public function test_tampered_setup_link_is_rejected(): void
    {
        $user = $this->socialUser();

        $this->get('/set-password?user='.$user->id.'&expires='.now()->addHour()->getTimestamp().'&signature=deadbeef')
            ->assertForbidden();
    }

    public function test_expired_setup_link_is_rejected(): void
    {
        $user = $this->socialUser();

        $this->get($this->setupUrl($user, now()->subMinute()))
            ->assertForbidden();
    }

    public function test_setup_link_stops_working_once_the_password_exists(): void
    {
        // Spec S2: a stolen/old link must NEVER change an existing password —
        // that always requires the current one.
        $user = User::factory()->create(); // password_set_at = now()
        $url = $this->setupUrl($user);

        $this->get($url)->assertStatus(410);
        $this->post($url, [
            'password' => 'Test-Fixture-Password-1',
            'password_confirmation' => 'Test-Fixture-Password-1',
        ])->assertStatus(410);

        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check('password', $user->fresh()->password),
            'The existing password must be untouched.',
        );
    }

    public function test_completing_the_setup_sets_the_password(): void
    {
        $user = $this->socialUser();
        $url = $this->setupUrl($user);

        $this->post($url, [
            'password' => 'Test-Fixture-Password-2',
            'password_confirmation' => 'Test-Fixture-Password-2',
        ])->assertRedirect();

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->password_set_at);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Test-Fixture-Password-2', $fresh->password));
        // The first-time setup flow is over: security shows the update form now.
        $this->assertFalse($fresh->password_set_at === null);
    }

    public function test_completing_the_setup_requires_a_strong_confirmed_password(): void
    {
        $user = $this->socialUser();
        $url = $this->setupUrl($user);

        $this->from('/set-password')
            ->post($url, [
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertRedirect('/set-password')
            ->assertSessionHasErrors(['password']);

        $this->assertNull($user->fresh()->password_set_at);
    }

    public function test_sending_the_setup_link_is_throttled(): void
    {
        $user = $this->socialUser();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->post(route('password.setup.link'))->assertRedirect();
        }

        $this->actingAs($user)
            ->post(route('password.setup.link'))
            ->assertStatus(429);
    }

    // --- S2: password chosen markers -----------------------------------

    public function test_password_reset_marks_the_password_as_chosen(): void
    {
        $user = $this->socialUser();
        $this->assertNull($user->password_set_at);

        event(new PasswordReset($user));

        $this->assertNotNull($user->fresh()->password_set_at);
    }

    public function test_update_password_marks_the_password_as_chosen(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->forceFill(['password_set_at' => null])->save();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'Test-Fixture-Password-3',
                'password_confirmation' => 'Test-Fixture-Password-3',
            ])
            ->assertRedirect();

        $this->assertNotNull($user->fresh()->password_set_at);
    }
}
