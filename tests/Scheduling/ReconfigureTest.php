<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Alerts\DataTransferObjects\ReconfigureHealthCheckData;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\Exceptions\InvalidCronExpression;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Exceptions\ScheduleConflict;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Support\Identity;
use RoundlyConsulting\Alerts\Support\MonitorOptions;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ServiceCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

/**
 * `Health::for($owner)->reconfigure($row, $changes)` is the PATCH that merges: what the DTO
 * leaves null stays, `meta` merges key by key, and — as for `schedule()` — nothing is written
 * and the flap counters stay when nothing changes.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-10-10 12:00:00');
    Health::checks([ExampleHealthCheck::class, ServiceCheck::class]);

    $this->team = Team::create();
    $this->row = Health::for($this->team)->schedule(new ScheduleHealthCheckData(
        check: ServiceCheck::class,
        frequency: 'everyFiveMinutes',
        maxAttempts: 2,
        decayMinutes: 10,
        failAfter: 3,
        tags: ['db'],
        meta: ['service' => 'api', 'region' => 'eu'],
    ));
    $this->row->forceFill(['consecutive_failures' => 2, 'consecutive_successes' => 0])->save();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('keeps what the changes leave out and resets the counters on a change', function (): void {
    $row = Health::for($this->team)->reconfigure($this->row, new ReconfigureHealthCheckData(frequency: 'hourly', maxAttempts: 5));

    $fresh = $row->fresh();

    expect($row)->toBe($this->row)
        ->and($row->wasChanged())->toBeTrue()
        ->and($fresh?->frequency)->toBe('@hourly')
        ->and($fresh?->max_attempts)->toBe(5)
        ->and($fresh?->decay_minutes)->toBe(10)
        ->and($fresh?->tags)->toBe(['db'])
        ->and($fresh?->meta)->toEqualCanonicalizing(['service' => 'api', 'region' => 'eu', MonitorOptions::FAIL_AFTER => 3])
        ->and($fresh?->consecutive_failures)->toBe(0);
});

it('merges meta key by key, removing a key set to null', function (): void {
    Health::for($this->team)->reconfigure($this->row, new ReconfigureHealthCheckData(meta: ['region' => null, 'tier' => 2]));

    $meta = $this->row->fresh()?->meta;

    expect($meta)->toHaveCount(3)
        ->toMatchArray(['service' => 'api', 'tier' => 2, MonitorOptions::FAIL_AFTER => 3])
        ->not->toHaveKey('region');
});

it('removes the fail and recover thresholds set back to one', function (): void {
    Health::for($this->team)->reconfigure($this->row, new ReconfigureHealthCheckData(failAfter: 1, recoverAfter: 4));

    expect($this->row->fresh()?->meta)->not->toHaveKey(MonitorOptions::FAIL_AFTER)
        ->and($this->row->fresh()?->options()->recoverAfter())->toBe(4);
});

it('clears the tags with an empty list and keeps them with null', function (): void {
    Health::for($this->team)->reconfigure($this->row, new ReconfigureHealthCheckData(decayMinutes: 30));

    expect($this->row->fresh()?->tags)->toBe(['db']);

    Health::for($this->team)->reconfigure($this->row, new ReconfigureHealthCheckData(tags: []));

    expect($this->row->fresh()?->tags)->toBeNull();
});

it('writes nothing and keeps the counters when nothing changes', function (): void {
    Carbon::setTestNow('2026-10-10 13:00:00');

    $row = Health::for($this->team)->reconfigure($this->row->fresh(), new ReconfigureHealthCheckData(
        frequency: '*/5 * * * *',
        tags: ['db'],
        failAfter: 3,
        meta: ['region' => 'eu'],
    ));

    expect($row->wasChanged())->toBeFalse()
        ->and($row->fresh()?->consecutive_failures)->toBe(2)
        ->and($row->fresh()?->updated_at?->toDateTimeString())->toBe('2026-10-10 12:00:00');
});

