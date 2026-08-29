<?php

namespace Invue\Core\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * The Invue equivalent of Filament's make:filament-user — simpler, on
 * purpose: Invue has no per-panel access gate to satisfy (no
 * canAccessPanel() equivalent). Every generated Panel is guarded by the
 * plain `auth` middleware alone, so any user created here can already log
 * into every panel in the app — this command exists purely to get a real
 * row into the users table without opening tinker.
 */
class MakeUserCommand extends Command
{
    protected $signature = 'make:invue-user';

    protected $description = 'Create a new user that can log into any Invue panel';

    public function handle(): int
    {
        if (! class_exists(User::class)) {
            $this->components->error("App\\Models\\User doesn't exist — Invue expects the default Laravel User model.");

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('make:invue-user prompts for name/email/password and has no --no-interaction path — create the user directly (e.g. via tinker or a seeder) instead.');

            return self::FAILURE;
        }

        $name = $this->components->ask('Name');
        $email = $this->askForEmail();
        $password = $this->askForPassword();

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->components->info("User created: {$user->email}");
        $this->line('');
        $this->line("Any authenticated user can log into any Invue panel by default — there's no separate canAccessPanel()-style gate to satisfy, just the auth middleware every generated Panel already has.");

        return self::SUCCESS;
    }

    protected function askForEmail(): string
    {
        while (true) {
            $email = $this->components->ask('Email address');

            $validator = Validator::make(['email' => $email], [
                'email' => ['required', 'email'],
            ]);

            if ($validator->fails()) {
                $this->components->error($validator->errors()->first('email'));

                continue;
            }

            if (User::query()->where('email', $email)->exists()) {
                $this->components->error("A user with email [{$email}] already exists.");

                continue;
            }

            return $email;
        }
    }

    protected function askForPassword(): string
    {
        while (true) {
            $password = $this->components->secret('Password');
            $confirmation = $this->components->secret('Confirm password');

            if ($password !== $confirmation) {
                $this->components->error('The passwords did not match. Try again.');

                continue;
            }

            $validator = Validator::make(['password' => $password], [
                'password' => ['required', Password::default()],
            ]);

            if ($validator->fails()) {
                $this->components->error($validator->errors()->first('password'));

                continue;
            }

            return $password;
        }
    }
}
