<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Lecture des paramètres d'officine (Annexe A), avec valeur par défaut.
 */
class PharmacySettings
{
    public static function get(string $pharmacyId, string $key, mixed $default = null): mixed
    {
        $setting = Setting::where('pharmacy_id', $pharmacyId)->where('key', $key)->first();

        return $setting ? $setting->value : $default;
    }

    public static function set(string $pharmacyId, string $key, mixed $value): void
    {
        Setting::updateOrCreate(['pharmacy_id' => $pharmacyId, 'key' => $key], ['value' => $value]);
    }
}
