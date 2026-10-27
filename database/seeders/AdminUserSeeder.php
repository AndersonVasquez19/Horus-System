<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@horusgroup.com'],
            [
                'name' => 'Administrador Horus',
                'password' => 'Horus2026*',
            ],
        );
    }
}
