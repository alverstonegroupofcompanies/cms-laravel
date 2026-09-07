<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'status',
        'must_reset_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'has_password',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'must_reset_password' => 'boolean',
        ];
    }

    public function getHasPasswordAttribute(): bool
    {
        return ! empty($this->attributes['password'] ?? null);
    }

    public function patient(): HasOne
    {
        return $this->hasOne(Patient::class);
    }

    public function doctor(): HasOne
    {
        return $this->hasOne(Doctor::class);
    }

    public function hasRole(UserRole|string ...$roles): bool
    {
        $current = $this->role instanceof UserRole
            ? $this->role->value
            : strtolower(trim((string) ($this->attributes['role'] ?? $this->role ?? '')));

        foreach ($roles as $role) {
            $value = $role instanceof UserRole ? $role->value : strtolower(trim((string) $role));
            if ($current !== '' && $current === $value) {
                return true;
            }
        }

        return false;
    }

    /** Receptionist and admin — full clinic staff (patient/doctor management). */
    public function isClinicStaff(): bool
    {
        return $this->hasRole(
            UserRole::Admin,
            UserRole::Receptionist,
            'admin',
            'receptionist',
            'staff'
        );
    }
}