it('moves the identity with the discriminator', function (): void {
    Health::for($this->team)->reconfigure($this->row, new ReconfigureHealthCheckData(meta: ['service' => 'web']));

    expect($this->row->fresh()?->identity_hash)
        ->toBe(Identity::schedule($this->team->getMorphClass(), $this->team->getKey(), 'service_check', ['service' => 'web']));

    $api = Health::for($this->team)->schedule(new ScheduleHealthCheckData(check: ServiceCheck::class, meta: ['service' => 'api']));

    expect($api->wasRecentlyCreated)->toBeTrue()
        ->and(HealthCheck::query()->count())->toBe(2);
});

it('refuses to move a schedule onto another live one, naming keys and never values', function (): void {
    Health::for($this->team)->schedule(new ScheduleHealthCheckData(check: ServiceCheck::class, meta: ['service' => 'web']));

    try {
        Health::for($this->team)->reconfigure($this->row, new ReconfigureHealthCheckData(maxAttempts: 9, meta: ['service' => 'web']));
        $this->fail('No conflict was raised.');
    } catch (ScheduleConflict $conflict) {
        expect($conflict->getMessage())
            ->toContain('[service_check]')
            ->toContain('#'.$this->row->getKey())
            ->toContain('service')
            ->not->toContain('web')
            ->not->toContain('api');
    }

    expect($this->row->fresh()?->meta['service'] ?? null)->toBe('api')
        ->and($this->row->fresh()?->max_attempts)->toBe(2)
        ->and($this->row->max_attempts)->toBe(2)
        ->and($this->row->isDirty())->toBeFalse();
});

it('takes the identity of a schedule soft-deleted straight through the model', function (): void {
    $web = Health::for($this->team)->schedule(new ScheduleHealthCheckData(check: ServiceCheck::class, meta: ['service' => 'web']));
    $web->delete();

    Health::for($this->team)->reconfigure($this->row, new ReconfigureHealthCheckData(meta: ['service' => 'web']));

    expect($this->row->fresh()?->meta['service'] ?? null)->toBe('web')
        ->and(HealthCheck::withTrashed()->find($web->getKey())?->identity_hash)->toBeNull();
});

it('refuses a row scheduled for another notifiable', function (): void {
    Health::for(Team::create())->reconfigure($this->row, new ReconfigureHealthCheckData(maxAttempts: 9));
})->throws(InvalidHealthCheck::class, 'is not scheduled for');

it('refuses an invalid cron or escalation when the changes are built', function (Closure $build, string $exception): void {
    expect($build)->toThrow($exception);
})->with([
    'cron' => [fn () => new ReconfigureHealthCheckData(frequency: '0 9 * * FUNDAY'), InvalidCronExpression::class],
    'escalation' => [fn () => new ReconfigureHealthCheckData(meta: [MonitorOptions::ESCALATION => [0 => 'owner']]), InvalidHealthCheck::class],
]);

it('keeps a check without uniqueBy free of an identity, through the manager too', function (): void {
    $row = Health::for($this->team)->monitor(ExampleHealthCheck::class)->save();

    app(HealthManager::class)->for($this->team)->reconfigure($row, new ReconfigureHealthCheckData(meta: ['service' => 'web']));

    expect($row->fresh()?->identity_hash)->toBeNull()
        ->and($row->fresh()?->meta)->toBe(['service' => 'web']);
});

it('keeps the identity of a row whose check is not registered here', function (): void {
    $hash = $this->row->identity_hash;
    $row = HealthCheck::query()->findOrFail($this->row->getKey());

    $registry = (new ReflectionProperty(HealthManager::class, 'checks'));
    $manager = app(HealthManager::class);
    $registry->setValue($manager, []);

    Health::for($this->team)->reconfigure($row, new ReconfigureHealthCheckData(maxAttempts: 4));

    expect($row->fresh()?->identity_hash)->toBe($hash)
        ->and($row->fresh()?->max_attempts)->toBe(4);
});
