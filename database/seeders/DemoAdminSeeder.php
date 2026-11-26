<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DemoAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'achozarate@gmail.com';

        if (User::where('email', $email)->exists()) {
            $this->command->warn('Ese correo ya está registrado. La cuenta existente se conservó.');

            return;
        }

        User::create([
            'name' => 'Administrador',
            'email' => $email,
            'password' => '$2y$12$z2hBj9lqu2sMH7opdnGsBeN989JSR68kmFOdXEolRcrgS8n/MdeZ6',
            'role' => 'admin',
            'active' => true,
            'must_change_password' => true,
        ]);

        $this->command->info('Administrador creado. Cambia la contraseña al iniciar sesión.');
    }
}
