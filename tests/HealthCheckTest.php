<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Exceptions\InvalidNotifiableForHealthCheck;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Interfaces\HasNotifiablesForAlerts;
use RoundlyConsulting\Alerts\Jobs\HealthCheckJob;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;
use RoundlyConsulting\Alerts\Tests\Models\User;

it('has notifiable relationship', function () {
    $healthCheck = createHealthCheckWithNotifiable(
        notifiable: Team::create(['name' => 'My Team']),
    );

    expect($healthCheck)
        ->notifiable()->toBeInstanceOf(MorphTo::class)
        ->notifiable->name->toBe('My Team');
});

it('executes callback for each notifiable for receiving alert notifications', function () {
    User::create(['email' => 'john@doe.com']);

    $healthCheck = createHealthCheckWithNotifiable(
        notifiable: Team::create(['name' => 'My Team']),
    );

    $notifiables = [];

    $healthCheck->forEachNotifiable(function (object $notifiable) use (&$notifiables) {
        array_push($notifiables, $notifiable);
    });

    expect($notifiables)
        ->toBeArray()
        ->toHaveCount(1)
        ->and($notifiables[0]->email)->toBe('john@doe.com');
});

it('throws exception while resolving notifiables with class that does not implement interface', function () {
    $healthCheck = createHealthCheckWithNotifiable(
        notifiable: User::create(['email' => 'john@doe.com']),
    );

    $healthCheck->forEachNotifiable(fn (object $notifiable) => $notifiable);
})->throws(
    InvalidNotifiableForHealthCheck::class,
    'Class ['.User::class.'] does not implement interface ['.HasNotifiablesForAlerts::class.']',
);

it('returns health check class', function () {
    $healthCheck = createHealthCheckWithNotifiable();

    expect($healthCheck->healthCheck())
        ->toBeInstanceOf(ExampleHealthCheck::class);
});

it('throws exception when resolving an unregistered health check', function () {
    $healthCheck = createHealthCheckWithNotifiable(
        notifiable: Team::create(['name' => 'My Team']),
        healthCheckKey: 'not_registered',
    );

    $healthCheck->healthCheck();
})->throws(
    InvalidHealthCheck::class,
    'No health check is registered for key [not_registered]. Register it via Health::check().',
);

it('throws exception when the notifiable is missing', function () {
    $healthCheck = new HealthCheck;

    $healthCheck->forEachNotifiable(fn (object $notifiable) => $notifiable);
})->throws(
    InvalidNotifiableForHealthCheck::class,
    'Class [null] does not implement interface ['.HasNotifiablesForAlerts::class.']',
);

it('checks whether health check is due to run', function () {
    $healthCheck = createHealthCheckWithNotifiable(
        frequency: '@hourly',
    );

    Carbon::setTestNow('2023-03-22 12:50:00');
    expect($healthCheck->isDue())->toBeFalse();

    Carbon::setTestNow('2023-03-22 13:00:00');
    expect($healthCheck->isDue())->toBeTrue();

    Carbon::setTestNow('2023-03-22 13:01:00');
    expect($healthCheck->isDue())->toBeFalse();
});

it('dispatches health check job to queue', function () {
    $healthCheck = createHealthCheckWithNotifiable(
        frequency: '@hourly',
    );

    Queue::fake([HealthCheckJob::class]);

    $healthCheck->dispatchHealthCheckJob();

    Queue::assertPushed(
        HealthCheckJob::class,
        fn (HealthCheckJob $job) => $job->healthCheck->getKey() === $healthCheck->getKey(),
    );
});
