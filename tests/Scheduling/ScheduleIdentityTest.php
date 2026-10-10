<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Support\Identity;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ServiceCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\SingletonCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

/**
 * A check that declares `uniqueBy()` is scheduled once per identity — the notifiable, the
 * check and the named meta values: `schedule()` updates that schedule instead of stacking
 * another, writes only what differs, and resets the flap counters only when something did.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-10-10 12:00:00');
    Health::checks([ExampleHealthCheck::class, ServiceCheck::class, SingletonCheck::class]);

    $this->team = Team::create();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function scheduleService(Team $team, ?string $service, array $options = []): HealthCheck
{
    return Health::for($team)->schedule(new ScheduleHealthCheckData(...[
        'check' => ServiceCheck::class,
        'meta' => $service === null ? [] : ['service' => $service],
        ...$options,
    ]));
}

it('keeps one schedule per service', function (): void {
    $first = scheduleService($this->team, 'api');
    $second = scheduleService($this->team, 'api');
    $other = scheduleService($this->team, 'worker');

    expect(HealthCheck::query()->count())->toBe(2)
        ->and($second->getKey())->toBe($first->getKey())
        ->and($first->wasRecentlyCreated)->toBeTrue()
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and($other->getKey())->not->toBe($first->getKey())
        ->and($first->fresh()?->identity_hash)
        ->toBe(Identity::schedule($this->team->getMorphClass(), $this->team->getKey(), 'service_check', ['service' => 'api']));
});

it('reads an absent discriminator as null', function (): void {
    $absent = scheduleService($this->team, null);
    $null = Health::for($this->team)->schedule(new ScheduleHealthCheckData(check: ServiceCheck::class, meta: ['service' => null]));

    expect($null->getKey())->toBe($absent->getKey())
        ->and(HealthCheck::query()->count())->toBe(1);
});

it('keeps one schedule per notifiable and check for an empty uniqueBy', function (): void {
    Health::for($this->team)->schedule(new ScheduleHealthCheckData(check: SingletonCheck::class, meta: ['service' => 'api']));
    Health::for($this->team)->schedule(new ScheduleHealthCheckData(check: SingletonCheck::class, meta: ['service' => 'worker']));
    Health::for(Team::create())->schedule(new ScheduleHealthCheckData(check: SingletonCheck::class));

    expect(HealthCheck::query()->count())->toBe(2)
        ->and(HealthCheck::query()->oldest('id')->firstOrFail()->meta)->toBe(['service' => 'worker']);
});

it('keeps inserting for a check without uniqueBy', function (): void {
    Health::for($this->team)->monitor(ExampleHealthCheck::class)->meta(['service' => 'api'])->save();
    Health::for($this->team)->monitor(ExampleHealthCheck::class)->meta(['service' => 'api'])->save();

    expect(HealthCheck::query()->count())->toBe(2)
        ->and(HealthCheck::query()->whereNotNull('identity_hash')->count())->toBe(0);
});

it('writes nothing and keeps the counters for an identical schedule', function (): void {
    $row = scheduleService($this->team, 'api', ['failAfter' => 3, 'tags' => ['a', 'b']]);
    $row->forceFill(['consecutive_failures' => 2])->save();

    Carbon::setTestNow('2026-10-10 13:00:00');

    $again = scheduleService($this->team, 'api', ['failAfter' => 3, 'tags' => ['b', 'a']]);

    expect($again->wasChanged())->toBeFalse()
        ->and($again->wasRecentlyCreated)->toBeFalse()
        ->and($again->consecutive_failures)->toBe(2)
        ->and($again->fresh()?->updated_at?->toDateTimeString())->toBe('2026-10-10 12:00:00');
});

it('compares meta whatever order the engine stores its keys in', function (): void {
    Health::for($this->team)->schedule(new ScheduleHealthCheckData(check: ServiceCheck::class, meta: ['service' => 'api', 'region' => 'eu', 'limits' => ['b' => 2, 'a' => 1]]));

    $again = Health::for($this->team)->schedule(new ScheduleHealthCheckData(check: ServiceCheck::class, meta: ['limits' => ['a' => 1, 'b' => 2], 'region' => 'eu', 'service' => 'api']));

    expect($again->wasChanged())->toBeFalse();
});

it('replaces what changed and resets the flap counters', function (): void {
    $row = scheduleService($this->team, 'api', ['failAfter' => 3, 'tags' => ['a']]);
    $row->forceFill(['consecutive_failures' => 2, 'consecutive_successes' => 0])->save();

    $again = scheduleService($this->team, 'api', ['failAfter' => 5, 'frequency' => 'hourly']);

    expect($again->getKey())->toBe($row->getKey())
        ->and($again->wasChanged())->toBeTrue()
        ->and(array_keys($again->getChanges()))->toContain('frequency', 'meta', 'tags', 'consecutive_failures')
        ->and($again->getChanges())->not->toHaveKey('max_attempts')
        ->and($again->getPrevious()['frequency'] ?? null)->toBe('* * * * *')
        ->and($again->fresh()?->frequency)->toBe('@hourly')
        ->and($again->fresh()?->tags)->toBeNull()
        ->and($again->fresh()?->options()->failAfter())->toBe(5)
        ->and($again->fresh()?->consecutive_failures)->toBe(0);
});

it('schedules one row per notifiable', function (): void {
    scheduleService($this->team, 'api');
    scheduleService(Team::create(), 'api');

    expect(HealthCheck::query()->count())->toBe(2);
});

it('upserts through the fluent builder and the trait helpers', function (): void {
    Health::for($this->team)->monitor(ServiceCheck::class)->meta(['service' => 'api'])->save();
    $this->team->monitorCheck(ServiceCheck::class)->meta(['service' => 'api'])->hourly()->save();
    $this->team->monitor(new ScheduleHealthCheckData(check: 'service_check', meta: ['service' => 'api']));
    $this->team->createHealthCheck('service_check', '@daily', meta: ['service' => 'api']);

    expect(HealthCheck::query()->count())->toBe(1)
        ->and(HealthCheck::query()->sole()->frequency)->toBe('@daily');
});

it('upserts an inline check that declares its identity', function (): void {
    Health::define('ping', fn (): bool => true)->uniqueBy(['service']);

    Health::for($this->team)->monitor('ping')->meta(['service' => 'api'])->save();
    Health::for($this->team)->monitor('ping')->meta(['service' => 'api'])->save();
    Health::for($this->team)->monitor('ping')->meta(['service' => 'web'])->save();

    expect(HealthCheck::query()->count())->toBe(2)
        ->and(Health::find('ping')?->uniqueBy())->toBe(['service']);
});

it('refuses a discriminator that is not a scalar, naming the key and never the value', function (): void {
    expect(fn () => Health::for($this->team)->schedule(new ScheduleHealthCheckData(
        check: ServiceCheck::class,
        meta: ['service' => ['secret-host']],
    )))->toThrow(InvalidHealthCheck::class, 'Health check [service_check] is unique by meta [service], which must be a scalar or null');

    expect(HealthCheck::query()->count())->toBe(0);
});

it('adopts the oldest matching schedule written before 1.2', function (): void {
    $legacy = fn (array $attributes): HealthCheck => HealthCheck::create([
        'notifiable_type' => $this->team->getMorphClass(),
        'notifiable_id' => $this->team->getKey(),
        'health_check' => 'service_check',
        'frequency' => '* * * * *',
        ...$attributes,
    ]);

    $onDemand = $legacy(['frequency' => null, 'meta' => ['service' => '7']]);
    $otherService = $legacy(['meta' => ['service' => 'worker']]);
    $nonScalar = $legacy(['meta' => ['service' => ['7']]]);
    $oldest = $legacy(['meta' => ['service' => 7]]);
    $newer = $legacy(['meta' => ['service' => '7']]);

    $row = scheduleService($this->team, '7');

    expect($row->getKey())->toBe($oldest->getKey())
        ->and($row->wasRecentlyCreated)->toBeFalse()
        ->and($oldest->fresh()?->identity_hash)->not->toBeNull()
        ->and($newer->fresh()?->identity_hash)->toBeNull()
        ->and($onDemand->fresh()?->identity_hash)->toBeNull()
        ->and($otherService->fresh()?->identity_hash)->toBeNull()
        ->and($nonScalar->fresh()?->identity_hash)->toBeNull()
        ->and(HealthCheck::query()->count())->toBe(5);
});

it('frees the identity when a schedule is unmonitored, so it can be scheduled again', function (): void {
    $first = scheduleService($this->team, 'api');

    expect(Health::for($this->team)->unmonitor($first))->toBe(1);

    $second = scheduleService($this->team, 'api');
    scheduleService($this->team, 'web');
    Health::for($this->team)->unmonitor(ServiceCheck::class);

    expect($second->getKey())->not->toBe($first->getKey())
        ->and(HealthCheck::withTrashed()->whereNotNull('identity_hash')->count())->toBe(0)
        ->and(HealthCheck::withTrashed()->count())->toBe(3)
        ->and($first->identity_hash)->toBeNull();
});

it('releases the identity of a schedule soft-deleted straight through the model', function (): void {
    $first = scheduleService($this->team, 'api');
    $first->delete();

    $second = scheduleService($this->team, 'api');

    expect($second->getKey())->not->toBe($first->getKey())
        ->and($second->wasRecentlyCreated)->toBeTrue()
        ->and(HealthCheck::withTrashed()->find($first->getKey())?->identity_hash)->toBeNull();
});

it('pins the schedule identity recipe', function (): void {
    // sha256 of ["alerts.schedule", type, id as text, check, [[meta key, value as text], …]].
    expect(Identity::schedule('team', 5, 'service_check', ['service' => 'api']))
        ->toBe(hash('sha256', '["alerts.schedule","team","5","service_check",[["service","api"]]]'))
        ->toBe('eb6c8f52dc05efa66877a6828cd36e94a36976bb4bb1cb416c33f0d1269b4c89')
        ->and(Identity::schedule('team', '5', 'service_check', ['service' => 'api']))
        ->toBe(Identity::schedule('team', 5, 'service_check', ['service' => 'api']))
        ->and(Identity::discriminators('c', ['b', 'a', 'z'], ['a' => true, 'b' => 1.5, 'c' => 'x']))
        ->toBe(['a' => 'true', 'b' => '1.5', 'z' => null]);
});
