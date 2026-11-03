<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Pré-remplissage d'une fiche produit à partir d'une désignation de grossiste,
 * ex. « METFORMINE 850 MG B/30 - LOT: L1 » → Metformine · 850 mg · comprimé · boîte de 30.
 */
class ProductLabelParser
{
    /** Abréviation (majuscules, sans accents) → [forme, unité de base] */
    private const FORMS = [
        'CPR' => ['comprimé', 'comprimé'], 'CP' => ['comprimé', 'comprimé'], 'COMP' => ['comprimé', 'comprimé'], 'COMPRIME' => ['comprimé', 'comprimé'],
        'GEL' => ['gélule', 'gélule'], 'GELULE' => ['gélule', 'gélule'], 'CAPS' => ['gélule', 'gélule'],
        'SIROP' => ['sirop', 'flacon'], 'SIR' => ['sirop', 'flacon'], 'SUSP' => ['suspension buvable', 'flacon'],
        'AMP' => ['ampoule', 'ampoule'], 'INJ' => ['solution injectable', 'ampoule'],
        'SACH' => ['poudre', 'sachet'], 'SACHET' => ['poudre', 'sachet'],
        'CREME' => ['crème', 'tube'], 'POMMADE' => ['pommade', 'tube'], 'GEL DERM' => ['gel', 'tube'],
        'COLLYRE' => ['collyre', 'flacon'], 'SUPPO' => ['suppositoire', 'suppositoire'],
        'SOL' => ['solution', 'flacon'], 'SPRAY' => ['spray', 'flacon'],
    ];

    /** @return array{brand_name: string, strength: string, dosage_form: string, units_per_pack: int, label: string, base_unit: string} */
    public static function parse(string $designation): array
    {
        // Le lot est saisi à part : on le retire du libellé
        $text = trim(preg_replace('/[-–,]?\s*\bLOT\b\s*:?\s*\S+.*$/iu', '', $designation));
        $upper = strtoupper(Str::ascii($text));

        $strength = '';
        if (preg_match('/(\d+(?:[.,]\d+)?(?:\s*\/\s*\d+(?:[.,]\d+)?)?)\s*(MG|MCG|UG|µG|G|ML|UI|%)(?![A-Z])/u', $upper, $m, PREG_OFFSET_CAPTURE)) {
            $strength = str_replace('.', ',', preg_replace('/\s+/', '', $m[1][0])).' '.strtolower(str_replace('UG', 'µg', $m[2][0]));
            $nameEnd = $m[0][1];
        }

        $units = 1;
        if (preg_match('/\b(?:B|BT|BTE|BOITE|X)\s*\/?\s*(\d{1,4})\b/', $upper, $p)) {
            $units = (int) $p[1];
            $nameEnd = min($nameEnd ?? PHP_INT_MAX, strpos($upper, $p[0]));
        }

        [$form, $baseUnit] = ['', 'unité'];
        foreach (self::FORMS as $abbr => $f) {
            if (preg_match('/\b'.preg_quote($abbr, '/').'\b/', $upper, $fm)) {
                [$form, $baseUnit] = $f;
                $nameEnd = min($nameEnd ?? PHP_INT_MAX, strpos($upper, $fm[0]));
                break;
            }
        }
        if ($form === '' && $units > 1 && $strength !== '') {
            [$form, $baseUnit] = ['comprimé', 'comprimé'];
        }

        $name = trim(mb_substr($text, 0, $nameEnd ?? mb_strlen($text)), " -–/,");

        return [
            'brand_name' => Str::title(mb_strtolower($name ?: $text)),
            'strength' => $strength,
            'dosage_form' => $form,
            'units_per_pack' => $units,
            'label' => $units > 1 ? "Boîte de $units" : 'Unité',
            'base_unit' => $baseUnit,
        ];
    }
}
