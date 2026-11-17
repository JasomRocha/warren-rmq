<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class UserCommand extends Command
{
    protected $signature = 'warren:user
        {email? : E-mail de login}
        {--name= : Nome de exibição (padrão: a parte antes do @)}
        {--password= : Senha (prefira digitar quando pedir: argumentos aparecem na lista de processos)}
        {--from-env : Cria o usuário de WARREN_ADMIN_EMAIL / WARREN_ADMIN_PASSWORD se ainda não existir}';

    protected $description = 'Cria um usuário ou redefine a senha de um existente';

    public function handle(): int
    {
        if ($this->option('from-env')) {
            return $this->fromEnvironment();
        }

        $email = $this->argument('email') ?: $this->ask(trans('warren.user.ask_email'));
        $password = $this->option('password') ?: $this->askForPassword();

        return $this->save((string) $email, (string) $password, $this->option('name'), reset: true);
    }

    private function fromEnvironment(): int
    {
        $email = config('warren.auth.admin_email');
        $password = config('warren.auth.admin_password');

        if (! $email || ! $password) {
            if (config('warren.auth.enabled') && ! User::query()->exists()) {
                $this->warn(trans('warren.user.no_users'));
            }

            return self::SUCCESS;
        }
        if (User::where('email', $email)->exists()) {
            return self::SUCCESS; // never overwrite a password that was changed since
        }

        return $this->save($email, $password, null, reset: false);
    }

    private function askForPassword(): string
    {
        $password = (string) $this->secret(trans('warren.user.ask_password'));
        if ($password !== (string) $this->secret(trans('warren.user.ask_repeat'))) {
            $this->error(trans('warren.user.mismatch'));

            return '';
        }

        return $password;
    }

    private function save(string $email, string $password, ?string $name, bool $reset): int
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error(trans('warren.user.invalid_email'));

            return self::FAILURE;
        }
        if (mb_strlen($password) < 8) {
            $this->error(trans('warren.user.short_password'));

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        if ($user->exists && ! $reset) {
            return self::SUCCESS;
        }
        $created = ! $user->exists;
        $user->name = $name ?: ($user->name ?: Str::before($email, '@'));
        $user->password = $password; // hashed by the model cast
        $user->save();

        $this->info(trans($created ? 'warren.user.created' : 'warren.user.updated', ['email' => $email]));

        return self::SUCCESS;
    }
}
