<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Support\Identity;

/**
 * Every stored `identity_hash` was computed with this recipe. Changing it would orphan them
 * all — the next `mute()` would insert beside the old row instead of updating it — so the
 * recipe is pinned to golden values.
 */
it('hashes a silence identity with the frozen recipe', function (): void {
    // sha256 of the JSON list ["alerts.silence", key, notifiable type, notifiable id as text].
    expect(Identity::silence('db', null, null))
        ->toBe('b778c7c1cb9f68eb5f51cbb5fe2289839d273ce1ee74f19382c6ba1dbe3b23dc')
        ->and(Identity::silence('db', 'team', 5))
        ->toBe('bfe2d14fa5c9ae44dac4ee8cda1be413e85fd7e09711f59f51ff47a8ad87dd32');
});

it('reads a notifiable id as text, so an int and a string key are one identity', function (): void {
    expect(Identity::silence('db', 'team', 5))->toBe(Identity::silence('db', 'team', '5'))
        ->and(Identity::silence('db', 'team', 5))->toHaveLength(64);
});

it('keeps the key and the scope in the identity', function (): void {
    expect(array_unique([
        Identity::silence('db', null, null),
        Identity::silence('cache', null, null),
        Identity::silence('db', 'team', 5),
        Identity::silence('db', 'team', 6),
        Identity::silence('db', 'user', 5),
    ]))->toHaveCount(5);
});
