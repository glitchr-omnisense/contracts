<?php

namespace Omnisense\Contracts\Policy;

/**
 * What the site says of itself, for the rights a guard can check: whether
 * it is a commercial use, whether its code is open source, whether it holds
 * a contract with the provider. Unsaid (null), nothing is checked.
 */
final readonly class Usage
{
    public function __construct(
        public ?bool $commercial = null,
        public ?bool $openSource = null,
        public bool $contract = false,
    ) {
    }

    /** @param array<string, mixed> $data commercial, open_source, contract */
    public static function fromArray(array $data): self
    {
        Read::only($data, ['commercial', 'open_source', 'openSource', 'contract'], 'usage');
        return new self(
            Read::nullableBool($data['commercial'] ?? null, 'usage.commercial'),
            Read::nullableBool($data['open_source'] ?? $data['openSource'] ?? null, 'usage.open_source'),
            Read::bool($data['contract'] ?? null, 'usage.contract'),
        );
    }
}
