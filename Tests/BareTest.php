<?php

namespace Omnisense\Contracts\Tests;

use Omnisense\Contracts\Provider\Registry;
use Omnisense\Contracts\Testing\BareTestCase;

/**
 * omnisense/contracts with no framework: every class but the testing kit
 * (over PHPUnit) loads in a PHP process of its own with nothing from outside
 * but Symfony's HTTP client contracts (interfaces only).
 */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return Registry::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
