<?php

namespace Omnisense\Contracts\Http;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * NoRetry::forWrites()'s client: a safe method (GET, HEAD, OPTIONS) goes
 * through the application's client and its retries, every other one
 * through its copy with the retries off. Both are the same chain of
 * decorators, so one streams the other's responses.
 *
 * @internal built by NoRetry::forWrites()
 */
final class RetriedReads implements HttpClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $retrying,
        private readonly HttpClientInterface $once,
    ) {
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return \in_array(strtoupper($method), NoRetry::SAFE_METHODS, true)
            ? $this->retrying->request($method, $url, $options)
            : $this->once->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->once->stream($responses, $timeout);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return new static($this->retrying->withOptions($options), $this->once->withOptions(['max_retries' => 0] + $options));
    }
}
