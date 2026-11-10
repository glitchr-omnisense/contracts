<?php

namespace Omnisense\Contracts\Policy;

/**
 * A provider's usage policy, declared as data: its rate limits, the rights
 * its terms give (commercial use, open source, caching, storage,
 * redistribution, attribution, licence), what every request must carry,
 * and where all this was read. A provider package declares it (a JSON file
 * beside its code, read by fromFile(), or the builder), a site may tighten
 * it (tighten()), never loosen it, and a PolicyGuard enforces it.
 *
 * $rateLimits null means nobody knows them; an empty list, that the terms
 * set none. $concurrency is the most requests the provider takes in flight
 * at once (null: not said); it is declared and exported, not enforced by
 * PolicyGuard (a PHP process makes one request at a time): an asynchronous
 * client or a shared semaphore reads it.
 */
final readonly class UsagePolicy
{
    public const SCHEMA = __DIR__.'/../resources/usage-policy.schema.json';

    /** The name of a policy whose provider is not known where it is written (a family's Capabilities): the registry gives it the provider's. */
    public const UNNAMED = '(unnamed)';

    /** @var ?list<RateLimit> */
    public ?array $rateLimits;

    /** @param ?list<RateLimit> $rateLimits */
    public function __construct(
        public string $provider,
        ?array $rateLimits = null,
        public Rights $rights = new Rights(),
        public Requirements $requirements = new Requirements(),
        public Provenance $provenance = new Provenance(),
        public ?int $concurrency = null,
    ) {
        if ('' === trim($provider)) {
            throw InvalidPolicyException::field('provider', 'a name', $provider);
        }
        if (null !== $concurrency && $concurrency < 1) {
            throw InvalidPolicyException::field('concurrency', 'an integer of 1 or more, or null', $concurrency);
        }
        $this->rateLimits = $rateLimits;
    }

    public static function builder(string $provider): PolicyBuilder
    {
        return new PolicyBuilder($provider);
    }

    /** Nothing known: no limit, no right read. */
    public static function unknown(string $provider): self
    {
        return new self($provider);
    }

    /**
     * What a family's older Capabilities said in free text, as a policy: the
     * attribution to show, the data's licence, the terms' first address,
     * whether a key is needed; the limits unknown.
     *
     * @param list<string> $terms
     */
    public static function described(string $provider, ?string $attribution = null, ?string $licence = null, array $terms = [], bool $keyless = false): self
    {
        return new self(
            $provider,
            null,
            new Rights(attribution: new Attribution(null !== $attribution, $attribution, null, null !== $attribution ? ['display'] : []), licence: $licence),
            new Requirements(key: !$keyless),
            new Provenance(terms: $terms[0] ?? null, notes: [] === $terms ? null : 'Terms: '.implode(', ', $terms)),
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        Read::only($data, ['$schema', 'provider', 'rateLimits', 'concurrency', 'rights', 'requirements', 'provenance'], '(root)');
        $provider = Read::string($data['provider'] ?? null, 'provider') ?? throw InvalidPolicyException::field('provider', 'a name', null);
        $limits = null;
        if (null !== ($data['rateLimits'] ?? null)) {
            if (!\is_array($data['rateLimits']) || !array_is_list($data['rateLimits'])) {
                throw InvalidPolicyException::field('rateLimits', 'a list or null', $data['rateLimits']);
            }
            $limits = [];
            foreach ($data['rateLimits'] as $i => $limit) {
                $limits[] = RateLimit::fromArray(Read::object($limit, 'rateLimits['.$i.']'), 'rateLimits['.$i.']');
            }
        }

        return new self(
            $provider,
            $limits,
            Rights::fromArray(Read::object($data['rights'] ?? null, 'rights')),
            Requirements::fromArray(Read::object($data['requirements'] ?? null, 'requirements')),
            Provenance::fromArray(Read::object($data['provenance'] ?? null, 'provenance')),
            Read::int($data['concurrency'] ?? null, 'concurrency', 1),
        );
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidPolicyException('The usage policy is not JSON: '.$e->getMessage().'.', 0, $e);
        }

        return self::fromArray(Read::object($data, '(root)'));
    }

    public static function fromFile(string $path): self
    {
        $json = is_file($path) ? file_get_contents($path) : false;

        return false === $json ? throw new InvalidPolicyException(\sprintf('No usage policy at "%s".', $path)) : self::fromJson($json);
    }

    /** The same policy under another provider's name. */
    public function named(string $provider): self
    {
        return new self($provider, $this->rateLimits, $this->rights, $this->requirements, $this->provenance, $this->concurrency);
    }

    public function knowsLimits(): bool
    {
        return null !== $this->rateLimits;
    }

    /** @return list<RateLimit> the limits that count an operation */
    public function limitsFor(string $operation): array
    {
        return array_values(array_filter($this->rateLimits ?? [], static fn (RateLimit $limit) => $limit->appliesTo($operation)));
    }

    /**
     * The policy a site's configuration makes of this one: its rate limits
     * added to the provider's (each counts: the strictest wins), its
     * concurrency, rights and requirements laid over them - only where they
     * are stricter. A looser value (a site's limit with a higher maximum for
     * the same count as a declared one, more requests in flight, a longer
     * time to live, caching allowed where it was not, an attribution dropped
     * or rewritten, a requirement lifted) throws InvalidPolicyException:
     * what a provider declares, a site cannot undo. Only the limits the site
     * adds are compared with the declared ones: a policy may carry two
     * limits on the same calls (a site's earlier tightening, a provider's
     * two figures), and stays tightenable.
     *
     * @param array<string, mixed> $site rateLimits, concurrency, rights, requirements - in the schema's shape, any part of it
     */
    public function tighten(array $site): self
    {
        foreach (array_keys($site) as $key) {
            if (!\in_array($key, ['rateLimits', 'concurrency', 'rights', 'requirements'], true)) {
                throw InvalidPolicyException::field((string) $key, 'absent: a site tightens rateLimits, concurrency, rights and requirements only', $site[$key]);
            }
        }
        if ([] === $site) {
            return $this;
        }
        $merged = $this->toArray();
        if (null !== ($site['rateLimits'] ?? null)) {
            $limits = $site['rateLimits'];
            if (!\is_array($limits) || !array_is_list($limits)) {
                throw InvalidPolicyException::field('rateLimits', 'a list', $limits);
            }
            $merged['rateLimits'] = [...($this->rateLimits ? array_map(static fn (RateLimit $limit) => $limit->toArray(), $this->rateLimits) : []), ...$limits];
        }
        if (null !== ($site['concurrency'] ?? null)) {
            $merged['concurrency'] = $site['concurrency'];
        }
        $rights = Read::object($site['rights'] ?? null, 'rights');
        $mergedRights = array_replace_recursive($this->rights->toArray(), $rights);
        $where = Read::strings(Read::object($rights['attribution'] ?? null, 'rights.attribution')['where'] ?? null, 'rights.attribution.where');
        $attribution = Read::object($mergedRights['attribution'] ?? null, 'rights.attribution');
        $attribution['where'] = array_values(array_unique([...$this->rights->attribution->where, ...$where]));
        $mergedRights['attribution'] = $attribution;
        $merged['rights'] = $mergedRights;
        $merged['requirements'] = array_replace_recursive($this->requirements->toArray(), Read::object($site['requirements'] ?? null, 'requirements'));

        $tightened = self::fromArray($merged);
        $this->assertNotLoosenedBy($tightened, \array_slice($tightened->rateLimits ?? [], \count($this->rateLimits ?? [])));

        return $tightened;
    }

    /**
     * What the terms forbid of a site that says what it is.
     *
     * @return list<string> one sentence per right it would break; empty when it may
     */
    public function violations(Usage $usage): array
    {
        $violations = [];
        if (true === $usage->commercial && Permission::Forbidden === $this->rights->commercial) {
            $violations[] = 'commercial use is forbidden';
        }
        if (true === $usage->commercial && Permission::Contract === $this->rights->commercial && !$usage->contract) {
            $violations[] = 'commercial use needs a contract with the provider';
        }
        if (false === $usage->openSource && true === $this->rights->openSourceRequired) {
            $violations[] = 'the application must be open source';
        }

        return $violations;
    }

    /** @return array<string, mixed> the schema's shape */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'rateLimits' => null === $this->rateLimits ? null : array_map(static fn (RateLimit $limit) => $limit->toArray(), $this->rateLimits),
            'concurrency' => $this->concurrency,
            'rights' => $this->rights->toArray(),
            'requirements' => $this->requirements->toArray(),
            'provenance' => $this->provenance->toArray(),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
    }

    /** @param list<RateLimit> $added the site's limits */
    private function assertNotLoosenedBy(self $tightened, array $added): void
    {
        $loosened = fn (string $field, string $declared, string $given) => InvalidPolicyException::loosened($this->provider, $field, $declared, $given);
        foreach ($added as $limit) {
            foreach ($this->rateLimits ?? [] as $declared) {
                if ($limit->sameCountAs($declared) && $limit->max > $declared->max) {
                    throw $loosened('rate limit', $declared->describe(), $limit->describe());
                }
            }
        }
        if (null !== $this->concurrency && (null === $tightened->concurrency || $tightened->concurrency > $this->concurrency)) {
            throw $loosened('concurrency', $this->concurrency.' in flight', null === $tightened->concurrency ? 'no maximum' : $tightened->concurrency.' in flight');
        }
        $old = $this->rights;
        $new = $tightened->rights;
        foreach (['commercial use' => [$old->commercial, $new->commercial], 'storage' => [$old->storage, $new->storage], 'redistribution' => [$old->redistribution, $new->redistribution], 'caching' => [$old->caching->permission, $new->caching->permission]] as $field => [$declared, $given]) {
            if ($declared->isStricterThan($given)) {
                throw $loosened($field, $declared->value, $given->value);
            }
        }
        if (true === $old->openSourceRequired && true !== $new->openSourceRequired) {
            throw $loosened('open-source requirement', 'required', 'not required');
        }
        if (null !== $old->caching->maxTtl && (null === $new->caching->maxTtl || $new->caching->maxTtl > $old->caching->maxTtl)) {
            throw $loosened('cache time to live', $old->caching->maxTtl.' s', null === $new->caching->maxTtl ? 'no maximum' : $new->caching->maxTtl.' s');
        }
        if ($old->attribution->required && !$new->attribution->required) {
            throw $loosened('attribution', 'required', 'not required');
        }
        foreach (['attribution text' => [$old->attribution->text, $new->attribution->text], 'attribution link' => [$old->attribution->url, $new->attribution->url], 'licence' => [$old->licence, $new->licence], 'User-Agent pattern' => [$this->requirements->userAgentPattern, $tightened->requirements->userAgentPattern]] as $field => [$declared, $given]) {
            if (null !== $declared && $declared !== $given) {
                throw $loosened($field, '"'.$declared.'"', null === $given ? 'none' : '"'.$given.'"');
            }
        }
        foreach (['key' => [$this->requirements->key, $tightened->requirements->key], 'User-Agent' => [$this->requirements->userAgent, $tightened->requirements->userAgent]] as $field => [$declared, $given]) {
            if ($declared && !$given) {
                throw $loosened($field.' requirement', 'required', 'not required');
            }
        }
    }
}
