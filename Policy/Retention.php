<?php

namespace Omnisense\Contracts\Policy;

/**
 * How long the terms let a site keep what it got from the provider: at
 * most $maxAge seconds (DossierFacile's documents three months, YouTube's
 * API data 30 days), for what $covers says. Unlike caching.maxTtl, which
 * says how long an answer may be served again without asking, it bounds
 * keeping the data at all (a stored copy, a database row). A site may
 * shorten it, never lengthen it, nor say it covers something else.
 */
final readonly class Retention
{
    /**
     * @param int     $maxAge seconds
     * @param ?string $covers what it applies to, as the terms say it ("tenant files and the data read from them", "API data other than authorized data")
     */
    public function __construct(
        public int $maxAge,
        public ?string $covers = null,
    ) {
        if ($maxAge < 0) {
            throw InvalidPolicyException::field('rights.retention.maxAge', 'an integer of 0 or more', $maxAge);
        }
    }

    public static function days(int $days, ?string $covers = null): self
    {
        return new self($days * 86400, $covers);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        if ([] === $data) {
            return null;
        }
        Read::only($data, ['maxAge', 'covers'], 'rights.retention');

        return new self(
            Read::int($data['maxAge'] ?? null, 'rights.retention.maxAge') ?? throw InvalidPolicyException::field('rights.retention.maxAge', 'an integer of 0 or more', null),
            Read::string($data['covers'] ?? null, 'rights.retention.covers'),
        );
    }

    /** Whether something kept for $seconds is past it. */
    public function exceededBy(int $seconds): bool
    {
        return $seconds > $this->maxAge;
    }

    /** @return array{maxAge: int, covers: ?string} */
    public function toArray(): array
    {
        return ['maxAge' => $this->maxAge, 'covers' => $this->covers];
    }
}
