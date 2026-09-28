<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Alerts\Actions\RunHealthCheckAction;
use RoundlyConsulting\Alerts\Events\HealthCheckEscalated;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Support\AlertModel;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;

/**
 * Two runs of the SAME monitor can overlap in production: the scheduler's
 * `withoutOverlapping()` guards the *command*, not the per-monitor jobs it queues,
 * and `Health::run()` can fire synchronously alongside a queued job. Both workers
 * load their own HealthCheck row, so anything the pipeline computes from a value it
 * read earlier is a lost-update surface.
 *
 * The package holds no row lock anywhere — SQLite compiles `lockForUpdate()` to an
 * empty string, so a lock would not exist on the suite's engine anyway. These pins
 * assert the writes that MUST be race-safe without one: the consecutive-result
 * counters (relative writes), the escalation level and the recovery (compare-and-swap
 * updates), and opening the alert (a unique index the losing insert adopts).
 *
 * Interleaving is simulated the way the workers really do it — two separate model
 * instances of the same row, each carrying the state it read before the other wrote.
 */
beforeEach(function (): void {
    Health::check(ExampleHealthCheck::class);

    $this->team = Team::create();

    $this->monitor = HealthCheckModel::class()::create([
        'notifiable_type' => $this->team->getMorphClass(),
        'notifiable_id' => $this->team->getKey(),
        'health_check' => 'example_health_check',
        'frequency' => '* * * * *',
        'max_attempts' => 1,
        'decay_minutes' => 1,
        'meta' => ['fail_after' => 2],
    ]);

    $this->run = fn (int $id) => app(RunHealthCheckAction::class)->execute(
        HealthCheckModel::query()->findOrFail($id),
    );
});

it('never loses a failure that lands between two racing runs', function (): void {
    ExampleHealthCheck::$ok = false;

    $id = (int) $this->monitor->getKey();

    // Both workers load the row at consecutive_failures = 0.
    $workerA = HealthCheckModel::query()->findOrFail($id);
    $workerB = HealthCheckModel::query()->findOrFail($id);

    app(RunHealthCheckAction::class)->execute($workerA);
    app(RunHealthCheckAction::class)->execute($workerB);

    // Two real failures happened, so the row must record two — a stale literal write
    // ("set failures = 0 + 1", twice) records one and silently defers the alert past
    // its `fail_after` threshold.
    expect(HealthCheckModel::query()->findOrFail($id)->consecutive_failures)->toBe(2);
});

it('opens the alert on the second failure even when the runs overlap', function (): void {
    ExampleHealthCheck::$ok = false;

    $id = (int) $this->monitor->getKey();

    $workerA = HealthCheckModel::query()->findOrFail($id);
    $workerB = HealthCheckModel::query()->findOrFail($id);

    app(RunHealthCheckAction::class)->execute($workerA);

    expect(AlertModel::query()->count())->toBe(0);

    app(RunHealthCheckAction::class)->execute($workerB);

    // `fail_after` = 2 and two failures were recorded, so the alert is open.
    expect(AlertModel::query()->count())->toBe(1);
});

it('never loses a success that lands between two racing runs', function (): void {
    ExampleHealthCheck::$ok = true;

    $id = (int) $this->monitor->getKey();

    $workerA = HealthCheckModel::query()->findOrFail($id);
    $workerB = HealthCheckModel::query()->findOrFail($id);

    app(RunHealthCheckAction::class)->execute($workerA);
    app(RunHealthCheckAction::class)->execute($workerB);

    expect(HealthCheckModel::query()->findOrFail($id)->consecutive_successes)->toBe(2);
});

/**
 * The escalation race is narrower than the counter one: the pipeline re-reads the
 * open alert on every run, so a *sequential* pair can never see a stale level. The
 * window is between that read and the write — where a second worker can escalate the
 * same alert to the same level, paging the tier twice.
 *
 * It is reproduced exactly, and deterministically: the competing worker is run from
 * the `retrieved` event of the alert the first worker just read, so the first worker
 * resumes holding the level it saw BEFORE the second one wrote. That is the race,
 * with the interleaving pinned instead of hoped for.
 */
