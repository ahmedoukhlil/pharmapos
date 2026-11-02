<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pharmacy extends Model
{
    use HasUlids;

    protected $table = 'pharmacies';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'subscription_ends_at' => 'date',
        ];
    }

    /** SaaS : une pharmacie suspendue ou dont l'abonnement a expiré ne peut plus se connecter. */
    public function isAccessible(): bool
    {
        return $this->is_active && ! $this->subscriptionExpired();
    }

    public function subscriptionExpired(): bool
    {
        return $this->subscription_ends_at !== null && $this->subscription_ends_at->endOfDay()->isPast();
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function registers(): HasMany
    {
        return $this->hasMany(Register::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function medicines(): HasMany
    {
        return $this->hasMany(Medicine::class);
    }

    public function presentations(): HasMany
    {
        return $this->hasMany(Presentation::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class);
    }
}
