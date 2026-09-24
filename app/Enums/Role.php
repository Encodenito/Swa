<?php

namespace App\Enums;

enum Role: string
{
    case Customer = 'customer';
    case Staff = 'staff';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Staff => 'Staff',
            self::Admin => 'Admin',
        };
    }

    /**
     * Where this role lands after login.
     */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Customer => 'account.home',
            self::Staff => 'staff.home',
            self::Admin => 'admin.home',
        };
    }
}
