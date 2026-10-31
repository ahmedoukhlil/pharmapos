<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Lecture des dates de péremption saisies ou imprimées sur les boîtes (Annexe A).
 * Un format mois/année expire en fin de mois par défaut (paramètre expiry.month_year_rule).
 */
class ExpiryDate
{
    /**
     * Accepte MM/AAAA, MM/AA, AAAA-MM, JJ/MM/AAAA et AAAA-MM-JJ. Retourne null si illisible.
     */
    public static function parse(string $raw, string $pharmacyId): ?Carbon
    {
        $raw = trim(str_replace(['.', '-', ' '], ['/', '/', ''], $raw));

        if (preg_match('#^(\d{1,2})/(\d{2}|\d{4})$#', $raw, $m)) {
            [$month, $year] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('#^(\d{4})/(\d{1,2})$#', $raw, $m)) {
            [$year, $month] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return Carbon::create((int) $m[3], (int) $m[2], (int) $m[1])->startOfDay();
        } elseif (preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $raw, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3])->startOfDay();
        } else {
            return null;
        }

        if ($month < 1 || $month > 12) {
            return null;
        }
        $year += $year < 100 ? 2000 : 0;
        $first = Carbon::create($year, $month, 1)->startOfDay();

        return PharmacySettings::get($pharmacyId, 'expiry.month_year_rule', 'end_of_month') === 'start_of_month'
            ? $first
            : $first->endOfMonth()->startOfDay();
    }
}
