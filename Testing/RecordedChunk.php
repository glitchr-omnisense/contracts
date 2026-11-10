<?php

namespace Omnisense\Contracts\Testing;

use Symfony\Contracts\HttpClient\ChunkInterface;

/** A piece of a RecordedResponse, as RecordedHttpClient::stream() yields it: the first (headers), the body, the last. */
final class RecordedChunk implements ChunkInterface
{
    public function __construct(private readonly string $content, private readonly int $offset, private readonly bool $first = false, private readonly bool $last = false)
    {
    }

    public function isTimeout(): bool
    {
        return false;
    }

    public function isFirst(): bool
    {
        return $this->first;
    }

    public function isLast(): bool
    {
        return $this->last;
    }

    /** @return ?array<mixed> none: a recorded answer has no 1xx */
    public function getInformationalStatus(): ?array
    {
        return null;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getError(): ?string
    {
        return null;
    }
}
