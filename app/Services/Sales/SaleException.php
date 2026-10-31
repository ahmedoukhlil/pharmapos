<?php

namespace App\Services\Sales;

use RuntimeException;

/**
 * Refus métier d'une vente ; `reason` est un code stable exploitable par l'API et l'interface.
 */
class SaleException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
