<?php

namespace Omnisense\Contracts\Http;

use Omnisense\Contracts\Exception\ProviderException;
use Omnisense\Contracts\Exception\UnreachableException;
use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\UsageLimitReached;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A provider's JSON calls over the application's HTTP client: the
 * provider's headers on every request, a 404 read as "not known" (null),
 * any other error status as a ProviderException naming the path only (never
 * the query, where a key may sit), a network failure as an
 * UnreachableException naming the path only too, with the HTTP client's
 * reason without the address it quotes (reason()). Its previous exception is
 * a copy of the client's (Redaction::copy(): same class and code, the key
 * masked in its message), and neither keeps its trace's arguments, where the
 * request's query and headers stood.
 *
 * Given the provider's Backoff (a factory's Config::backoff()), it waits
 * while the provider asked to (UsageLimitReached before the request), and
 * reads every response's Retry-After and RateLimit fields: a 429 is a
 * UsageLimitReached with the wait asked, a RateLimit with nothing left
 * holds the next calls until its window ends.
 *
 * An empty body (a 204, a 304) reads as an empty Data; decode() is the hook
 * for a body read otherwise. raw() makes the calls whose answer is not JSON
 * or whose headers matter (CalDAV's XML, an ETag), with the same rules, and
 * gives the status, the headers, the body and the URL that answered. A
 * header may carry several values (a list: Prefer's preferences).
 *
 * A family names it its own (final class JsonClient extends this one) and
 * throws its own exceptions through answered() and unreachable(), its own
 * Data through data().
 */
class JsonClient
{
    /** @param array<string, string|list<string>> $headers sent with every request */
    public function __construct(
        private readonly HttpClientInterface $http,
        protected readonly string $provider,
        private readonly array $headers = [],
        private readonly ?Backoff $backoff = null,
    ) {
    }

    /**
     * @param array<string, string|int|float|bool|null> $query
     * @param array<string, string>                     $headers
     *
     * @return ?Data null when the provider answers 404
     */
    public function get(string $url, array $query = [], array $headers = []): ?Data
    {
        return $this->request('GET', $url, ['query' => array_filter($query, static fn ($value) => null !== $value)], $headers);
    }

    /**
     * @param array<string, mixed>  $options json, body or query, as Symfony's HttpClient takes them
     * @param array<string, string> $headers
     *
     * @return ?Data null when the provider answers 404
     */
    public function request(string $method, string $url, array $options = [], array $headers = []): ?Data
    {
        $answer = $this->exchange($method, $url, $options, $headers, [404]);

        return 404 === $answer->status ? null : $this->decode($answer->body, $method.' '.(parse_url($url, \PHP_URL_PATH) ?: '/'), $answer->status);
    }

    /**
     * Any call, its answer as it came: the status, the headers, the body
     * (XML, empty, anything), the URL that answered after redirects. The
     * same errors as request() but for the statuses in $pass, given back
     * (a 404 read as "not there", a 412 as "changed meanwhile").
     *
     * @param array<string, mixed>               $options json, body or query, as Symfony's HttpClient takes them
     * @param array<string, string|list<string>> $headers
     * @param list<int>                          $pass    error statuses given back instead of thrown
     */
    public function raw(string $method, string $url, array $options = [], array $headers = [], array $pass = [404]): Answer
    {
        return $this->exchange($method, $url, $options, $headers, $pass);
    }

    /**
     * A body that is not JSON - a GTFS-Realtime feed (Protocol Buffers), an
     * archive - with the same errors as get().
     *
     * @param array<string, string|int|float|bool|null> $query
     * @param array<string, string>                     $headers
     *
     * @return ?string null when the provider answers 404
     */
    public function bytes(string $url, array $query = [], array $headers = []): ?string
    {
        $answer = $this->exchange('GET', $url, ['query' => array_filter($query, static fn ($value) => null !== $value)], $headers, [404]);

        return 404 === $answer->status ? null : $answer->body;
    }

    /**
     * An HTTP client's message without the addresses it quotes: Symfony's
     * ' for "<url>"' dropped, any other address cut to its scheme, host and
     * path (no credentials, no query, no fragment).
     */
    public static function reason(string $message): string
    {
        $message = (string) preg_replace('~\s+for\s+"[a-z][a-z0-9+.\-]*://[^"]*"~i', '', $message);
        $message = (string) preg_replace_callback('~[a-z][a-z0-9+.\-]*://[^\s"\'<>]+~i', static function (array $match): string {
            $parts = parse_url($match[0]);
            if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
                return '(an address)';
            }

            return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
        }, $message);
        $message = rtrim(trim($message), '.');

        return '' !== $message ? $message.'.' : 'no reason given.';
    }

    /** @param array<mixed> $data */
    protected function data(array $data): Data
    {
        return new Data($data);
    }

    /**
     * A successful answer's body as Data: an empty body (a 204, a 304) as an
     * empty Data, JSON as its values; anything else is the provider's error.
     * A family overrides it for a body read otherwise (NDJSON, a JSON
     * string).
     */
    protected function decode(string $body, string $what, int $status): Data
    {
        if ('' === trim($body)) {
            return $this->data([]);
        }
        $data = json_decode($body, true);

        return \is_array($data) ? $this->data($data) : throw Redaction::withoutArguments($this->answered($what, 200, 'The body is not JSON.'));
    }

    /** The provider answered an error status, or a body that is not JSON ($status 200). */
    protected function answered(string $what, int $status, string $detail): \Throwable
    {
        return ProviderException::answered($this->provider, $what, $status, $detail);
    }

    protected function unreachable(string $message, \Throwable $previous): \Throwable
    {
        return new UnreachableException($message, 0, $previous);
    }

    /**
     * @param array<string, mixed>               $options
     * @param array<string, string|list<string>> $headers
     * @param list<int>                          $pass
     */
    private function exchange(string $method, string $url, array $options, array $headers, array $pass): Answer
    {
        $what = $method.' '.(parse_url($url, \PHP_URL_PATH) ?: '/');
        $options = ['headers' => $headers + $this->headers] + $options;
        $secrets = Redaction::secrets($url, $options);
        try {
            $this->backoff?->clear();
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            $received = $response->getHeaders(false);
            $this->backoff?->observe($status, $received);
            $body = $response->getContent(false);
            $final = $response->getInfo('url');
        } catch (UsageLimitReached $e) {
            throw Redaction::withoutArguments($e);
        } catch (ExceptionInterface $e) {
            throw Redaction::withoutArguments($this->unreachable(\sprintf('%s could not be reached for %s: %s', $this->provider, $what, Redaction::text(self::reason($e->getMessage()), $secrets)), Redaction::copy($e, $secrets)));
        }
        if ($status >= 400 && !\in_array($status, $pass, true)) {
            throw Redaction::withoutArguments($this->answered($what, $status, Redaction::text($body, $secrets)));
        }
        $lower = [];
        foreach ($received as $name => $values) {
            $lower[strtolower($name)] = array_map('strval', $values);
        }

        return new Answer($status, $lower, $body, \is_string($final) && '' !== $final ? $final : $url);
    }
}
