<?php

namespace Omnisense\Contracts\Policy;

/**
 * A UsagePolicy written in PHP, field by field:
 *
 *   UsagePolicy::builder('transitous')
 *       ->limit(RateLimit::perDay(720, 'journeys'))
 *       ->commercial(Permission::Forbidden)->openSourceRequired()
 *       ->attribution('Transitous', 'https://transitous.org/sources/', 'display')
 *       ->requireUserAgent(Requirements::USER_AGENT_WITH_CONTACT)
 *       ->provenance(terms: 'https://transitous.org/api/', verifiedAt: '2026-11-10')
 *       ->build();
 *
 * No limit() call leaves the limits unknown; noLimits() says the terms set none.
 */
final class PolicyBuilder
{
    /** @var ?list<RateLimit> */
    private ?array $limits = null;
    private Permission $commercial = Permission::Unknown;
    private ?bool $openSource = null;
    private Caching $caching;
    private Permission $storage = Permission::Unknown;
    private Permission $redistribution = Permission::Unknown;
    private Attribution $attribution;
    private ?string $licence = null;
    private Requirements $requirements;
    private Provenance $provenance;
    private ?int $concurrency = null;

    public function __construct(private readonly string $provider)
    {
        $this->caching = new Caching();
        $this->attribution = new Attribution();
        $this->requirements = new Requirements();
        $this->provenance = new Provenance();
    }

    public function limit(RateLimit ...$limits): self
    {
        $this->limits = [...($this->limits ?? []), ...array_values($limits)];

        return $this;
    }

    public function noLimits(): self
    {
        $this->limits ??= [];

        return $this;
    }

    /** The most requests the provider takes in flight at once. */
    public function concurrency(int $max): self
    {
        $this->concurrency = $max;

        return $this;
    }

    public function commercial(Permission $permission): self
    {
        $this->commercial = $permission;

        return $this;
    }

    public function openSourceRequired(bool $required = true): self
    {
        $this->openSource = $required;

        return $this;
    }

    public function caching(Caching $caching): self
    {
        $this->caching = $caching;

        return $this;
    }

    public function storage(Permission $permission): self
    {
        $this->storage = $permission;

        return $this;
    }

    public function redistribution(Permission $permission): self
    {
        $this->redistribution = $permission;

        return $this;
    }

    public function attribution(string $text, ?string $url = null, string ...$where): self
    {
        $this->attribution = new Attribution(true, $text, $url, [] === $where ? ['display'] : array_values($where));

        return $this;
    }

    public function licence(string $licence): self
    {
        $this->licence = $licence;

        return $this;
    }

    public function requireKey(bool $required = true): self
    {
        $this->requirements = new Requirements($required, $this->requirements->userAgent, $this->requirements->userAgentPattern);

        return $this;
    }

    public function requireUserAgent(?string $pattern = null): self
    {
        $this->requirements = new Requirements($this->requirements->key, true, $pattern);

        return $this;
    }

    public function provenance(?string $terms = null, ?string $policy = null, ?string $verifiedAt = null, DeclaredBy $declaredBy = DeclaredBy::Maintainer, ?string $contact = null, ?string $notes = null): self
    {
        $this->provenance = new Provenance($terms, $policy, $verifiedAt, $declaredBy, $contact, $notes);

        return $this;
    }

    public function build(): UsagePolicy
    {
        return new UsagePolicy(
            $this->provider,
            $this->limits,
            new Rights($this->commercial, $this->openSource, $this->caching, $this->storage, $this->redistribution, $this->attribution, $this->licence),
            $this->requirements,
            $this->provenance,
            $this->concurrency,
        );
    }
}
