<?php

namespace Omnisense\Contracts\Testing;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * An HTTP client that answers from what it was given - a callable of the
 * method, the URL and the options, returning [status, body, headers] - and
 * keeps every request made ($requests): a provider's calls checked with no
 * network.
 */
class RecordedHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @param \Closure(string, string, array<string, mixed>): array{0: int, 1: string, 2?: array<string, list<string>>} $answer */
    final public function __construct(private readonly \Closure $answer)
    {
    }

    /**
     * Answers by route: the first pattern (a regular expression matched
     * against "METHOD url", the query included) that matches gives the
     * status and the body; a body naming a file ("@/path/to/answer.json")
     * is read from it, the headers given beside it are sent with it (a 429's
     * Retry-After). Nothing matched: 404.
     *
     * @param array<string, array{0: int, 1: string, 2?: array<string, list<string>>}> $routes
     */
    public static function routes(array $routes): static
    {
        return new static(static function (string $method, string $url, array $options) use ($routes): array {
            $query = $options['query'] ?? [];
            $target = $method.' '.$url.(\is_array($query) && [] !== $query ? (str_contains($url, '?') ? '&' : '?').http_build_query($query) : '');
            foreach ($routes as $pattern => $route) {
                [$status, $body] = $route;
                if (preg_match($pattern, $target)) {
                    return [$status, str_starts_with($body, '@') ? (string) file_get_contents(substr($body, 1)) : $body, ($route[2] ?? []) + ['content-type' => ['application/json']]];
                }
            }

            return [404, '{"error":"not recorded"}'];
        });
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
        $answer = ($this->answer)($method, $url, $options);

        return new RecordedResponse($answer[0], $answer[1], $answer[2] ?? [], $url);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new \LogicException('RecordedHttpClient does not stream.');
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return $this;
    }

    /**
     * The last request's header, as sent.
     */
    public function header(string $name, int $request = -1): ?string
    {
        $headers = $this->requests[$request < 0 ? \count($this->requests) + $request : $request]['options']['headers'] ?? [];
        if (!\is_array($headers)) {
            return null;
        }
        foreach ($headers as $key => $value) {
            if (\is_string($key) && 0 === strcasecmp($key, $name) && \is_scalar($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
