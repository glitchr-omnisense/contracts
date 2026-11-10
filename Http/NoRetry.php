<?php

namespace Omnisense\Contracts\Http;

use Omnisense\Contracts\Testing\RecordedHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The HTTP client a provider sends with, kept from replaying a request.
 *
 * An application's client may retry by itself: Symfony's
 * RetryableHttpClient (framework.http_client's retry_failed, or a scoped
 * client's) resends a request on 423, 425, 429, 502 and 503 whatever the
 * method, and an idempotent one (GET, HEAD, PUT, DELETE, OPTIONS, TRACE,
 * QUERY) on 500, 504, 507, 510 and a transport error. Handed to a family's
 * factory as it is, it would send a payment, a label's purchase, a
 * publication or a transfer twice without the family's code retrying
 * anything.
 *
 * of() - the default - turns every retry off: withOptions(['max_retries' =>
 * 0]) reaches the RetryableHttpClient through Symfony's decorators
 * (TraceableHttpClient, ScopingHttpClient, UriTemplateHttpClient...) and
 * gives a copy that sends each request once; no reflection, nothing
 * unwrapped by hand. Why nothing at all, reads included: a 429 retried by
 * the client never reaches the provider's Backoff (the wait that holds
 * every process), each retry is a request the policy guard never counted (a
 * declared quota passed unseen), the process sleeps the Retry-After inside
 * a web request, and an API's PUT or DELETE is idempotent only when its
 * provider says so. forWrites() keeps the application's retries for the
 * safe methods (GET, HEAD, OPTIONS), which a family may choose for reads
 * that take no quota; every other method is sent once.
 *
 * Kept as given, the same instance: a client with no retry (it refuses the
 * option as unsupported), and the test clients - Symfony's MockHttpClient
 * (its copy would count requests getRequestsCount() no longer sees) and the
 * kit's RecordedHttpClient (Testing\MockHttp hands out a MockHttpClient).
 * A client whose innermost transport takes any option (a MockHttpClient
 * under Symfony's decorators: the framework's test transport) is not
 * copied either: each request is sent through it with max_retries 0
 * (SentOnce), so the very same MockHttpClient answers and counts.
 * symfony/http-client is optional: none of its classes is loaded here.
 * Needs symfony/http-client 6.4 or later under a RetryableHttpClient (the
 * max_retries option); with an older one the client is kept as given.
 */
final class NoRetry
{
    /** The methods forWrites() leaves to the application's retries: RFC 9110's safe methods. */
    public const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** Every request sent once. */
    public static function of(HttpClientInterface $http): HttpClientInterface
    {
        return self::once($http) ?? $http;
    }

    /** Every request but a GET, a HEAD or an OPTIONS sent once; those retried as the application's client does. */
    public static function forWrites(HttpClientInterface $http): HttpClientInterface
    {
        if ($http instanceof RetriedReads) {
            return $http;
        }
        $once = self::once($http);

        return null === $once ? $http : new RetriedReads($http, $once);
    }

    /** Whether the client is one NoRetry keeps as given whatever it is asked: a test client. */
    public static function isTestClient(HttpClientInterface $http): bool
    {
        return $http instanceof RecordedHttpClient
            || (class_exists(MockHttpClient::class, false) && $http instanceof MockHttpClient);
    }

    /** The client sending each request once, or null when it has no retry to turn off (or is a test client). */
    private static function once(HttpClientInterface $http): ?HttpClientInterface
    {
        if (self::isTestClient($http)) {
            return null;
        }
        try {
            $copy = $http->withOptions(['max_retries' => 0]);
        } catch (\InvalidArgumentException) {
            return null;
        }
        try {
            // An option no client knows: refused, the chain checks its options and the copy is safe to use.
            $http->withOptions(['omnisense_no_retry_probe' => true]);
        } catch (\InvalidArgumentException) {
            return $copy;
        }

        // Taken: the innermost transport takes anything (a MockHttpClient). The same chain, max_retries 0 on each request.
        return new SentOnce($http);
    }
}
