<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Alerts\HealthCheck;

/**
 * `2024_01_01_000005_add_identity_hash_to_health_checks_table`: a nullable identity with a
 * unique index, on whatever engine the leg configured. NULLs never collide, so every row
 * without an identity — on-demand, other checks, soft-deleted — coexists.
 */
it('adds a nullable unique identity to the health checks', function (): void {
    expect(Schema::hasColumn('health_checks', 'identity_hash'))->toBeTrue()
        ->and(Schema::hasIndex('health_checks', 'health_checks_identity_hash_unique', 'unique'))->toBeTrue();

    createHealthCheckWithNotifiable();
    createHealthCheckWithNotifiable();

    $held = createHealthCheckWithNotifiable();
    $held->forceFill(['identity_hash' => str_repeat('a', 64)])->save();

    expect(HealthCheck::query()->whereNull('identity_hash')->count())->toBe(2)
        ->and(fn () => createHealthCheckWithNotifiable()->forceFill(['identity_hash' => str_repeat('a', 64)])->save())
        ->toThrow(UniqueConstraintViolationException::class);
});
