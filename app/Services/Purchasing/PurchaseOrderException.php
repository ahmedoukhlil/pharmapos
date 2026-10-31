<?php

namespace App\Services\Purchasing;

use RuntimeException;

class PurchaseOrderException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
