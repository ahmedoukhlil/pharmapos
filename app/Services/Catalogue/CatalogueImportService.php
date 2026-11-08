<?php

namespace App\Services\Catalogue;

use App\Models\Category;
use App\Models\Medicine;
use App\Models\Presentation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * MED-05 : import Excel/CSV avec aperçu, erreurs par ligne et détection des doublons.
 * L'aperçu exécute l'import réel dans une transaction annulée : il voit exactement les mêmes
 * erreurs que l'import (y compris les doublons internes au fichier).
 */
class CatalogueImportService
{
    /** En-têtes reconnus => champ interne. */
    public const COLUMNS = [
        'type' => 'type', 'nom_commercial' => 'brand_name', 'dci' => 'inn', 'dosage' => 'strength',
        'forme' => 'dosage_form', 'voie' => 'route', 'fabricant' => 'manufacturer', 'categorie' => 'category',
        'ordonnance' => 'prescription_required', 'sku' => 'sku', 'libelle' => 'label', 'unite_base' => 'base_unit',
        'unites_par_boite' => 'units_per_pack', 'vente_unite' => 'allow_split_sale', 'prix_unite' => 'unit_sale_price',
        'prix_achat' => 'purchase_price', 'prix_vente' => 'sale_price', 'seuil' => 'reorder_threshold',
        'code_barres' => 'barcode',
    ];

    public const MAX_ROWS = 5000;

    public function __construct(private CatalogueService $catalogue) {}

