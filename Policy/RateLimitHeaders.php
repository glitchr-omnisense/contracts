<?php

namespace Omnisense\Contracts\Policy;

/**
 * The fields a provider says its limits in, read as they are specified:
 *
 * - Retry-After (RFC 9110, section 10.2.3): delay-seconds or an HTTP-date;
 * - RateLimit and RateLimit-Policy (IETF draft-ietf-httpapi-ratelimit-headers-11,
 *   2026-05-23): Structured Field lists, an item per policy with its
 *   parameters - RateLimit's r (quota left) and t (seconds until the window
 *   ends), RateLimit-Policy's q (quota), w (window in seconds) and qu (unit):
 *
 *     RateLimit-Policy: "burst";q=100;w=60,"daily";q=1000;w=86400
 *     RateLimit: "default";r=50;t=30
 *
 * Nothing else is guessed: the X-RateLimit-* fields some APIs send differ
 * from one API to the next (a reset in seconds or as a timestamp) and are
 * not read.
 */
final class RateLimitHeaders
{
    /**
     * The seconds to wait a response asks for: its Retry-After when it is a
     * 429 or a 503 (Retry-After prevails over RateLimit, the draft's section
     * 7), else the longest t of a RateLimit item with nothing left (r=0).
     *
     * @param array<string, string|list<string>> $headers
     */
    public static function wait(int $status, array $headers, ?int $now = null): ?int
    {
        if (\in_array($status, [429, 503], true) && null !== $after = self::retryAfter(self::header($headers, 'retry-after'), $now)) {
            return $after;
        }
        $wait = null;
        foreach (self::rateLimit(self::header($headers, 'ratelimit')) as $item) {
            if (0 === ($item['r'] ?? null)) {
                $wait = max($wait ?? 0, \is_int($item['t'] ?? null) ? $item['t'] : 0);
            }
        }

        return $wait;
    }

    /** Retry-After's seconds: delay-seconds as they are, an HTTP-date from now; null when absent or unreadable. */
    public static function retryAfter(?string $value, ?int $now = null): ?int
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return null;
        }
        if (1 === preg_match('~^\d+$~', $value)) {
            return (int) $value;
        }
        $date = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone('UTC'));

        return false === $date ? null : max(0, $date->getTimestamp() - ($now ?? time()));
    }

    /**
     * RateLimit's items: each policy's name and its integer parameters.
     *
     * @return list<array<string, string|int>> ["policy" => "default", "r" => 50, "t" => 30]
     */
    public static function rateLimit(?string $value): array
    {
        return self::items($value);
    }

    /** @return list<array<string, string|int>> ["policy" => "daily", "q" => 1000, "w" => 86400] */
    public static function rateLimitPolicy(?string $value): array
    {
        return self::items($value);
    }

    /**
     * A Structured Field list (RFC 9651) of string items with parameters,
     * read for what these fields carry: the item's string, integer
     * parameters, string and token ones; byte sequences (pk) kept as they are
     * written.
     *
     * @return list<array<string, string|int>>
     */
    private static function items(?string $value): array
    {
        $items = [];
        foreach (self::split((string) $value, ',') as $member) {
            $parts = self::split($member, ';');
            $name = trim(array_shift($parts) ?? '');
            if ('' === $name) {
                continue;
            }
            $item = ['policy' => trim($name, '"')];
            foreach ($parts as $parameter) {
                [$key, $raw] = array_map('trim', explode('=', $parameter, 2)) + [1 => '?1'];
                if (1 !== preg_match('~^[a-z*][a-z0-9_.*-]*$~', $key)) {
                    continue;
                }
                $item[$key] = 1 === preg_match('~^-?\d{1,15}$~', $raw) ? (int) $raw : trim($raw, '"');
            }
            $items[] = $item;
        }

        return $items;
    }

    /** @return list<string> the parts between separators outside quoted strings */
    private static function split(string $value, string $separator): array
    {
        $parts = [];
        $current = '';
        $quoted = false;
        for ($i = 0, $n = \strlen($value); $i < $n; ++$i) {
            $char = $value[$i];
            if ('\\' === $char && $quoted && $i + 1 < $n) {
                $current .= $char.$value[++$i];
                continue;
            }
            if ('"' === $char) {
                $quoted = !$quoted;
            } elseif ($char === $separator && !$quoted) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;

        return array_values(array_filter($parts, static fn (string $part) => '' !== trim($part)));
    }

    /** @param array<string, string|list<string>> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (0 === strcasecmp($key, $name)) {
                return \is_array($value) ? implode(', ', $value) : $value;
            }
        }

        return null;
    }
}
