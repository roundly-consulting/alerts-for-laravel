<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\DataTransferObjects\ScheduleHealthCheckData;
use RoundlyConsulting\Alerts\Exceptions\InvalidHealthCheck;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ExampleHealthCheck;

it('derives the key from a check class-string', function () {
    $data = new ScheduleHealthCheckData(check: ExampleHealthCheck::class);

    expect($data->key())->toBe('example_health_check');
});

it('treats a non-class string as a key', function () {
    $data = new ScheduleHealthCheckData(check: 'custom_key');

    expect($data->key())->toBe('custom_key');
});

it('rejects a class that is not a check', function () {
    (new ScheduleHealthCheckData(check: stdClass::class))->key();
})->throws(InvalidHealthCheck::class);

it('rejects an abstract class that is not a check', function () {
    (new ScheduleHealthCheckData(check: Illuminate\Database\Eloquent\Model::class))->key();
})->throws(InvalidHealthCheck::class, 'does not extend base check class');

it('resolves preset frequencies to cron', function () {
    $data = new ScheduleHealthCheckData(check: 'k', frequency: 'hourly');

    expect($data->cronFrequency())->toBe('@hourly');
});

it('exposes defaults', function () {
    $data = new ScheduleHealthCheckData(check: 'k');

    expect($data->maxAttempts)->toBe(1)
        ->and($data->decayMinutes)->toBe(1)
        ->and($data->meta)->toBe([])
        ->and($data->cronFrequency())->toBe('* * * * *');
});
