<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class RolesAndUsersSeeder extends Seeder
{
    public function run(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('user', 'web');

        // Spec L10: exactly ONE admin — label@admin.com. Rename the legacy
        // seeded admin in place so its Sanctum token keeps working.
        //
        // The repository is public, so production must never use a known
        // password: ADMIN_PASSWORD from .env wins; unset in production →
        // random password logged once; local/dev keeps REDACTED-LOCAL-DEV-PASSWORD.
        $password = (string) (config('app.admin_password') ?? '');
        if ($password === '') {
            if (app()->environment('production')) {
                $password = Str::random(20);
                Log::warning(
                    'RolesAndUsersSeeder: generated a random admin password for production. '
                    .'Set ADMIN_PASSWORD in .env and re-run db:seed --class=RolesAndUsersSeeder to choose your own.',
                );
            } else {
                $password = 'REDACTED-LOCAL-DEV-PASSWORD';
            }
        }

        $admin = User::where('email', 'label@admin.com')->first()
            ?? User::where('email', 'admin@sunsetgathering.com')->first();

        if ($admin === null) {
            $admin = new User([
                'name' => 'Admin',
                'email' => 'label@admin.com',
                'password' => $password,
            ]);
        } else {
            $admin->name = 'Admin';
            $admin->email = 'label@admin.com';
            $admin->password = $password;
        }

        $admin->email_verified_at = $admin->email_verified_at ?? now();
        $admin->is_active = true;
        $admin->save();
        $admin->syncRoles(['admin']);

        // Enforce the single-admin invariant: demote anyone else.
        User::where('id', '!=', $admin->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->get()
            ->each(fn (User $user) => $user->syncRoles(['user']));
    }
}
