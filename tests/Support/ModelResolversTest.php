<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthCheckRun;
use RoundlyConsulting\Alerts\Support\AlertModel;
use RoundlyConsulting\Alerts\Support\AlertSilenceModel;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;
use RoundlyConsulting\Alerts\Support\HealthCheckRunModel;
use RoundlyConsulting\Alerts\Tests\Models\CustomAlert;
use RoundlyConsulting\Alerts\Tests\Models\CustomAlertSilence;
use RoundlyConsulting\Alerts\Tests\Models\CustomHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\CustomHealthCheckRun;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * Each of the four model seams is pinned at all four of its edges: the packaged
 * default, a host subclass (honoured), a real Eloquent model that is not ours (the
 * package calls the packaged class's own API — scopes, `isDue()`, `options()` — so a
 * stranger model cannot serve it and the packaged model is used), and a class-string
 * that is not a model at all (the toolkit throws rather than blind-casting).
 */
final class StrangerModel extends Model
{
    protected $table = 'teams';
}

dataset('model seams', [
    'health check' => [
        'alerts.health-check',
        HealthCheckModel::class,
        HealthCheck::class,
        CustomHealthCheck::class,
    ],
    'alert' => [
        'alerts.alert',
        AlertModel::class,
        Alert::class,
        CustomAlert::class,
    ],
    'silence' => [
        'alerts.silence-model',
        AlertSilenceModel::class,
        AlertSilence::class,
        CustomAlertSilence::class,
    ],
    'run' => [
        'alerts.history.model',
        HealthCheckRunModel::class,
        HealthCheckRun::class,
        CustomHealthCheckRun::class,
    ],
]);

it('resolves the packaged model by default', function (string $key, string $resolver, string $packaged): void {
    expect($resolver::class())->toBe($packaged)
        ->and($resolver::new())->toBeInstanceOf($packaged)
        ->and($resolver::query()->getModel())->toBeInstanceOf($packaged);
})->with('model seams');

it('honours a host subclass', function (string $key, string $resolver, string $packaged, string $custom): void {
    config()->set($key, $custom);

    expect($resolver::class())->toBe($custom)
        ->and($resolver::new())->toBeInstanceOf($custom)
        ->and($resolver::query()->getModel())->toBeInstanceOf($custom);
})->with('model seams');

it('falls back to the packaged model for a model that is not ours', function (string $key, string $resolver, string $packaged): void {
    config()->set($key, StrangerModel::class);

    expect($resolver::class())->toBe($packaged);
})->with('model seams');

it('throws when the configured class is not a model', function (string $key, string $resolver): void {
    config()->set($key, 'Not\\A\\Model');

    $resolver::class();
})->with('model seams')->throws(InvalidConfigurationException::class);
