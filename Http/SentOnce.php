<?php

namespace Omnisense\Contracts\Http;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * The client given, each request sent with max_retries 0: Symfony's
 * RetryableHttpClient reads the option per request (and takes it away
 * before the transport sees it). NoRetry uses it where copying the client
 * would copy a MockHttpClient under it.
 *
 * @internal built by NoRetry
 */
final class SentOnce implements HttpClientInterface
{
    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->http->request($method, $url, ['max_retries' => 0] + $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->http->stream($responses, $timeout);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return new static($this->http->withOptions($options));
    }
}
