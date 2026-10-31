<?php

namespace App\Services\Cash;

use RuntimeException;

class CashSessionException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
