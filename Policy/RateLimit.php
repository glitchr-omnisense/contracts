<?php

namespace Omnisense\Contracts\Policy;

/**
 * At most $max of a unit in a window, counted per scope, for some
 * operations (the family's contract methods: "journeys", "departures"...)
 * or all of them when none is named.
 *
 * The window is $every times $window: 500 every 5 minutes is
 * (500, Window::Minute, every: 5). The unit is the provider's HTTP requests
 * by default; $cost says what one operation consumes (3 requests, 100 quota
 * units), 1 for an operation it does not name, 0 for one that is free.
 *
 *   {"max": 10000, "window": "day", "unit": "units", "cost": {"searchVideos": 100}}
 *   {"max": 500, "window": "minute", "every": 5}
 */
final readonly class RateLimit
{
    /** @var list<string> */
    public array $operations;

    /** @var array<string, int> by operation */
    public array $cost;

    /**
     * @param list<string>       $operations the operations it counts; empty: every one
     * @param ?string            $note       what the terms say beside the figure ("more is heavy use: ask first")
     * @param int                $every      the windows it spans: 5 minutes is Window::Minute, every 5
     * @param array<string, int> $cost       what one call of an operation consumes, in $unit; 1 when not named
     */
    public function __construct(
        public int $max,
        public Window $window,
        public Scope $scope = Scope::Application,
        array $operations = [],
        public ?string $note = null,
        public int $every = 1,
        public Unit $unit = Unit::Requests,
        array $cost = [],
    ) {
        if ($max < 0) {
            throw InvalidPolicyException::field('rateLimits[].max', 'an integer of 0 or more', $max);
        }
        if ($every < 1) {
            throw InvalidPolicyException::field('rateLimits[].every', 'an integer of 1 or more', $every);
        }
        foreach ($cost as $operation => $amount) {
            if ('' === $operation || $amount < 0) {
                throw InvalidPolicyException::field('rateLimits[].cost', 'an object of operations to integers of 0 or more', $cost);
            }
        }
        $operations = array_values(array_unique($operations));
        sort($operations);
        $this->operations = $operations;
        ksort($cost);
        $this->cost = $cost;
    }

    public static function perSecond(int $max, string ...$operations): self
    {
        return new self($max, Window::Second, Scope::Application, array_values($operations));
    }

    public static function perMinute(int $max, string ...$operations): self
    {
        return new self($max, Window::Minute, Scope::Application, array_values($operations));
    }

    public static function perHour(int $max, string ...$operations): self
    {
        return new self($max, Window::Hour, Scope::Application, array_values($operations));
    }

    public static function perDay(int $max, string ...$operations): self
    {
        return new self($max, Window::Day, Scope::Application, array_values($operations));
    }

    public static function perMonth(int $max, string ...$operations): self
    {
        return new self($max, Window::Month, Scope::Application, array_values($operations));
    }

    /** The same limit over $every windows: RateLimit::perMinute(500)->every(5) is 500 every 5 minutes. */
    public function every(int $every): self
    {
        return new self($this->max, $this->window, $this->scope, $this->operations, $this->note, $every, $this->unit, $this->cost);
    }

    /**
     * The same limit counted in another unit, each operation costing what
     * $cost says (1 when not named): RateLimit::perDay(10000)->counting(Unit::Units, ['searchVideos' => 100]).
     *
     * @param array<string, int> $cost
     */
    public function counting(Unit $unit, array $cost = []): self
    {
        return new self($this->max, $this->window, $this->scope, $this->operations, $this->note, $this->every, $unit, $cost);
    }

    public function appliesTo(string $operation): bool
    {
        return [] === $this->operations || \in_array($operation, $this->operations, true);
    }

    /** What one call of an operation consumes, in the limit's unit: its cost, 1 when not named. */
    public function cost(string $operation): int
    {
        return $this->cost[$operation] ?? 1;
    }

    /** The window's length in seconds: $every times the window's. */
    public function seconds(): int
    {
        return $this->window->seconds() * $this->every;
    }

    /** Whether it counts the same calls as another, in the same way: the same window, scope, operations, unit and costs. */
    public function sameCountAs(self $other): bool
    {
        return $this->window === $other->window && $this->every === $other->every && $this->scope === $other->scope
            && $this->operations === $other->operations && $this->unit === $other->unit && $this->cost === $other->cost;
    }

    /**
     * Its name in a limiter's store: "720/day/application/journeys"; a
     * multiple of the window, a unit and costs are written only when given
     * ("500/5minute/application/*", "10000/day/application/live/units/live=3").
     */
    public function id(): string
    {
        $costs = implode(',', array_map(static fn (string $operation, int $amount) => $operation.'='.$amount, array_keys($this->cost), $this->cost));

        return $this->max.'/'.(1 === $this->every ? '' : $this->every).$this->window->value.'/'.$this->scope->value.'/'.([] === $this->operations ? '*' : implode('+', $this->operations))
            .(Unit::Requests === $this->unit ? '' : '/'.$this->unit->value).('' === $costs ? '' : '/'.$costs);
    }

    /** "720 a day for journeys", "1200 an hour", "500 every 5 minutes", "10000 units a day (live costs 3)" */
    public function describe(): string
    {
        $costs = implode(', ', array_map(static fn (string $operation, int $amount) => $operation.' costs '.$amount, array_keys($this->cost), $this->cost));

        return $this->max.(Unit::Requests === $this->unit ? '' : ' '.$this->unit->value)
            .(1 === $this->every ? (Window::Hour === $this->window ? ' an ' : ' a ').$this->window->value : ' every '.$this->every.' '.$this->window->value.'s')
            .([] === $this->operations ? '' : ' for '.implode(', ', $this->operations))
            .(Scope::Application === $this->scope ? '' : ' per '.$this->scope->value)
            .('' === $costs ? '' : ' ('.$costs.')');
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path = 'rateLimits[]'): self
    {
        Read::only($data, ['max', 'window', 'every', 'scope', 'operations', 'unit', 'cost', 'note'], $path);
        $max = Read::int($data['max'] ?? null, $path.'.max') ?? throw InvalidPolicyException::field($path.'.max', 'an integer of 0 or more', null);
        $cost = [];
        foreach (Read::object($data['cost'] ?? null, $path.'.cost') as $operation => $amount) {
            $cost[$operation] = '' !== $operation ? Read::int($amount, $path.'.cost.'.$operation) ?? throw InvalidPolicyException::field($path.'.cost.'.$operation, 'an integer of 0 or more', null) : throw InvalidPolicyException::field($path.'.cost', 'an object of operations to integers', $data['cost']);
        }

        return new self(
            $max,
            Read::enum($data['window'] ?? throw InvalidPolicyException::field($path.'.window', 'a window', null), $path.'.window', Window::class, Window::Day),
            Read::enum($data['scope'] ?? null, $path.'.scope', Scope::class, Scope::Application),
            Read::strings($data['operations'] ?? null, $path.'.operations'),
            Read::string($data['note'] ?? null, $path.'.note'),
            Read::int($data['every'] ?? null, $path.'.every', 1) ?? 1,
            Read::enum($data['unit'] ?? null, $path.'.unit', Unit::class, Unit::Requests),
            $cost,
        );
    }

    /**
     * The schema's shape; "cost" null when every operation costs 1.
     *
     * @return array{max: int, window: string, every: int, scope: string, operations: list<string>, unit: string, cost: ?array<string, int>, note: ?string}
     */
    public function toArray(): array
    {
        return ['max' => $this->max, 'window' => $this->window->value, 'every' => $this->every, 'scope' => $this->scope->value, 'operations' => $this->operations, 'unit' => $this->unit->value, 'cost' => [] === $this->cost ? null : $this->cost, 'note' => $this->note];
    }
}
