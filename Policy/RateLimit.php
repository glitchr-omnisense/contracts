<?php

namespace Omnisense\Contracts\Policy;

/**
 * At most $max calls in a window, counted per scope, for some operations
 * (the family's contract methods: "journeys", "departures"...) or all of
 * them when none is named.
 */
final readonly class RateLimit
{
    /** @var list<string> */
    public array $operations;

    /**
     * @param list<string> $operations the operations it counts; empty: every one
     * @param ?string      $note       what the terms say beside the figure ("more is heavy use: ask first")
     */
    public function __construct(
        public int $max,
        public Window $window,
        public Scope $scope = Scope::Application,
        array $operations = [],
        public ?string $note = null,
    ) {
        if ($max < 0) {
            throw InvalidPolicyException::field('rateLimits[].max', 'an integer of 0 or more', $max);
        }
        $operations = array_values(array_unique($operations));
        sort($operations);
        $this->operations = $operations;
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

    public function appliesTo(string $operation): bool
    {
        return [] === $this->operations || \in_array($operation, $this->operations, true);
    }

    /** Whether it counts the same calls as another: the same window, scope and operations. */
    public function sameCountAs(self $other): bool
    {
        return $this->window === $other->window && $this->scope === $other->scope && $this->operations === $other->operations;
    }

    /** Its name in a limiter's store: "720/day/application/journeys". */
    public function id(): string
    {
        return $this->max.'/'.$this->window->value.'/'.$this->scope->value.'/'.([] === $this->operations ? '*' : implode('+', $this->operations));
    }

    /** "720 a day for journeys" */
    public function describe(): string
    {
        return $this->max.' a '.$this->window->value.([] === $this->operations ? '' : ' for '.implode(', ', $this->operations)).(Scope::Application === $this->scope ? '' : ' per '.$this->scope->value);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path = 'rateLimits[]'): self
    {
        Read::only($data, ['max', 'window', 'scope', 'operations', 'note'], $path);
        $max = Read::int($data['max'] ?? null, $path.'.max') ?? throw InvalidPolicyException::field($path.'.max', 'an integer of 0 or more', null);

        return new self(
            $max,
            Read::enum($data['window'] ?? throw InvalidPolicyException::field($path.'.window', 'a window', null), $path.'.window', Window::class, Window::Day),
            Read::enum($data['scope'] ?? null, $path.'.scope', Scope::class, Scope::Application),
            Read::strings($data['operations'] ?? null, $path.'.operations'),
            Read::string($data['note'] ?? null, $path.'.note'),
        );
    }

    /** @return array{max: int, window: string, scope: string, operations: list<string>, note: ?string} */
    public function toArray(): array
    {
        return ['max' => $this->max, 'window' => $this->window->value, 'scope' => $this->scope->value, 'operations' => $this->operations, 'note' => $this->note];
    }
}
