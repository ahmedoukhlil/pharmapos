<?php

namespace App\Services\DeliveryNotes;

/**
 * Transcrit un bordereau de livraison (photos et/ou PDF, une ou plusieurs pages).
 */
interface DeliveryNoteExtractor
{
    /**
     * @param  list<array{path: string, mime: string}>  $files  pages dans l'ordre
     * @return array{
     *     delivery_note_number: string, supplier_name: string, date: string, total: float,
     *     lines: list<array{designation: string, code: string, lot: string, expiry: string, quantity: float, unit_price: float, line_total: float}>,
     *     usage: array{input_tokens: int, output_tokens: int, model: string}
     * }
     *
     * @throws DeliveryNoteException
     */
    public function extract(array $files): array;
}
