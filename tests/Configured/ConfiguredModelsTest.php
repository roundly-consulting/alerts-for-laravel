<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Alerts\Actions\RunHealthCheckAction;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Support\AlertModel;
use RoundlyConsulting\Alerts\Support\AlertSilenceModel;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;
use RoundlyConsulting\Alerts\Support\HealthCheckRunModel;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\CustomAlert;
use RoundlyConsulting\Alerts\Tests\Models\CustomAlertSilence;
use RoundlyConsulting\Alerts\Tests\Models\CustomHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\CustomHealthCheckRun;
use RoundlyConsulting\Alerts\Tests\Models\Team;
use RoundlyConsulting\Alerts\Tests\Models\User;

/**
 * `config/alerts.php` invites a host to swap all four models. This drives the whole
 * package through host subclasses of every one of them at once — schedule, run,
 * record history, open an alert, mute, recover — so a seam that is honoured in some
 * call sites and bypassed in others cannot pass.
 *
 * It is also the pin for the FK bug: `HealthCheck::runs()` named no foreign key, so
 * Eloquent derived it from the PARENT'S CLASS NAME — a host `CustomHealthCheck` read
 * and wrote `custom_health_check_id`, a column no migration has ever created.
 */
beforeEach(function (): void {
    config()->set('alerts.health-check', CustomHealthCheck::class);
    config()->set('alerts.alert', CustomAlert::class);
    config()->set('alerts.silence-model', CustomAlertSilence::class);
    config()->set('alerts.history.model', CustomHealthCheckRun::class);

    Health::check(ExampleHealthCheck::class);

    $this->team = Team::create();
    User::create(['email' => 'oncall@x.com']);
});

it('resolves every configured model', function (): void {
    expect(HealthCheckModel::class())->toBe(CustomHealthCheck::class)
        ->and(AlertModel::class())->toBe(CustomAlert::class)
        ->and(AlertSilenceModel::class())->toBe(CustomAlertSilence::class)
        ->and(HealthCheckRunModel::class())->toBe(CustomHealthCheckRun::class);
});

it('names the health check foreign key a swapped model would derive from its class', function (): void {
    $monitor = new CustomHealthCheck;

    expect($monitor->runs()->getForeignKeyName())->toBe('health_check_id');

    $alert = new CustomAlert;
    $run = new CustomHealthCheckRun;

    expect($alert->healthCheck()->getForeignKeyName())->toBe('health_check_id')
        ->and($run->healthCheck()->getForeignKeyName())->toBe('health_check_id');
});

it('runs a monitor end to end through the host models', function (): void {
    Notification::fake();

    ExampleHealthCheck::$status = Status::Failed;
    ExampleHealthCheck::$ok = false;

    $monitor = $this->team->monitorCheck(ExampleHealthCheck::class)->everyMinute()->save();

    expect($monitor)->toBeInstanceOf(CustomHealthCheck::class);

    app(RunHealthCheckAction::class)->execute(
        HealthCheckModel::query()->findOrFail($monitor->getKey()),
    );

    // The run history landed on the host's run model, keyed by the packaged column.
    $run = CustomHealthCheckRun::query()->sole();

    expect($run->health_check_id)->toBe($monitor->getKey())
        ->and($run->status)->toBe(Status::Failed);

    // ...and the relation off the host's monitor model reads it back.
    expect($monitor->runs()->count())->toBe(1)
        ->and($monitor->latestRun())->toBeInstanceOf(CustomHealthCheckRun::class);

    // The alert landed on the host's alert model, and its inverse relation resolves
    // back to the host's monitor.
    $alert = CustomAlert::query()->sole();

    expect($alert->healthCheck)->toBeInstanceOf(CustomHealthCheck::class)
        ->and($alert->healthCheck->getKey())->toBe($monitor->getKey());
});

it('serves the notifiable relations through the host models', function (): void {
    ExampleHealthCheck::$ok = false;
    ExampleHealthCheck::$status = Status::Failed;

    Notification::fake();

    $monitor = $this->team->monitorCheck(ExampleHealthCheck::class)->everyMinute()->save();

    app(RunHealthCheckAction::class)->execute(
        HealthCheckModel::query()->findOrFail($monitor->getKey()),
    );

    expect($this->team->healthChecks()->first())->toBeInstanceOf(CustomHealthCheck::class)
        ->and($this->team->alerts()->first())->toBeInstanceOf(CustomAlert::class);
});

it('creates a health check through the trait helper on the host model', function (): void {
    $monitor = $this->team->createHealthCheck('example_health_check', '* * * * *');

    expect($monitor)->toBeInstanceOf(CustomHealthCheck::class)
        ->and(CustomHealthCheck::query()->count())->toBe(1);
});

it('mutes and recovers through the host models', function (): void {
    Notification::fake();

    $silence = Health::mute('example_health_check');

    expect($silence)->toBeInstanceOf(CustomAlertSilence::class)
        ->and(Health::isMuted('example_health_check'))->toBeTrue();

    ExampleHealthCheck::$ok = false;
    ExampleHealthCheck::$status = Status::Failed;

    $monitor = $this->team->monitorCheck(ExampleHealthCheck::class)->everyMinute()->save();

    app(RunHealthCheckAction::class)->execute(
        HealthCheckModel::query()->findOrFail($monitor->getKey()),
    );

    // Muted: the alert is recorded but flagged, and nothing is paged.
    expect(CustomAlert::query()->sole()->meta)->toHaveKey('muted', true);

    Notification::assertNothingSent();

    Health::unmute('example_health_check');

    expect(Health::isMuted('example_health_check'))->toBeFalse();

    ExampleHealthCheck::$ok = true;
    ExampleHealthCheck::$status = Status::Ok;

    app(RunHealthCheckAction::class)->execute(
        HealthCheckModel::query()->findOrFail($monitor->getKey()),
    );

    expect(CustomAlert::query()->sole()->recovered_at)->not->toBeNull();
});

it('reports uptime and latency off the host run model', function (): void {
    Notification::fake();

    $monitor = $this->team->monitorCheck(ExampleHealthCheck::class)->everyMinute()->save();

    ExampleHealthCheck::$ok = true;
    ExampleHealthCheck::$status = Status::Ok;

    app(RunHealthCheckAction::class)->execute(
        HealthCheckModel::query()->findOrFail($monitor->getKey()),
    );

    $report = Health::report($this->team);

    expect($report->checks())->toHaveCount(1)
        ->and($report->checks()[0]->uptime)->toBe(100.0);
});

it('runs the console commands against the host models', function (): void {
    Notification::fake();

    $monitor = $this->team->monitorCheck(ExampleHealthCheck::class)->everyMinute()->save();

    ExampleHealthCheck::$ok = true;
    ExampleHealthCheck::$status = Status::Ok;

    app(RunHealthCheckAction::class)->execute(
        HealthCheckModel::query()->findOrFail($monitor->getKey()),
    );

    $this->artisan('alerts:list')->assertSuccessful();

    expect(CustomHealthCheckRun::query()->count())->toBe(1);

    CustomHealthCheckRun::query()->update(['ran_at' => now()->subDays(90)]);

    $this->artisan('alerts:prune-runs', ['--days' => 30])->assertSuccessful();

    expect(CustomHealthCheckRun::query()->count())->toBe(0);
});
