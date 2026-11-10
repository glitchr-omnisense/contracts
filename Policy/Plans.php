<?php

namespace Omnisense\Contracts\Policy;

/**
 * The plans a provider publishes limits for, the "plans" member of its
 * policy:
 *
 *   "plans": {
 *     "default": "free",
 *     "plans": {
 *       "free": {"rateLimits": [{"max": 10, "window": "minute"}], "concurrency": 2, "source": "https://...", "verifiedAt": "2026-11-10"},
 *       "pro":  {"rateLimits": [{"max": 500, "window": "minute"}], "concurrency": 25, "source": "https://...", "verifiedAt": "2026-11-10"}
 *     }
 *   }
 *
 * The default is the plan that applies when the site names none - the free
 * or most restrictive one -, and the policy's own rateLimits and
 * concurrency are the default plan's, so that the policy reads the same
 * where plans are not known. A site picks its plan with the factory's
 * "plan" option (UsagePolicy::forPlan()), then may only tighten it.
 *
 * The merge (apply()): the plan's rateLimits, when it names them, replace
 * the policy's; its concurrency, when it names one, replaces the policy's;
 * the provenance's policy and verifiedAt become the plan's source and
 * date, and its notes say which plan applies. Rights, requirements and
 * terms are the provider's, whatever the plan.
 */
final readonly class Plans
{
    /** @var array<string, Plan> */
    public array $plans;

    /** @param array<string, Plan> $plans by name */
    public function __construct(public string $default, array $plans)
    {
        if ([] === $plans) {
            throw InvalidPolicyException::field('plans.plans', 'at least one plan', $plans);
        }
        if (!isset($plans[$default])) {
            throw new InvalidPolicyException(\sprintf('The default plan "%s" is not among the plans declared: %s.', $default, implode(', ', array_keys($plans))));
        }
        $this->plans = $plans;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path = 'plans'): self
    {
        Read::only($data, ['$schema', 'default', 'plans'], $path);
        $default = Read::string($data['default'] ?? null, $path.'.default') ?? throw InvalidPolicyException::field($path.'.default', 'the name of a plan', null);
        $plans = [];
        foreach (Read::object($data['plans'] ?? null, $path.'.plans') as $name => $plan) {
            $plans[$name] = Plan::fromArray(Read::object($plan, $path.'.plans.'.$name), $path.'.plans.'.$name);
        }

        return new self($default, $plans);
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidPolicyException('The plans are not JSON: '.$e->getMessage().'.', 0, $e);
        }

        return self::fromArray(Read::object($data, 'plans'));
    }

    /** A separate plans file (the omnicrawl shape before plans joined the policy's schema). */
    public static function fromFile(string $path): self
    {
        $json = is_file($path) ? file_get_contents($path) : false;

        return false === $json ? throw new InvalidPolicyException(\sprintf('No plans at "%s".', $path)) : self::fromJson($json);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->plans);
    }

    /** The plan's name, checked; the default's for null. */
    public function check(?string $plan): string
    {
        $plan ??= $this->default;

        return isset($this->plans[$plan]) ? $plan : throw new InvalidPolicyException(\sprintf('No "%s" plan; declared: %s.', $plan, implode(', ', $this->names())));
    }

    /** A plan by name; the default for null. */
    public function get(?string $plan = null): Plan
    {
        return $this->plans[$this->check($plan)];
    }

    /**
     * The policy under a plan (the default for null): its limits and
     * concurrency, its source and date. The policy returned names the plan
     * it runs (UsagePolicy::$plan) and declares no plans any more.
     */
    public function apply(UsagePolicy $policy, ?string $plan = null): UsagePolicy
    {
        $name = $this->check($plan);
        $chosen = $this->plans[$name];
        $p = $policy->provenance;
        $notes = trim(\sprintf('Plan "%s"%s, read at %s on %s. %s', $name, $name === $this->default ? ' (the default)' : '', $chosen->source, $chosen->verifiedAt, $chosen->note ?? '').' '.($p->notes ?? ''));

        return new UsagePolicy(
            $policy->provider,
            $chosen->rateLimits ?? $policy->rateLimits,
            $policy->rights,
            $policy->requirements,
            new Provenance($p->terms, $chosen->source, $chosen->verifiedAt, $p->declaredBy, $p->contact, $notes),
            $chosen->concurrency ?? $policy->concurrency,
            plan: $name,
        );
    }

    /** @return array{default: string, plans: array<string, array{rateLimits: ?list<array<string, mixed>>, concurrency: ?int, source: string, verifiedAt: string, note: ?string}>} the schema's shape */
    public function toArray(): array
    {
        return ['default' => $this->default, 'plans' => array_map(static fn (Plan $plan) => $plan->toArray(), $this->plans)];
    }
}
