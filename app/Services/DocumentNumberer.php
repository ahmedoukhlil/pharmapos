<?php

namespace App\Services;

use App\Models\DocumentSequence;
use App\Models\Register;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Numérotation continue sans trou par caisse et par exercice (POS-16).
 * Doit être appelé dans la transaction de l'opération : un rollback annule aussi l'incrément.
 */
class DocumentNumberer
{
    public function next(Register $register, string $documentType, string $prefix = ''): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La numérotation doit se faire dans une transaction.');
        }

        $year = (int) now()->format('Y');
        $key = [
            'pharmacy_id' => $register->pharmacy_id,
            'register_id' => $register->id,
            'document_type' => $documentType,
            'fiscal_year' => $year,
        ];

        DocumentSequence::firstOrCreate($key, ['last_number' => 0]);

        $sequence = DocumentSequence::where($key)->lockForUpdate()->firstOrFail();
        $sequence->increment('last_number');

        return sprintf('%s%s-%d-%06d', $prefix, $register->code, $year, $sequence->last_number);
    }
}
