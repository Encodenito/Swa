<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\StateConflictException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Admin account management. Every change is audit-logged (spec K.13), and the shop can never
 * lock itself out: nobody changes their own role or deactivates themselves, and the last active
 * admin stays an admin.
 */
class AccountService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function createStaff(string $name, string $email, ?string $phone, string $password, Role $role, User $admin): User
    {
        return DB::transaction(function () use ($name, $email, $phone, $password, $role, $admin) {
            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => strtolower($email),
                'phone' => $phone,
                'password' => $password,
                'role' => $role,
                'email_verified_at' => now(),
                // The admin shared this password by hand: it must be replaced on first login.
                'must_change_password' => true,
            ])->save();

            $this->audit->record('user.created', $user, null, ['role' => $role->value, 'email' => $user->email], $admin);

            return $user;
        });
    }

    /**
     * @throws StateConflictException
     */
    public function changeRole(User $user, Role $role, string $reason, User $admin): void
    {
        DB::transaction(function () use ($user, $role, $reason, $admin) {
            $locked = $this->lockAndGuard($user, $admin);

            if ($locked->role === Role::Admin && $role !== Role::Admin) {
                $this->assertAnotherActiveAdmin($locked);
            }

            $before = $locked->role->value;
            $locked->forceFill(['role' => $role])->save();
            $this->audit->record('user.role_changed', $locked, ['role' => $before], ['role' => $role->value, 'reason' => $reason], $admin);
        });

        $user->refresh();
    }

    /**
     * @throws StateConflictException
     */
    public function setActive(User $user, bool $active, string $reason, User $admin): void
    {
        DB::transaction(function () use ($user, $active, $reason, $admin) {
            $locked = $this->lockAndGuard($user, $admin);

            if (! $active && $locked->role === Role::Admin) {
                $this->assertAnotherActiveAdmin($locked);
            }

            $locked->forceFill(['deactivated_at' => $active ? null : now()])->save();

            // Signed-in sessions end on the next request (EnsureAccountIsActive); remember-me tokens are rotated now.
            if (! $active) {
                $locked->forceFill(['remember_token' => null])->save();
            }

            $this->audit->record($active ? 'user.reactivated' : 'user.deactivated', $locked, null, ['reason' => $reason], $admin);
        });

        $user->refresh();
    }

    private function lockAndGuard(User $user, User $admin): User
    {
        if ($user->is($admin)) {
            throw new StateConflictException('You cannot change your own role or deactivate your own account.');
        }

        // Lock every admin row so two admins cannot demote each other at the same moment.
        User::query()->where('role', Role::Admin)->orderBy('id')->lockForUpdate()->get();

        return User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
    }

    private function assertAnotherActiveAdmin(User $user): void
    {
        $others = User::query()
            ->where('role', Role::Admin)
            ->whereNull('deactivated_at')
            ->whereKeyNot($user->id)
            ->count();

        if ($others === 0) {
            throw new StateConflictException('The shop needs at least one active admin.');
        }
    }
}
