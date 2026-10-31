<?php

namespace App\Support;

use App\Models\Presentation;
use App\Models\ProductBarcode;
use Illuminate\Support\Collection;

/**
 * Recherche produit (MED-02, POS-03) : code-barres ou SKU exacts d'abord, puis DCI / nom commercial / SKU partiel.
 */
class ProductLookup
{
    public static function find(string $pharmacyId, string $term, int $limit = 8): Collection
    {
        $term = trim($term);
        $base = fn () => Presentation::with('medicine')->where('pharmacy_id', $pharmacyId)->where('status', 'active');

        $exactIds = ProductBarcode::where('pharmacy_id', $pharmacyId)->where('is_active', true)
            ->where('barcode', $term)->pluck('presentation_id');
        $exact = $base()->where(fn ($q) => $q->whereIn('id', $exactIds)->orWhere('sku', $term))->get();
        if ($exact->isNotEmpty()) {
            return $exact;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $base()
            ->where(fn ($q) => $q->where('sku', 'like', $like)
                ->orWhereHas('medicine', fn ($m) => $m->where('brand_name', 'like', $like)->orWhere('inn', 'like', $like)))
            ->limit($limit)
            ->get();
    }
}
