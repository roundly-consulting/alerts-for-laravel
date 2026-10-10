<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;

/**
 * The one recipe for the identity hashes stored in `alert_silences.identity_hash` and, for
 * `uniqueBy()` checks, `health_checks.identity_hash`. The unique index on each column is
 * what makes `mute()` and `schedule()` idempotent and race-safe.
 *
 * The recipe is frozen: every stored hash was computed with it, and a change would orphan
 * them all — the next `mute()` would insert beside the old row instead of updating it. A
 * golden-hash test pins it. It is an identity digest, not cryptography: nothing is signed or
 * kept secret, and the input is readable from the row itself.
 *
 * @internal
 */
final class Identity
{
    /**
     * An immediate silence (`starts_at` null, all `mute()` ever writes): its key and its scope,
     * the notifiable id read as text so an int and a string key are one identity.
     */
    public static function silence(string $key, ?string $notifiableType, mixed $notifiableId): string
    {
        return self::hash(['alerts.silence', $key, $notifiableType, self::text($notifiableId)]);
    }

    /**
     * A live schedule of a `uniqueBy()` check: its notifiable (the id read as text), the check
     * key and the discriminator values {@see self::discriminators()} picked.
     *
     * @param  array<string, string|null>  $discriminators
     */
    public static function schedule(string $notifiableType, mixed $notifiableId, string $check, array $discriminators): string
    {
        ksort($discriminators, SORT_STRING);

        $pairs = [];

        foreach ($discriminators as $metaKey => $value) {
            $pairs[] = [(string) $metaKey, $value];
        }

        return self::hash(['alerts.schedule', $notifiableType, self::text($notifiableId), $check, $pairs]);
    }

    /**
     * The values a check's `uniqueBy()` meta keys pick out of a schedule's meta, sorted by key:
     * absent is null, a scalar is read as text the way the database reads a JSON value
     * (`true` / `false` for a boolean), so `7` and `'7'` are one identity.
     *
     * @param  list<string>  $metaKeys
     * @param  array<array-key, mixed>|null  $meta
     * @return array<string, string|null>
     *
     * @throws InvalidHealthCheck for a value that is not a scalar or null
     */
    public static function discriminators(string $check, array $metaKeys, ?array $meta): array
    {
        $values = [];

        foreach ($metaKeys as $metaKey) {
            $value = $meta[$metaKey] ?? null;

            $values[$metaKey] = match (true) {
                $value === null => null,
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                default => throw InvalidHealthCheck::invalidIdentity($check, $metaKey),
            };
        }

        ksort($values, SORT_STRING);

        return $values;
    }

    /**
     * @param  list<mixed>  $parts
     */
    private static function hash(array $parts): string
    {
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
