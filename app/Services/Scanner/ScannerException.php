<?php

namespace App\Services\Scanner;

use RuntimeException;

class ScannerException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
