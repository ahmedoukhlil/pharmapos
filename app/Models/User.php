<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable;

    protected $fillable = [
        'pharmacy_id',
        'name',
        'username',
        'email',
        'phone',
        'locale',
        'is_active',
        'password',
        'must_change_password',
        'is_super_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'is_super_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->using(RoleUserPivot::class)
            ->withPivot('id', 'pharmacy_id', 'location_id')
            ->withTimestamps();
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function hasPermission(string $slug, ?string $pharmacyId = null): bool
    {
        return $this->roleAssignments()
            ->where('pharmacy_id', $pharmacyId ?? $this->pharmacy_id)
            ->whereHas('role.permissions', fn ($q) => $q->where('slug', $slug))
            ->exists();
    }
}
