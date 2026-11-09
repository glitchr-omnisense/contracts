<?php

namespace Omnisense\Contracts\Testing;

use Symfony\Contracts\HttpClient\ResponseInterface;

/** RecordedHttpClient's answer. */
class RecordedResponse implements ResponseInterface
{
    /** @param array<string, list<string>> $headers */
    public function __construct(private readonly int $status, private readonly string $body, private readonly array $headers, private readonly string $url)
    {
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    /** @return array<string, list<string>> */
    public function getHeaders(bool $throw = true): array
    {
        return array_change_key_case($this->headers);
    }

    public function getContent(bool $throw = true): string
    {
        return $this->body;
    }

    /** @return array<mixed> */
    public function toArray(bool $throw = true): array
    {
        $array = json_decode($this->body, true);

        return \is_array($array) ? $array : [];
    }

    public function cancel(): void
    {
    }

    public function getInfo(?string $type = null): mixed
    {
        $info = ['http_code' => $this->status, 'url' => $this->url, 'response_headers' => []];

        return null === $type ? $info : ($info[$type] ?? null);
    }
}
