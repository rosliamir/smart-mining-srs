<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            LegacyImportSeeder::class,
        ]);

        // Admin login for the Filament panel
        $admin = User::firstOrCreate(
            ['email' => 'admin@kssb.local'],
            ['name' => 'Administrator', 'username' => 'admin', 'password' => Hash::make('password'), 'confirmed' => true]
        );
        $admin->assignRole('super_admin');
    }
}