    /** @return array<int, array{line:int, data:array}> lignes du fichier (numérotées comme dans le tableur) */
    public function read(string $path, string $extension): array
    {
        if (strtolower($extension) === 'xlsx') {
            $reader = new XlsxReader;
        } else {
            $options = new CsvOptions;
            $options->FIELD_DELIMITER = $this->detectDelimiter($path);
            $reader = new CsvReader($options);
        }

        $reader->open($path);
        $rows = [];
        $headers = null;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $i => $row) {
                $cells = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row->toArray());
                if ($headers === null) {
                    $headers = array_map(fn ($h) => Str::of(str_replace("\u{FEFF}", '', (string) $h))->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value(), $cells);
                    $unknown = array_diff(['nom_commercial'], $headers);
                    if ($unknown) {
                        throw ValidationException::withMessages(['file' => 'Colonnes obligatoires absentes : '.implode(', ', $unknown).'.']);
                    }

                    continue;
                }
                if (array_filter($cells, fn ($v) => $v !== '' && $v !== null) === []) {
                    continue;
                }
                $data = [];
                foreach ($headers as $k => $h) {
                    if (isset(self::COLUMNS[$h])) {
                        $data[self::COLUMNS[$h]] = $cells[$k] ?? null;
                    }
                }
                $rows[] = ['line' => $i, 'data' => $data];
                if (count($rows) > self::MAX_ROWS) {
                    throw ValidationException::withMessages(['file' => 'Fichier trop volumineux (maximum '.self::MAX_ROWS.' lignes).']);
                }
            }
            break;   // première feuille uniquement
        }
        $reader->close();

        return $rows;
    }

    /**
     * @return array{created:int, skipped:int, errors:int, lines:array<int, array{line:int, status:string, message:?string, label:string}>}
     */
    public function preview(User $user, array $rows): array
    {
        DB::beginTransaction();
        try {
            return $this->run($user, $rows);
        } finally {
            DB::rollBack();
        }
    }

    /** N'importe que les lignes valides ; les doublons et les lignes en erreur sont ignorés. */
    public function import(User $user, array $rows): array
    {
        return DB::transaction(fn () => $this->run($user, $rows));
    }

    private function run(User $user, array $rows): array
    {
        $result = ['created' => 0, 'skipped' => 0, 'errors' => 0, 'lines' => []];

        foreach ($rows as ['line' => $line, 'data' => $d]) {
            $label = trim(($d['brand_name'] ?? '').' '.($d['strength'] ?? '')).' - '.($d['sku'] ?? '?');
            $sku = strtoupper(trim((string) ($d['sku'] ?? '')));

            if ($sku !== '' && Presentation::where('pharmacy_id', $user->pharmacy_id)->where('sku', $sku)->exists()) {
                $result['skipped']++;
                $result['lines'][] = ['line' => $line, 'status' => 'duplicate', 'message' => "SKU {$sku} déjà présent : ligne ignorée.", 'label' => $label];

                continue;
            }

            DB::beginTransaction();   // point de sauvegarde : une ligne en erreur n'annule pas les autres
            try {
                $note = $this->importLine($user, $d);
                DB::commit();
                $result['created']++;
                $result['lines'][] = ['line' => $line, 'status' => 'ok', 'message' => $note, 'label' => $label];
            } catch (ValidationException $e) {
                DB::rollBack();
                $result['errors']++;
                $result['lines'][] = ['line' => $line, 'status' => 'error', 'message' => collect($e->errors())->flatten()->implode(' '), 'label' => $label];
            }
        }

        return $result;
    }

    /** @return string|null remarque affichée sur la ligne (prix de vente calculé ou à compléter) */
    private function importLine(User $user, array $d): ?string
    {
        $type = match (Str::lower(Str::ascii((string) ($d['type'] ?? '')))) {
            'parapharmacie', 'parapharmacy', 'para' => 'parapharmacy',
            default => 'medicine',
        };

        $categoryId = filled($d['category'] ?? null) ? $this->catalogue->saveCategory($user, $d['category'])->id : null;

        // Plusieurs présentations d'un même produit : on regroupe sur type + nom + dosage
        $medicine = Medicine::where('pharmacy_id', $user->pharmacy_id)->where('type', $type)
            ->where('brand_name', $d['brand_name'] ?? '')->where('strength', filled($d['strength'] ?? null) ? $d['strength'] : null)->first()
            ?? $this->catalogue->saveMedicine($user, [
                'type' => $type,
                'brand_name' => $d['brand_name'] ?? null,
                'inn' => $this->nullable($d['inn'] ?? null),
                'strength' => $this->nullable($d['strength'] ?? null),
                'dosage_form' => $this->nullable($d['dosage_form'] ?? null),
                'route' => $this->nullable($d['route'] ?? null),
                'manufacturer' => $this->nullable($d['manufacturer'] ?? null),
                'category_id' => $categoryId,
                'prescription_required' => $this->bool($d['prescription_required'] ?? null),
            ]);

        // Prix de vente facultatif : calculé depuis le prix d'achat et les marges, sinon 0 à compléter
        $note = null;
        $sale = $this->number($d['sale_price'] ?? null);
        $purchase = $this->number($d['purchase_price'] ?? null);
        if ($sale === null) {
            if (is_numeric($purchase) && (float) $purchase > 0) {
                $sale = CatalogueService::suggestedSalePrice($user->pharmacy_id, $type, (float) $purchase);
                $note = 'Prix de vente calculé depuis le prix d\'achat : '.number_format($sale, 2, ',', ' ').' MRU.';
            } else {
                $sale = 0;
                $note = 'Prix de vente à compléter : le produit ne peut pas être vendu à 0.';
            }
        }

        $split = $this->bool($d['allow_split_sale'] ?? null);
        $presentation = $this->catalogue->savePresentation($user, $medicine, [
            'sku' => $d['sku'] ?? null,
            'label' => $this->nullable($d['label'] ?? null) ?? 'Boîte',
            'base_unit' => $this->nullable($d['base_unit'] ?? null) ?? 'unité',
            'units_per_pack' => $this->nullable($d['units_per_pack'] ?? null) ?? 1,
            'allow_split_sale' => $split,
            'unit_sale_price' => $split ? $this->number($d['unit_sale_price'] ?? null) : null,
            'purchase_price' => $this->number($d['purchase_price'] ?? null) ?? 0,
            'sale_price' => $sale,
            'reorder_threshold' => $this->nullable($d['reorder_threshold'] ?? null),
        ]);

        // Plusieurs codes séparés par « | » ou des espaces (jamais la virgule : « 3,40094E+12 » d'Excel
        // serait coupé en deux faux codes). Une cellule numérique xlsx arrive en nombre : on la remet en chiffres.
        $raw = $d['barcode'] ?? '';
        $raw = is_int($raw) || is_float($raw) ? sprintf('%.0f', $raw) : (string) $raw;
        foreach (preg_split('/[\s|]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $code) {
            $this->catalogue->addBarcode($user, $presentation, $code);
        }

        return $note;
    }

    private function nullable($v)
    {
        return $v === '' ? null : $v;
    }

    /** « 1 250,50 » ou 1250.5 → 1250.50 */
    private function number($v): mixed
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        $n = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $v);

        return is_numeric($n) ? $n : $v;   // valeur invalide laissée telle quelle pour le message d'erreur
    }

    private function bool($v): bool
    {
        return in_array(Str::lower(Str::ascii(trim((string) $v))), ['1', 'oui', 'o', 'yes', 'y', 'x', 'vrai', 'true'], true);
    }

    private function detectDelimiter(string $path): string
    {
        $first = (string) fgets(fopen($path, 'r'));

        return substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    }
}
