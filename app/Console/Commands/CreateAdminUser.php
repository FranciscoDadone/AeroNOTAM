<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The only way into /admin: there is no public registration, and `is_admin` is
 * not mass-assignable, so an account has to be made here.
 *
 * Also promotes and re-passwords an existing account, which is what makes it
 * safe to re-run against a deployed database.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'admin:create
                            {email : Dirección de correo con la que va a entrar}
                            {--name= : Nombre a mostrar (por defecto, la parte local del correo)}
                            {--password= : Contraseña; si se omite, se pide por teclado}';

    protected $description = 'Crea o actualiza una cuenta con acceso al panel /admin';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("«{$email}» no es una dirección de correo válida.");

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?? $this->secret('Contraseña'));

        if (strlen($password) < 8) {
            $this->error('La contraseña necesita al menos 8 caracteres.');

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $existed = $user->exists;

        $user->forceFill([
            'name' => (string) ($this->option('name') ?: $user->name ?: Str::before($email, '@')),
            'password' => Hash::make($password),
            'is_admin' => true,
        ])->save();

        $this->info($existed
            ? "Cuenta actualizada: {$email} ya tiene acceso al panel."
            : "Cuenta creada: {$email} puede entrar en /admin.");

        return self::SUCCESS;
    }
}
