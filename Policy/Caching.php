<?php

namespace Omnisense\Contracts\Policy;

/** Whether answers may be cached, and for how long at most (seconds; null: as long as one likes). */
final readonly class Caching
{
    public function __construct(
        public Permission $permission = Permission::Unknown,
        public ?int $maxTtl = null,
    ) {
        if (null !== $maxTtl && $maxTtl < 0) {
            throw InvalidPolicyException::field('rights.caching.maxTtl', 'an integer of 0 or more', $maxTtl);
        }
    }

    public static function forbidden(): self
    {
        return new self(Permission::Forbidden);
    }

    public static function upTo(int $seconds): self
    {
        return new self(Permission::Allowed, $seconds);
    }

    /**
     * The time to live a caller may give an answer: none when caching is
     * forbidden or needs a contract, the shorter of the one wanted and the
     * maximum otherwise. Unknown terms do not shorten it: whoever runs the
     * site reads them.
     */
    public function ttl(?int $wanted = null): ?int
    {
        if (Permission::Forbidden === $this->permission || Permission::Contract === $this->permission) {
            return 0;
        }
        if (null === $this->maxTtl) {
            return $wanted;
        }

        return null === $wanted ? $this->maxTtl : min($wanted, $this->maxTtl);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        Read::only($data, ['permission', 'maxTtl'], 'rights.caching');
        return new self(
            Read::enum($data['permission'] ?? null, 'rights.caching.permission', Permission::class, Permission::Unknown),
            Read::int($data['maxTtl'] ?? null, 'rights.caching.maxTtl'),
        );
    }

    /** @return array{permission: string, maxTtl: ?int} */
    public function toArray(): array
    {
        return ['permission' => $this->permission->value, 'maxTtl' => $this->maxTtl];
    }
}
