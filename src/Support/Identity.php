<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Support;

/**
 * The one recipe for the identity hashes stored in `alert_silences.identity_hash` (and, for
 * `uniqueBy()` checks, `health_checks.identity_hash`). The unique index on each column is
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
