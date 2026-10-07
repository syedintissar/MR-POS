<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\PermissionCatalogue;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = ['name', 'username', 'email', 'phone', 'password', 'is_active', 'must_change_password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(PermissionCatalogue::ADMIN);
    }

    /** Active users who hold the Admin role (used by the "last admin" protection). */
    public function scopeActiveAdmins($query)
    {
        return $query->where('is_active', true)->role(PermissionCatalogue::ADMIN);
    }

    public function roleName(): string
    {
        return (string) $this->roles->pluck('name')->first();
    }
}
