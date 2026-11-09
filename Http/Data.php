<?php

namespace Omnisense\Contracts\Http;

/**
 * A decoded JSON answer, read by key without trusting its shape: each
 * reader gives its type or null (an empty list), whatever the provider sent.
 * A key may be a path: "release_date.date", "credits.cast". A family may
 * name it its own (final readonly class Data extends this one): every reader
 * gives back the class it was called on.
 */
readonly class Data
{
    /** @param array<mixed> $values */
    final public function __construct(private array $values)
    {
    }

    public static function of(mixed $value): static
    {
        return new static(\is_array($value) ? $value : []);
    }

    public function get(string|int $key): mixed
    {
        if (\is_int($key) || \array_key_exists($key, $this->values)) {
            return $this->values[$key] ?? null;
        }
        $value = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!\is_array($value) || !\array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    public function has(string|int $key): bool
    {
        return null !== $this->get($key);
    }

    /** A string, a number as a string; null when empty or absent. */
    public function string(string|int $key): ?string
    {
        $value = $this->get($key);
        if (\is_string($value) || \is_int($value) || \is_float($value)) {
            $value = trim((string) $value);

            return '' === $value ? null : $value;
        }

        return null;
    }

    public function int(string|int $key): ?int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : null;
    }

    public function float(string|int $key): ?float
    {
        $value = $this->get($key);

        return is_numeric($value) ? (float) $value : null;
    }

    public function bool(string|int $key): ?bool
    {
        $value = $this->get($key);

        return \is_bool($value) ? $value : (\is_int($value) ? 0 !== $value : null);
    }

    /** A nested object (an empty one when absent). */
    public function data(string|int $key): static
    {
        return static::of($this->get($key));
    }

    /**
     * The objects of a list (what is not an object is skipped).
     *
     * @return list<static>
     */
    public function list(string|int $key): array
    {
        $value = $this->get($key);

        return \is_array($value) ? array_values(array_map(static::of(...), array_filter($value, 'is_array'))) : [];
    }

    /**
     * The objects of this list itself (a provider answering a JSON array).
     *
     * @return list<static>
     */
    public function items(): array
    {
        return array_values(array_map(static::of(...), array_filter($this->values, 'is_array')));
    }

    /**
     * The strings of a list, or the field named in each of its objects.
     *
     * @return list<string>
     */
    public function strings(string|int $key, ?string $field = null): array
    {
        $value = $this->get($key);
        if (!\is_array($value)) {
            return [];
        }
        $strings = [];
        foreach ($value as $item) {
            $item = null === $field ? static::of([$item])->string(0) : static::of($item)->string($field);
            if (null !== $item) {
                $strings[] = $item;
            }
        }

        return array_values(array_unique($strings));
    }

    /** @return list<string|int> */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    /** @return array<mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    /** A moment ("2009-10-25T06:57:33Z", "2026-11-09T20:00:00+01:00"), in UTC; null when unreadable. */
    public function dateTime(string|int $key): ?\DateTimeImmutable
    {
        $value = $this->string($key);
        if (null === $value || !preg_match('~^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}~', $value)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * A duration in seconds: an ISO 8601 duration ("PT3M33S", "PT1H2M",
     * "P1DT2H", "P0D") or a number of seconds; null when unreadable.
     */
    public function duration(string|int $key): ?int
    {
        $value = $this->get($key);
        if (\is_int($value) || \is_float($value) || (\is_string($value) && is_numeric($value))) {
            return (int) $value;
        }
        if (!\is_string($value) || !preg_match('~^P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)(?:\.\d+)?S)?)?$~', trim($value), $m) || 'P' === trim($value) || str_ends_with(trim($value), 'T')) {
            return null;
        }

        return (int) ($m[1] ?? 0) * 604800 + (int) ($m[2] ?? 0) * 86400 + (int) ($m[3] ?? 0) * 3600 + (int) ($m[4] ?? 0) * 60 + (int) ($m[5] ?? 0);
    }

    /** A date ("2011-04-17", "2011-04-17T00:00:00Z", or the format given); null when unreadable. */
    public function date(string|int $key, ?string $format = null): ?\DateTimeImmutable
    {
        $value = $this->string($key);
        if (null === $value) {
            return null;
        }
        $date = null === $format ? date_create_immutable(substr($value, 0, 10).' 00:00:00', new \DateTimeZone('UTC')) : \DateTimeImmutable::createFromFormat('!'.$format, $value, new \DateTimeZone('UTC'));

        return $date ?: null;
    }
}
