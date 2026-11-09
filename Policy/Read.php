<?php

namespace Omnisense\Contracts\Policy;

/**
 * Typed reads of a decoded policy, each failure naming the field's path.
 *
 * @internal
 */
final class Read
{
    /**
     * @param array<string, mixed> $data
     * @param list<string>         $allowed
     */
    public static function only(array $data, array $allowed, string $path): void
    {
        foreach (array_keys($data) as $key) {
            if (!\in_array($key, $allowed, true)) {
                throw new InvalidPolicyException(\sprintf('The usage policy\'s "%s" has no "%s": %s.', $path, $key, implode(', ', $allowed)));
            }
        }
    }

    /** @return array<string, mixed> */
    public static function object(mixed $value, string $path): array
    {
        if (null === $value) {
            return [];
        }
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            throw InvalidPolicyException::field($path, 'an object', $value);
        }
        $object = [];
        foreach ($value as $key => $one) {
            $object[(string) $key] = $one;
        }

        return $object;
    }

    public static function string(mixed $value, string $path): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return \is_string($value) ? $value : throw InvalidPolicyException::field($path, 'a string', $value);
    }

    public static function bool(mixed $value, string $path, bool $default = false): bool
    {
        if (null === $value) {
            return $default;
        }

        return \is_bool($value) ? $value : throw InvalidPolicyException::field($path, 'true or false', $value);
    }

    public static function nullableBool(mixed $value, string $path): ?bool
    {
        return null === $value ? null : self::bool($value, $path);
    }

    public static function int(mixed $value, string $path, int $min = 0): ?int
    {
        if (null === $value) {
            return null;
        }
        if (!\is_int($value) || $value < $min) {
            throw InvalidPolicyException::field($path, 'an integer of '.$min.' or more', $value);
        }

        return $value;
    }

    /** @return list<string> */
    public static function strings(mixed $value, string $path): array
    {
        if (null === $value) {
            return [];
        }
        if (!\is_array($value) || !array_is_list($value)) {
            throw InvalidPolicyException::field($path, 'a list of strings', $value);
        }
        $strings = [];
        foreach ($value as $i => $one) {
            $strings[] = \is_string($one) && '' !== $one ? $one : throw InvalidPolicyException::field($path.'['.$i.']', 'a string', $one);
        }

        return $strings;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     * @param T               $default
     *
     * @return T
     */
    public static function enum(mixed $value, string $path, string $enum, \BackedEnum $default): \BackedEnum
    {
        if (null === $value) {
            return $default;
        }
        $case = \is_string($value) ? $enum::tryFrom($value) : null;

        return $case ?? throw InvalidPolicyException::field($path, 'one of "'.implode('", "', array_map(static fn (\BackedEnum $case) => $case->value, $enum::cases())).'"', $value);
    }
}
