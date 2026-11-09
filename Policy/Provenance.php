<?php

namespace Omnisense\Contracts\Policy;

/**
 * Where a policy comes from: the terms and the usage policy it was read
 * from, the day someone last checked it against them, who wrote it down,
 * whom to ask, and what did not fit the fields.
 */
final readonly class Provenance
{
    /** @param ?string $verifiedAt the day the policy was last read against its source (YYYY-MM-DD) */
    public function __construct(
        public ?string $terms = null,
        public ?string $policy = null,
        public ?string $verifiedAt = null,
        public DeclaredBy $declaredBy = DeclaredBy::Maintainer,
        public ?string $contact = null,
        public ?string $notes = null,
    ) {
        if (null !== $verifiedAt && (1 !== preg_match('~^\d{4}-\d{2}-\d{2}$~', $verifiedAt) || false === \DateTimeImmutable::createFromFormat('!Y-m-d', $verifiedAt))) {
            throw InvalidPolicyException::field('provenance.verifiedAt', 'a date (YYYY-MM-DD)', $verifiedAt);
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        Read::only($data, ['terms', 'policy', 'verifiedAt', 'declaredBy', 'contact', 'notes'], 'provenance');
        return new self(
            Read::string($data['terms'] ?? null, 'provenance.terms'),
            Read::string($data['policy'] ?? null, 'provenance.policy'),
            Read::string($data['verifiedAt'] ?? null, 'provenance.verifiedAt'),
            Read::enum($data['declaredBy'] ?? null, 'provenance.declaredBy', DeclaredBy::class, DeclaredBy::Maintainer),
            Read::string($data['contact'] ?? null, 'provenance.contact'),
            Read::string($data['notes'] ?? null, 'provenance.notes'),
        );
    }

    /** @return array{terms: ?string, policy: ?string, verifiedAt: ?string, declaredBy: string, contact: ?string, notes: ?string} */
    public function toArray(): array
    {
        return ['terms' => $this->terms, 'policy' => $this->policy, 'verifiedAt' => $this->verifiedAt, 'declaredBy' => $this->declaredBy->value, 'contact' => $this->contact, 'notes' => $this->notes];
    }
}
