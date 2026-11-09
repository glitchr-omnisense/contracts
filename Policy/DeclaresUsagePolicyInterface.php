<?php

namespace Omnisense\Contracts\Policy;

/**
 * A provider that gives its usage policy without building its whole
 * Capabilities (which may read a store or call the API): the guard asks it
 * here first, Capabilities::policy() otherwise.
 */
interface DeclaresUsagePolicyInterface
{
    public function usagePolicy(): UsagePolicy;
}
