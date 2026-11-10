<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateAdministrator extends Command
{
    protected $signature = 'kardex:admin {email} {--name=Administrador}';

    protected $description = 'Crea una cuenta administradora con contraseña temporal aleatoria';

    public function handle(): int
    {
        $email = $this->argument('email');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Correo inválido.');

            return self::FAILURE;
        }
        if (User::where('email', $email)->exists()) {
            $this->error('Ya existe una cuenta con este correo.');

            return self::FAILURE;
        }
        $password = Str::random(16).'9a';
        User::create(['name' => $this->option('name'), 'email' => $email, 'password' => $password, 'role' => 'admin', 'active' => true, 'must_change_password' => true]);
        $this->info('Administrador creado: '.$email);
        $this->line('Contraseña temporal: '.$password);
        $this->warn('Debe cambiarla en su primer ingreso.');

        return self::SUCCESS;
    }
}
