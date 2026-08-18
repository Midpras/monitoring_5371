<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $email = trim((string) env('ADMIN_EMAIL'));
        $password = (string) env('ADMIN_PASSWORD');

        if ($email === '' || $password === '') {
            return;
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => env('ADMIN_NAME', 'Administrator'), 'password' => Hash::make($password), 'role' => 'admin'],
        );

        if (! $user->isAdmin()) {
            $user->forceFill(['role' => 'admin'])->save();
        }
    }
}
