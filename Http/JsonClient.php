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
 * A family names it its own (final class JsonClient extends this one) and
 * throws its own exceptions through answered() and unreachable(), its own
 * Data through data().
 */
class JsonClient
{
    /** @param array<string, string> $headers sent with every request */
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
        $body = $this->send($method, $url, $options, $headers);
        if (null === $body) {
            return null;
        }
        $data = json_decode($body, true);

        return \is_array($data) ? $this->data($data) : throw Redaction::withoutArguments($this->answered($method.' '.(parse_url($url, \PHP_URL_PATH) ?: '/'), 200, 'The body is not JSON.'));
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
        return $this->send('GET', $url, ['query' => array_filter($query, static fn ($value) => null !== $value)], $headers);
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
     * @param array<string, mixed>  $options
     * @param array<string, string> $headers
     */
    private function send(string $method, string $url, array $options, array $headers): ?string
    {
        $what = $method.' '.(parse_url($url, \PHP_URL_PATH) ?: '/');
        $options = ['headers' => $headers + $this->headers] + $options;
        $secrets = Redaction::secrets($url, $options);
        try {
            $this->backoff?->clear();
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            $this->backoff?->observe($status, $response->getHeaders(false));
            if (404 === $status) {
                return null;
            }
            $body = $response->getContent(false);
        } catch (UsageLimitReached $e) {
            throw Redaction::withoutArguments($e);
        } catch (ExceptionInterface $e) {
            throw Redaction::withoutArguments($this->unreachable(\sprintf('%s could not be reached for %s: %s', $this->provider, $what, Redaction::text(self::reason($e->getMessage()), $secrets)), Redaction::copy($e, $secrets)));
        }
        if ($status >= 400) {
            throw Redaction::withoutArguments($this->answered($what, $status, Redaction::text($body, $secrets)));
        }

        return $body;
    }
}
