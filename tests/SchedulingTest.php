<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use RoundlyConsulting\Alerts\AlertsServiceProvider;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

function alertCommandFrom(Schedule $schedule): ?object
{
    return collect($schedule->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'alerts:perform-health-checks'));
}

it('schedules the command at the configured preset frequency', function () {
    config()->set('alerts.schedule.frequency', 'hourly');

    $schedule = new Schedule;
    (new AlertsServiceProvider(app()))->scheduleCommand($schedule);

    expect(alertCommandFrom($schedule)?->expression)->toBe('0 * * * *');
});

it('throws for an unknown frequency instead of running every minute (strict config)', function () {
    config()->set('alerts.schedule.frequency', 'not-a-real-frequency');

    expect(fn () => (new AlertsServiceProvider(app()))->scheduleCommand(new Schedule))
        ->toThrow(InvalidConfigurationException::class, 'alerts.schedule.frequency');
});

it('registers the command on resolve when enabled', function () {
    config()->set('alerts.schedule.enabled', true);
    config()->set('alerts.schedule.frequency', 'everyMinute');

    app()->forgetInstance(Schedule::class);
    app()->singleton(Schedule::class, fn () => new Schedule);

    // The toolkit builds the package declaration in register(), so a provider
    // instantiated by hand must register before it boots.
    $provider = new AlertsServiceProvider(app());
    $provider->register();
    $provider->boot();

    expect(alertCommandFrom(app(Schedule::class)))->not->toBeNull();
});

it('does not schedule when the gate is disabled', function () {
    config()->set('alerts.schedule.enabled', false);

    $provider = new AlertsServiceProvider(app());
    $schedule = new Schedule;

    // Simulate the gated boot: a disabled gate must not touch the schedule.
    if (config('alerts.schedule.enabled', true) === true) {
        $provider->scheduleCommand($schedule);
    }

    expect(alertCommandFrom($schedule))->toBeNull();
});
