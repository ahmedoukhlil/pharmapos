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

    /** Permissions effectives, mémorisées pour la requête : pharmacy_id => [slug => true] */
    private array $permissionCache = [];

    public function hasPermission(string $slug, ?string $pharmacyId = null): bool
    {
        return isset($this->permissionSlugs($pharmacyId)[$slug]);
    }

    /** Permissions du rôle, plus celles ajoutées et moins celles retirées pour cet utilisateur. */
    public function permissionSlugs(?string $pharmacyId = null): array
    {
        $pharmacyId ??= $this->pharmacy_id;

        return $this->permissionCache[$pharmacyId] ??= (function () use ($pharmacyId) {
            $slugs = Permission::whereHas('roles', fn ($q) => $q->whereIn('roles.id',
                $this->roleAssignments()->where('pharmacy_id', $pharmacyId)->select('role_id')))
                ->pluck('slug')->flip()->map(fn () => true)->all();
            foreach ($this->permissionOverrides($pharmacyId) as $slug => $granted) {
                if ($granted) {
                    $slugs[$slug] = true;
                } else {
                    unset($slugs[$slug]);
                }
            }

            return $slugs;
        })();
    }

    /** @return array<string, bool> slug => ajoutée (true) ou retirée (false) */
    public function permissionOverrides(?string $pharmacyId = null): array
    {
        return \Illuminate\Support\Facades\DB::table('permission_user')
            ->join('permissions', 'permissions.id', '=', 'permission_user.permission_id')
            ->where('user_id', $this->id)->where('pharmacy_id', $pharmacyId ?? $this->pharmacy_id)
            ->pluck('granted', 'permissions.slug')->map(fn ($g) => (bool) $g)->all();
    }

    public function forgetPermissions(): void
    {
        $this->permissionCache = [];
    }
}
