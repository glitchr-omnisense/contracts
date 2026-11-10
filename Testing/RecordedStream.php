<?php

namespace Omnisense\Contracts\Testing;

use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * RecordedHttpClient::stream(): each response's chunks in turn - the first
 * (its headers are there), its body in one piece, the last - as Symfony's
 * clients yield them, so that what reads a response by streaming it (the
 * PSR-18 bridge's Psr18Client, StreamWrapper) reads a recorded one too.
 * As Symfony's, a response's chunks are yielded once: a stream asked again
 * for it goes on from where the last one stopped ($progress, kept by the
 * client).
 */
final class RecordedStream implements ResponseStreamInterface
{
    /** @var \Generator<ResponseInterface, ChunkInterface> */
    private readonly \Generator $chunks;

    /**
     * @param iterable<ResponseInterface>         $responses
     * @param \WeakMap<ResponseInterface, int>    $progress  the chunks already yielded of each response
     */
    public function __construct(iterable $responses, \WeakMap $progress)
    {
        $this->chunks = (static function () use ($responses, $progress): \Generator {
            foreach ($responses as $response) {
                $body = $response->getContent(false);
                $chunks = [new RecordedChunk('', 0, first: true), ...('' === $body ? [] : [new RecordedChunk($body, 0)]), new RecordedChunk('', \strlen($body), last: true)];
                for ($i = $progress[$response] ?? 0, $n = \count($chunks); $i < $n; ++$i) {
                    $progress[$response] = $i + 1;
                    yield $response => $chunks[$i];
                }
            }
        })();
    }

    public function key(): ResponseInterface
    {
        return $this->chunks->key();
    }

    public function current(): ChunkInterface
    {
        return $this->chunks->current();
    }

    public function next(): void
    {
        $this->chunks->next();
    }

    public function valid(): bool
    {
        return $this->chunks->valid();
    }

    public function rewind(): void
    {
        $this->chunks->rewind();
    }
}
