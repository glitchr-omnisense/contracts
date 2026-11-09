<?php

namespace Omnisense\Contracts\Policy;

/**
 * Who wrote a policy down: the package's maintainer, reading the
 * provider's terms; the provider itself (the company that runs the API and
 * keeps its package); the site, for data whose terms are per source and
 * given in its configuration (a GTFS feed's licence).
 */
enum DeclaredBy: string
{
    case Maintainer = 'maintainer';
    case Provider = 'provider';
    case Site = 'site';
}
