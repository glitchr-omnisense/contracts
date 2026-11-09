<?php

namespace Omnisense\Contracts\Provider;

use Omnisense\Contracts\Exception\InvalidConfigException;
use Omnisense\Contracts\Policy\Backoff;

/**
 * A provider's configuration while its factory builds it: the options
 * given, the factory's defaults under them, and the keys that must be
 * filled. The keys a factory and the registry fill themselves carry the
 * family's prefix (PREFIX): "<prefix>.factory_name", "<prefix>.factory_title",
 * "<prefix>.required_options", "<prefix>.name" (the name it is configured
 * under), "<prefix>.limiter", "<prefix>.backoff". Two options belong to
 * every provider: "policy" (the site's tightening of its usage policy, in
 * the schema's shape) and "usage" (what the site says of itself:
 * commercial, open_source, contract).
 *
 * A family names it its own, with its prefix and its exception:
 *
 *   final class Config extends \Omnisense\Contracts\Provider\Config
 *   {
 *       public const PREFIX = 'omnitravel';
 *   }
 *
 * @extends \ArrayObject<string, mixed>
 */
class Config extends \ArrayObject
{
    /** @var string */
    public const PREFIX = 'provider';

    /** A key the factory or the registry fills: "omnitravel.factory_name". */
    public function key(string $name): string
    {
        return static::PREFIX.'.'.$name;
    }

    /**
     * Sets each key not set yet.
     *
     * @param array<string, mixed> $defaults
     */
    public function defaults(array $defaults): static
    {
        foreach ($defaults as $key => $value) {
            if (!$this->offsetExists($key)) {
                $this[$key] = $value;
            }
        }

        return $this;
    }

    /** @param list<string> $keys */
    public function validateNotEmpty(array $keys): void
    {
        $missing = array_values(array_filter($keys, fn (string $key) => !isset($this[$key]) || '' === $this[$key] || [] === $this[$key]));
        if ($missing) {
            throw $this->invalid(\sprintf('The "%s" provider needs: %s.', $this->string($this->key('factory_name')) ?? '?', implode(', ', $missing)));
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->offsetExists($key) ? $this[$key] : $default;
    }

    /** A string option; null when it is empty. */
    public function string(string $key): ?string
    {
        $value = $this->get($key);

        return \is_scalar($value) && '' !== (string) $value ? (string) $value : null;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->get($key);

        return is_numeric($value) ? (float) $value : $default;
    }

    /** A boolean option, as an environment variable gives it too: "1", "true", "yes", "on". */
    public function bool(string $key): bool
    {
        $value = $this->get($key);

        return \is_bool($value) ? $value : \is_scalar($value) && \in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * An option that must be a map when it is given ("policy", "usage").
     *
     * @return array<string, mixed>
     */
    public function map(string $key): array
    {
        $value = $this->get($key);
        if (null === $value) {
            return [];
        }
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            throw $this->invalid(\sprintf('The "%s" provider\'s "%s" option must be a map, %s given.', $this->string($this->key('factory_name')) ?? '?', $key, get_debug_type($value)));
        }
        $map = [];
        foreach ($value as $name => $one) {
            $map[(string) $name] = $one;
        }

        return $map;
    }

    /** The provider's wait, for its HTTP client (JsonClient's last argument); null outside a factory's create(). */
    public function backoff(): ?Backoff
    {
        $backoff = $this->get($this->key('backoff'));

        return $backoff instanceof Backoff ? $backoff : null;
    }

    /** The family's InvalidConfigException. */
    protected function invalid(string $message): \Throwable
    {
        return new InvalidConfigException($message);
    }
}
