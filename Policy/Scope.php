<?php

namespace Omnisense\Contracts\Policy;

/**
 * What a rate limit counts per: the whole application (every call it makes
 * to the provider), each key it holds, each client address, each user, or
 * each resource of a kind the limit names (RateLimit::$per: a parcel
 * tracked, an envelope polled, a zone). A call that does not name the
 * client address or the user it is made for counts in one shared bucket:
 * the strictest reading. A call that does not name the resource of a limit
 * counted per resource is refused (ResourceNotNamed): one bucket for every
 * parcel would block the second parcel, none would let the terms be broken.
 */
enum Scope: string
{
    case Application = 'application';
    case Key = 'key';
    case ClientIp = 'client-ip';
    case User = 'user';
    case Resource = 'resource';
}
