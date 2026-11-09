<?php

namespace Omnisense\Contracts\Policy;

/**
 * What the terms allow of whoever uses the provider: commercial use,
 * whether the application must be open source, caching answers, storing
 * the data, redistributing it, what to credit, the data's licence.
 */
final readonly class Rights
{
    /**
     * @param ?bool   $openSourceRequired null: the terms do not say, or nobody read them
     * @param ?string $licence            the data's licence, as SPDX names it where it can ("ODbL-1.0", "CC-BY-4.0", "etalab-2.0")
     */
    public function __construct(
        public Permission $commercial = Permission::Unknown,
        public ?bool $openSourceRequired = null,
        public Caching $caching = new Caching(),
        public Permission $storage = Permission::Unknown,
        public Permission $redistribution = Permission::Unknown,
        public Attribution $attribution = new Attribution(),
        public ?string $licence = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        Read::only($data, ['commercial', 'openSourceRequired', 'caching', 'storage', 'redistribution', 'attribution', 'licence'], 'rights');
        return new self(
            Read::enum($data['commercial'] ?? null, 'rights.commercial', Permission::class, Permission::Unknown),
            Read::nullableBool($data['openSourceRequired'] ?? null, 'rights.openSourceRequired'),
            Caching::fromArray(Read::object($data['caching'] ?? null, 'rights.caching')),
            Read::enum($data['storage'] ?? null, 'rights.storage', Permission::class, Permission::Unknown),
            Read::enum($data['redistribution'] ?? null, 'rights.redistribution', Permission::class, Permission::Unknown),
            Attribution::fromArray(Read::object($data['attribution'] ?? null, 'rights.attribution')),
            Read::string($data['licence'] ?? null, 'rights.licence'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'commercial' => $this->commercial->value,
            'openSourceRequired' => $this->openSourceRequired,
            'caching' => $this->caching->toArray(),
            'storage' => $this->storage->value,
            'redistribution' => $this->redistribution->value,
            'attribution' => $this->attribution->toArray(),
            'licence' => $this->licence,
        ];
    }
}
