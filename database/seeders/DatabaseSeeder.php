<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AccountSeeder::class,
            RoleSeeder::class,
            PermissionSeeder::class,
            AreaSeeder::class,
            UserSeeder::class,
        ]);
    }
}
