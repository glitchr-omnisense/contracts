<?php

namespace Omnisense\Contracts\Testing;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * An HTTP client that answers from what it was given - a callable of the
 * method, the URL and the options, returning [status, body, headers] - and
 * keeps every request made ($requests): a provider's calls checked with no
 * network. It streams its answers too (stream()), so it can sit under
 * Symfony's PSR-18 bridge (new Psr18Client($recorded)); MockHttp is the
 * same over Symfony's own MockHttpClient, every request kept as it went on
 * the wire.
 */
class RecordedHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @var ?\WeakMap<ResponseInterface, int> the chunks stream() has yielded of each response */
    private ?\WeakMap $streamed = null;

    /** @param \Closure(string, string, array<string, mixed>): array{0: int, 1: string, 2?: array<string, list<string>>} $answer */
    final public function __construct(private readonly \Closure $answer)
    {
    }

    /**
     * Answers by route: the first pattern (a regular expression matched
     * against "METHOD url", the query included) that matches gives the
     * status and the body; a body naming a file ("@/path/to/answer.json")
     * is read from it, the headers given beside it are sent with it (a 429's
     * Retry-After). A fourth element is a pattern the request's body must
     * match too (its JSON encoded, its form fields as a query string): two
     * POSTs to one address answered apart. Nothing matched: 404.
     *
     *   RecordedHttpClient::routes(['~^POST .+/translate$~' => [200, '{"ok":true}', [], '~"target_lang":"DE"~']]);
     *
     * @param array<string, array{0: int, 1: string, 2?: array<string, list<string>>, 3?: string}> $routes
     */
    public static function routes(array $routes): static
    {
        return new static(static function (string $method, string $url, array $options) use ($routes): array {
            $query = $options['query'] ?? [];
            $target = $method.' '.$url.(\is_array($query) && [] !== $query ? (str_contains($url, '?') ? '&' : '?').http_build_query($query) : '');
            foreach ($routes as $pattern => $route) {
                [$status, $body] = $route;
                if (preg_match($pattern, $target) && (!isset($route[3]) || preg_match($route[3], self::body($options)))) {
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

    /** Each response's chunks: the first, its body in one piece, the last (RecordedStream). */
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $this->streamed ??= new \WeakMap();

        return new RecordedStream($responses instanceof ResponseInterface ? [$responses] : $responses, $this->streamed);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return $this;
    }

    /**
     * A request's body as a string: as given, its JSON encoded, its form
     * fields as a query string, a generator or a closure read through.
     *
     * @param array<string, mixed> $options
     */
    public static function body(array $options): string
    {
        if (\array_key_exists('json', $options)) {
            return (string) json_encode($options['json'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }
        $body = $options['body'] ?? '';
        if (\is_array($body)) {
            return http_build_query($body);
        }
        if (\is_string($body)) {
            return $body;
        }
        $bytes = '';
        if (\is_iterable($body)) {
            foreach ($body as $chunk) {
                $bytes .= \is_string($chunk) ? $chunk : '';
            }
        } elseif ($body instanceof \Closure) {
            while (\is_string($chunk = $body(16384)) && '' !== $chunk) {
                $bytes .= $chunk;
            }
        }

        return $bytes;
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
