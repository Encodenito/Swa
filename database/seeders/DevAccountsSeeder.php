<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Known-password accounts for local development only. Never runs in production.
 */
class DevAccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DevAccountsSeeder only runs in local or testing.');
        }

        $accounts = [
            ['admin@swa.test', 'Dev Admin', Role::Admin],
            ['staff@swa.test', 'Dev Staff', Role::Staff],
            ['customer@swa.test', 'Dev Customer', Role::Customer],
        ];

        foreach ($accounts as [$email, $name, $role]) {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->forceFill([
                'name' => $name,
                'phone' => '09170000000',
                'password' => 'password',
                'role' => $role,
                'email_verified_at' => now(),
            ])->save();
        }
    }
}
