<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Mirrors the column default so a freshly created user has a role before it is reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'customer',
    ];

    /**
     * The attributes that are mass assignable. `role` is deliberately absent:
     * it only changes through explicit admin actions.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'deactivated_at' => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function isCustomer(): bool
    {
        return $this->role === Role::Customer;
    }

    public function isStaff(): bool
    {
        return in_array($this->role, [Role::Staff, Role::Admin], true);
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }
}