it('escalates an open alert only once per level when two runs interleave', function (): void {
    ExampleHealthCheck::$ok = false;

    $this->monitor->update(['meta' => [
        'fail_after' => 1,
        'escalation' => [1 => 'owner', 3 => 'oncall'],
    ]]);

    $id = (int) $this->monitor->getKey();

    ($this->run)($id); // failures 1 -> alert opens at level 1
    ($this->run)($id); // failures 2 -> still level 1

    expect(AlertModel::query()->sole()->escalation_level)->toBe(1);

    Event::fake([HealthCheckEscalated::class]);

    $interleaved = false;

    AlertModel::class()::retrieved(function () use (&$interleaved, $id): void {
        if ($interleaved) {
            return;
        }

        $interleaved = true;

        // The competing worker runs to completion here, taking the alert 1 -> 3.
        ($this->run)($id);
    });

    // ...and this worker resumes with the alert it read at level 1, computing the very
    // same 1 -> 3 transition the other one just took.
    ($this->run)($id);

    expect($interleaved)->toBeTrue()
        ->and(AlertModel::query()->sole()->escalation_level)->toBe(3);

    // Exactly one escalation to level 3 — the loser of the race must not page 'oncall'
    // a second time.
    Event::assertDispatchedTimes(HealthCheckEscalated::class, 1);
});

/**
 * Opening an alert is a read-then-insert: two failing runs of one monitor can both
 * read "no open alert" and both insert one. The orphan then keeps the report red
 * forever. A unique (health_check_id, open_slot) index lets only one open alert exist;
 * the loser adopts the winner's.
 */
it('never leaves two open alerts when two failing runs race to open one', function (): void {
    ExampleHealthCheck::$ok = false;
    $this->monitor->update(['meta' => []]);
    $id = (int) $this->monitor->getKey();

    $interleaved = false;

    // The competitor runs from inside this run's insert — after this run has already
    // decided there is no open alert to reuse.
    AlertModel::class()::creating(function () use (&$interleaved, $id): void {
        if ($interleaved) {
            return;
        }

        $interleaved = true;

        ($this->run)($id);
    });

    ($this->run)($id);

    expect($interleaved)->toBeTrue()
        ->and(AlertModel::query()->whereNull('recovered_at')->count())->toBe(1);
});

it('adopts the alert a racing run opened between its read and its insert', function (): void {
    ExampleHealthCheck::$ok = false;
    $this->monitor->update(['meta' => []]);
    $id = (int) $this->monitor->getKey();

    Event::fake([RoundlyConsulting\Alerts\Events\HealthCheckFailed::class]);

    $interleaved = false;

    // The competitor runs to completion — and commits — right before this run's
    // insert transaction starts.
    AlertModel::query()->getConnection()->beforeStartingTransaction(function () use (&$interleaved, $id): void {
        if ($interleaved) {
            return;
        }

        $interleaved = true;

        ($this->run)($id);
    });

    ($this->run)($id);

    $alert = AlertModel::query()->sole();

    expect($interleaved)->toBeTrue()
        ->and($alert->recovered_at)->toBeNull();

    Event::assertDispatchedTimes(RoundlyConsulting\Alerts\Events\HealthCheckFailed::class, 2);
    Event::assertDispatched(
        RoundlyConsulting\Alerts\Events\HealthCheckFailed::class,
        fn ($event): bool => $event->alert->is($alert),
    );
});

it('recovers an alert once when two healthy runs interleave', function (): void {
    ExampleHealthCheck::$ok = false;
    $this->monitor->update(['meta' => []]);
    $id = (int) $this->monitor->getKey();

    ($this->run)($id);

    ExampleHealthCheck::$ok = true;
    Event::fake([RoundlyConsulting\Alerts\Events\HealthCheckRecovered::class]);

    $interleaved = false;

    AlertModel::class()::retrieved(function () use (&$interleaved, $id): void {
        if ($interleaved) {
            return;
        }

        $interleaved = true;

        // The competing worker recovers the alert this worker just read as open.
        ($this->run)($id);
    });

    ($this->run)($id);

    expect($interleaved)->toBeTrue()
        ->and(AlertModel::query()->whereNull('recovered_at')->count())->toBe(0);

    Event::assertDispatchedTimes(RoundlyConsulting\Alerts\Events\HealthCheckRecovered::class, 1);
});

it('opens a new alert past one deleted or resolved outside the pipeline', function (string $how): void {
    ExampleHealthCheck::$ok = false;
    $this->monitor->update(['meta' => []]);
    $id = (int) $this->monitor->getKey();

    ($this->run)($id);

    $first = AlertModel::query()->sole();
    $how === 'deleted' ? $first->delete() : $first->update(['recovered_at' => now()]);

    ($this->run)($id);

    expect(AlertModel::query()->whereNull('recovered_at')->count())->toBe(1)
        ->and(AlertModel::query()->whereNull('recovered_at')->sole()->is($first))->toBeFalse();
})->with(['deleted', 'resolved']);
