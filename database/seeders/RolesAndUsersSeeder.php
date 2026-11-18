<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolesAndUsersSeeder extends Seeder
{
    public function run(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('user', 'web');

        // Spec L10: exactly ONE admin — label@admin.com / REDACTED-LOCAL-DEV-PASSWORD.
        // Rename the legacy seeded admin in place so its Sanctum token
        // (used by the Elementor form) keeps working.
        $admin = User::where('email', 'label@admin.com')->first()
            ?? User::where('email', 'admin@sunsetgathering.com')->first();

        if ($admin === null) {
            $admin = new User([
                'name' => 'Admin',
                'email' => 'label@admin.com',
                'password' => 'REDACTED-LOCAL-DEV-PASSWORD',
            ]);
        } else {
            $admin->name = 'Admin';
            $admin->email = 'label@admin.com';
            $admin->password = 'REDACTED-LOCAL-DEV-PASSWORD';
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
