<?php

namespace Omnisense\Contracts\Policy;

/** What a window has left: calls, and seconds until it resets. */
final readonly class Allowance
{
    public function __construct(public int $remaining, public int $resetsIn)
    {
    }

    public function exhausted(): bool
    {
        return $this->remaining <= 0;
    }
}
