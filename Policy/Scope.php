<?php

namespace Omnisense\Contracts\Policy;

/**
 * What a rate limit counts per: the whole application (every call it makes
 * to the provider), each key it holds, each client address, each user. A
 * call that does not name the client address or the user it is made for
 * counts in one shared bucket: the strictest reading.
 */
enum Scope: string
{
    case Application = 'application';
    case Key = 'key';
    case ClientIp = 'client-ip';
    case User = 'user';
}
