<?php

namespace Tests;

use App\Support\Reauth;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Simule un mot de passe confirmé à l'instant (opérations sensibles). */
    protected function withFreshReauth(): static
    {
        session()->put(Reauth::SESSION_KEY, now()->timestamp);

        return $this;
    }
}
