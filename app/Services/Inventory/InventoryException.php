<?php

namespace App\Services\Inventory;

use RuntimeException;

class InventoryException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
