<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Désignation d'un produit sur les bordereaux d'un fournisseur. */
class SupplierProductAlias extends Model
{
    use HasUlids;

    protected $fillable = ['pharmacy_id', 'supplier_id', 'label_key', 'label', 'presentation_id'];

    public function presentation(): BelongsTo
    {
        return $this->belongsTo(Presentation::class);
    }

    /** Clé de rapprochement : majuscules sans accents, espaces et ponctuation normalisés. */
    public static function key(string $label): string
    {
        return Str::limit(trim(preg_replace('/[^A-Z0-9]+/', ' ', strtoupper(Str::ascii($label)))), 191, '');
    }
}
