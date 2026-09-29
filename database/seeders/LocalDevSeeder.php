<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local development only: `php artisan db:seed --class=LocalDevSeeder`.
 * Creates admin@skripsi.test with the password from DEV_ADMIN_PASSWORD in .env.
 * Production admins are created with `php artisan app:create-admin`.
 */
class LocalDevSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local') || ! env('DEV_ADMIN_PASSWORD')) {
            $this->command?->warn('Skipped: requires APP_ENV=local and DEV_ADMIN_PASSWORD.');

            return;
        }

        User::updateOrCreate(['email' => 'admin@skripsi.test'], [
            'name' => 'Admin Lokal',
            'password' => env('DEV_ADMIN_PASSWORD'),
            'role' => 'superadmin',
            'is_active' => true,
        ]);
    }
}
