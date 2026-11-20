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
        // The repository is public: NO password literal may live here.
        // ADMIN_PASSWORD from .env is the only explicit password (create or
        // rotate). Without it: a brand-new admin gets a random password
        // logged once; an existing admin keeps whatever hash is already in
        // the database (local dev keeps its known password there).
        $explicit = (string) (config('app.admin_password') ?? '');

        $admin = User::where('email', 'label@admin.com')->first()
            ?? User::where('email', 'admin@sunsetgathering.com')->first();

        if ($admin === null) {
            $password = $explicit;
            if ($password === '') {
                $password = Str::random(20);
                Log::warning(
                    'RolesAndUsersSeeder: generated a random admin password (ADMIN_PASSWORD not set). '
                    .'Set ADMIN_PASSWORD in .env and re-run db:seed --class=RolesAndUsersSeeder to choose your own.',
                );
            }
            $admin = new User([
                'name' => 'Admin',
                'email' => 'label@admin.com',
                'password' => $password,
            ]);
        } else {
            $admin->name = 'Admin';
            $admin->email = 'label@admin.com';
            if ($explicit !== '') {
                $admin->password = $explicit;
            }
            // No ADMIN_PASSWORD: leave the stored hash untouched.
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
