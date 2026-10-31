<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Journaux immuables (audit, mouvements, paiements) : une correction est
 * une nouvelle écriture compensatoire, jamais une modification.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new LogicException(static::class.' est en ajout seul.'));
        static::deleting(fn () => throw new LogicException(static::class.' est en ajout seul.'));
    }
}
