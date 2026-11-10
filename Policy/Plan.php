<?php

namespace Omnisense\Contracts\Policy;

/**
 * One plan a provider publishes limits for (Free, Pro, S1...): its rate
 * limits and concurrency - each null when the plan keeps the policy's -,
 * where they are published and the day they were read there.
 */
final readonly class Plan
{
    /** @var ?list<RateLimit> */
    public ?array $rateLimits;

    /**
     * @param ?list<RateLimit> $rateLimits null: the policy's
     * @param string           $source     where the provider publishes the plan's limits
     * @param string           $verifiedAt the day they were read there (YYYY-MM-DD)
     */
    public function __construct(
        public string $source,
        public string $verifiedAt,
        ?array $rateLimits = null,
        public ?int $concurrency = null,
        public ?string $note = null,
        string $path = 'plans.plans[]',
    ) {
        if ('' === trim($source)) {
            throw InvalidPolicyException::field($path.'.source', 'where the plan\'s limits are published', $source);
        }
        if (1 !== preg_match('~^\d{4}-\d{2}-\d{2}$~', $verifiedAt) || false === \DateTimeImmutable::createFromFormat('!Y-m-d', $verifiedAt)) {
            throw InvalidPolicyException::field($path.'.verifiedAt', 'a date (YYYY-MM-DD)', $verifiedAt);
        }
        if (null !== $concurrency && $concurrency < 1) {
            throw InvalidPolicyException::field($path.'.concurrency', 'an integer of 1 or more, or null', $concurrency);
        }
        $this->rateLimits = $rateLimits;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path = 'plans.plans[]'): self
    {
        Read::only($data, ['rateLimits', 'concurrency', 'source', 'verifiedAt', 'note'], $path);
        $limits = null;
        if (null !== ($data['rateLimits'] ?? null)) {
            if (!\is_array($data['rateLimits']) || !array_is_list($data['rateLimits'])) {
                throw InvalidPolicyException::field($path.'.rateLimits', 'a list or null', $data['rateLimits']);
            }
            $limits = [];
            foreach ($data['rateLimits'] as $i => $limit) {
                $limits[] = RateLimit::fromArray(Read::object($limit, $path.'.rateLimits['.$i.']'), $path.'.rateLimits['.$i.']');
            }
        }

        return new self(
            Read::string($data['source'] ?? null, $path.'.source') ?? throw InvalidPolicyException::field($path.'.source', 'where the plan\'s limits are published', null),
            Read::string($data['verifiedAt'] ?? null, $path.'.verifiedAt') ?? throw InvalidPolicyException::field($path.'.verifiedAt', 'a date (YYYY-MM-DD)', null),
            $limits,
            Read::int($data['concurrency'] ?? null, $path.'.concurrency', 1),
            Read::string($data['note'] ?? null, $path.'.note'),
            $path,
        );
    }

    /** @return array{rateLimits: ?list<array<string, mixed>>, concurrency: ?int, source: string, verifiedAt: string, note: ?string} the schema's shape */
    public function toArray(): array
    {
        return [
            'rateLimits' => null === $this->rateLimits ? null : array_map(static fn (RateLimit $limit) => $limit->toArray(), $this->rateLimits),
            'concurrency' => $this->concurrency,
            'source' => $this->source,
            'verifiedAt' => $this->verifiedAt,
            'note' => $this->note,
        ];
    }
}
