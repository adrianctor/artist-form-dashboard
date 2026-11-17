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

        $admin = User::updateOrCreate(
            ['email' => 'admin@sunsetgathering.com'],
            [
                'name' => 'Admin',
                'password' => 'dashboard123',
                'email_verified_at' => now(),
                'is_active' => true,
            ],
        );

        $admin->syncRoles(['admin']);
    }
}
