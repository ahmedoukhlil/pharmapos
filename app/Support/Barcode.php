<?php

namespace App\Support;

/**
 * Contrôle des codes-barres (SCN-02). Les codes restent des chaînes pour préserver les zéros initiaux.
 */
class Barcode
{
    /** Symbologie déduite d'un code numérique, ou code128 par défaut. */
    public static function guessSymbology(string $code): string
    {
        return match (true) {
            ctype_digit($code) && strlen($code) === 13 => 'ean13',
            ctype_digit($code) && strlen($code) === 8 => 'ean8',
            ctype_digit($code) && strlen($code) === 12 => 'upca',
            ctype_digit($code) && strlen($code) === 14 => 'gtin14',
            default => 'code128',
        };
    }

    public static function isValid(string $code, string $symbology): bool
    {
        $lengths = ['ean13' => 13, 'ean8' => 8, 'upca' => 12, 'gtin14' => 14];

        if (isset($lengths[$symbology])) {
            return ctype_digit($code) && strlen($code) === $lengths[$symbology] && self::checkDigitOk($code);
        }

        // Code128 / autres : caractères ASCII imprimables
        return $code !== '' && strlen($code) <= 64 && preg_match('/^[\x20-\x7E]+$/', $code) === 1;
    }

    /** Clé de contrôle GS1 (modulo 10, poids 3/1 depuis la droite). */
    private static function checkDigitOk(string $code): bool
    {
        $digits = array_map('intval', str_split($code));
        $check = array_pop($digits);
        $sum = 0;
        foreach (array_reverse($digits) as $i => $d) {
            $sum += $d * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === $check;
    }
}
