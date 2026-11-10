<?php

namespace Omnisense\Contracts\Policy;

/**
 * What a rate limit's maximum counts. "requests" (the default) are the
 * provider's HTTP requests, as the IETF RateLimit-Policy field's default
 * quota unit; "content-bytes" is that draft's other unit of volume. The
 * others are the provider's own: quota "units" (YouTube Data API),
 * "points" (Twitch, GitHub's GraphQL API), "characters" (DeepL, Google
 * Translation). A limit's cost says what one operation consumes; a
 * provider reports what it cannot know before the call (PolicyGuard::call()'s
 * amounts).
 */
enum Unit: string
{
    case Requests = 'requests';
    case ContentBytes = 'content-bytes';
    case Characters = 'characters';
    case Units = 'units';
    case Points = 'points';
}
