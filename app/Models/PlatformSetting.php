<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Réglage de la plateforme (clé → valeur chiffrée). Lu en cache ; le .env sert de valeur par défaut.
 */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    public const ANTHROPIC_KEY = 'anthropic.key';

    public const ANTHROPIC_MODEL = 'anthropic.model';

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = Cache::rememberForever('platform_setting.'.$key, function () use ($key) {
            $stored = static::find($key)?->value;

            return $stored === null ? null : Crypt::decryptString($stored);
        });

        return $value ?? $default;
    }

    /** Valeur vide = retour au réglage du .env. */
    public static function put(string $key, ?string $value): void
    {
        $value = trim((string) $value);
        $value === ''
            ? static::whereKey($key)->delete()
            : static::updateOrCreate(['key' => $key], ['value' => Crypt::encryptString($value)]);
        Cache::forget('platform_setting.'.$key);
    }

    public static function anthropicKey(): ?string
    {
        return static::get(self::ANTHROPIC_KEY, config('services.anthropic.key'));
    }

    public static function anthropicModel(): string
    {
        return static::get(self::ANTHROPIC_MODEL, config('services.anthropic.model'));
    }
}
