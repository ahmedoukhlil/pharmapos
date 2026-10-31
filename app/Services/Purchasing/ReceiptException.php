<?php

namespace App\Services\Purchasing;

use RuntimeException;

class ReceiptException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly ?int $lineNo = null)
    {
        parent::__construct($lineNo ? "$message (ligne $lineNo)" : $message);
    }
}
