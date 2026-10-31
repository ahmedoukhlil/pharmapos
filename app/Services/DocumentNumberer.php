<?php

namespace App\Services;

use App\Models\DocumentSequence;
use App\Models\Register;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Numérotation continue sans trou par exercice (POS-16) : par caisse pour les tickets et avoirs,
 * par officine pour les documents d'arrière-boutique (réceptions, inventaires).
 * Doit être appelé dans la transaction de l'opération : un rollback annule aussi l'incrément.
 */
class DocumentNumberer
{
    /** Ex. C01-2026-000123 */
    public function next(Register $register, string $documentType, string $prefix = ''): string
    {
        [$year, $number] = $this->increment($register->pharmacy_id, $register->id, $documentType);

        return sprintf('%s%s-%d-%06d', $prefix, $register->code, $year, $number);
    }

    /** Ex. RC-2026-000045 */
    public function nextForPharmacy(string $pharmacyId, string $documentType, string $prefix): string
    {
        [$year, $number] = $this->increment($pharmacyId, null, $documentType);

        return sprintf('%s-%d-%06d', $prefix, $year, $number);
    }

    /** @return array{0: int, 1: int} exercice, numéro */
    private function increment(string $pharmacyId, ?string $registerId, string $documentType): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La numérotation doit se faire dans une transaction.');
        }

        $year = (int) now()->format('Y');
        $key = [
            'pharmacy_id' => $pharmacyId,
            'register_id' => $registerId,
            'document_type' => $documentType,
            'fiscal_year' => $year,
        ];

        DocumentSequence::firstOrCreate($key, ['last_number' => 0]);

        $sequence = DocumentSequence::where($key)->lockForUpdate()->firstOrFail();
        $sequence->increment('last_number');

        return [$year, $sequence->last_number];
    }
}
