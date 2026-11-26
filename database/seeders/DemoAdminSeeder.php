<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DemoAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'achozarate@gmail.com';

        if (User::where('email', $email)->exists()) {
            $this->command->warn('Ese correo ya está registrado. La cuenta existente se conservó.');

            return;
        }

        $password = $this->command->secret('Contraseña temporal del administrador');
        if (! is_string($password) || strlen($password) < 8) {
            throw new RuntimeException('La contraseña temporal debe tener al menos 8 caracteres.');
        }

        User::create([
            'name' => 'Administrador',
            'email' => $email,
            'password' => $password,
            'role' => 'admin',
            'active' => true,
            'must_change_password' => true,
        ]);

        $this->command->info('Administrador creado. Cambia la contraseña al iniciar sesión.');
    }
}
