<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'swa:create-admin {email} {--name=Administrator}';

    protected $description = 'Create an admin account (or promote an existing one), prompting for the password';

    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();

        $password = null;

        if (! $user) {
            $password = (string) $this->secret('Password (min 8 characters)');

            $validator = Validator::make(['email' => $email, 'password' => $password], [
                'email' => ['required', 'email'],
                'password' => ['required', 'min:8'],
            ]);

            if ($validator->fails()) {
                $this->error($validator->errors()->first());

                return self::FAILURE;
            }

            $user = new User;
        }

        $user->forceFill([
            'name' => $user->name ?? $this->option('name'),
            'email' => $email,
            'role' => Role::Admin,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'deactivated_at' => null,
        ]);

        if ($password !== null) {
            $user->forceFill(['password' => $password]);
        }

        $user->save();

        $this->info("{$email} is now an admin.");

        return self::SUCCESS;
    }
}
