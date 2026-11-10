<?php

namespace Omnisense\Contracts\Http;

/**
 * What JsonClient::raw() got back: the status, the headers (names in lower
 * case), the body as it came (XML, an empty 204, anything), and the URL that
 * finally answered, after redirects (CalDAV resolves its hrefs against it).
 * A family may name it its own, as Data.
 */
readonly class Answer
{
    /** @param array<string, list<string>> $headers by lower-case name */
    final public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public string $url,
    ) {
    }

    /** A header's first value; null when it was not sent. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /**
     * The body as JSON: an empty Data for an empty body (a 204) or one that
     * is not JSON.
     *
     * @template T of Data
     *
     * @param class-string<T> $class the family's Data
     *
     * @return T
     */
    public function json(string $class = Data::class): Data
    {
        return $class::of('' === trim($this->body) ? [] : json_decode($this->body, true));
    }
}
