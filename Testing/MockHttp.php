<?php

namespace Omnisense\Contracts\Testing;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Symfony's MockHttpClient answering by route, and every request it got
 * kept as it went on the wire: the method, the full URL (query included),
 * the headers by lower-case name, the body as bytes. A provider's tests
 * assert the exact request - path, query, headers, JSON or form body - and
 * that no key leaks, with no network. Its client is Symfony's own, so it
 * streams, and sits under Symfony's PSR-18 bridge (new Psr18Client($http->client)).
 *
 *   $http = MockHttp::routes(['~^POST https://api\.example/v2/translate$~' => [200, '@'.__DIR__.'/Fixtures/translate.json']]);
 *   $provider = (new ExampleFactory($http->client))->create(['key' => 'not-a-real-key']);
 *   $http->json();                  // the last request's JSON body, decoded
 *   $http->header('authorization'); // "Key not-a-real-key"
 *
 * A route is [status, body, headers] or a closure of the method, the URL
 * and the body giving one. A body naming a file ("@/path/answer.json") is
 * read from it; the headers are sent with it (a 429's Retry-After). Nothing
 * matched: 404. RecordedHttpClient is the same with no symfony/http-client.
 *
 * @suggested symfony/http-client (test support: MockHttpClient)
 */
final class MockHttp
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    public readonly MockHttpClient $client;

    /** @param array<string, array{0: int, 1: string, 2?: array<string, string|list<string>>}|\Closure(string, string, string): array{0: int, 1: string, 2?: array<string, string|list<string>>}> $routes "METHOD url" patterns */
    private function __construct(private readonly array $routes)
    {
        $this->client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $headers = [];
            foreach ((array) ($options['normalized_headers'] ?? []) as $name => $lines) {
                foreach ((array) $lines as $line) {
                    if (\is_string($line) && str_contains($line, ':')) {
                        $value = trim(explode(':', $line, 2)[1]);
                        $headers[strtolower((string) $name)] = isset($headers[strtolower((string) $name)]) ? $headers[strtolower((string) $name)].', '.$value : $value;
                    }
                }
            }
            $body = $options['body'] ?? '';
            $body = \is_string($body) ? $body : (\is_callable($body) ? self::drain($body) : '');
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

            return $this->answer($method, $url, $body);
        });
    }

    /** @param array<string, array{0: int, 1: string, 2?: array<string, string|list<string>>}|\Closure(string, string, string): array{0: int, 1: string, 2?: array<string, string|list<string>>}> $routes */
    public static function routes(array $routes): self
    {
        return new self($routes);
    }

    /** @return array{method: string, url: string, headers: array<string, string>, body: string} */
    public function request(int $index = -1): array
    {
        return $this->requests[$index < 0 ? \count($this->requests) + $index : $index] ?? throw new \OutOfRangeException(\sprintf('No request %d; %d made.', $index, \count($this->requests)));
    }

    /**
     * "METHOD url" of each request, in order.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map(static fn (array $r) => $r['method'].' '.$r['url'], $this->requests);
    }

    /** A request's header (its values joined by ", " when it was sent several). */
    public function header(string $name, int $index = -1): ?string
    {
        return $this->request($index)['headers'][strtolower($name)] ?? null;
    }

    /** A request's JSON body, decoded. */
    public function json(int $index = -1): mixed
    {
        return json_decode($this->request($index)['body'], true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * A request's form fields (application/x-www-form-urlencoded).
     *
     * @return array<array-key, mixed>
     */
    public function fields(int $index = -1): array
    {
        parse_str($this->request($index)['body'], $fields);

        return $fields;
    }

    /** Every request, as one string: what a test greps for a key that must not be there. */
    public function dump(): string
    {
        return json_encode($this->requests, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    private function answer(string $method, string $url, string $body): MockResponse
    {
        foreach ($this->routes as $pattern => $route) {
            if (!preg_match($pattern, $method.' '.$url)) {
                continue;
            }
            $route = $route instanceof \Closure ? $route($method, $url, $body) : $route;
            [$status, $content] = $route;
            $content = str_starts_with($content, '@') ? (string) file_get_contents(substr($content, 1)) : $content;
            $headers = [];
            foreach (($route[2] ?? []) + ['Content-Type' => 'application/json'] as $name => $value) {
                foreach ((array) $value as $one) {
                    $headers[] = $name.': '.$one;
                }
            }

            return new MockResponse($content, ['http_code' => $status, 'response_headers' => $headers]);
        }

        return new MockResponse('{"error": "not recorded"}', ['http_code' => 404, 'response_headers' => ['Content-Type: application/json']]);
    }

    private static function drain(callable $body): string
    {
        $bytes = '';
        while (\is_string($chunk = $body(16384)) && '' !== $chunk) {
            $bytes .= $chunk;
        }

        return $bytes;
    }
}
