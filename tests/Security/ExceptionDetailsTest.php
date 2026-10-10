<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Checks\CacheCheck;
use RoundlyConsulting\Alerts\Checks\DatabaseCheck;
use RoundlyConsulting\Alerts\Checks\HttpPingCheck;
use RoundlyConsulting\Alerts\Checks\QueueCheck;
use RoundlyConsulting\Alerts\Checks\StorageCheck;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\Exceptions\CheckTimedOut;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheckRun;
use RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification;
use RoundlyConsulting\Alerts\Tests\HealthChecks\ThrowingHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\Team;
use RoundlyConsulting\Alerts\Tests\Models\User;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * An exception's message and frames can carry hosts, SQL and credentials. By default they are
 * never stored on a run or an alert, nor mailed: the throwable is reported to the exception
 * handler (the logs), and the stored result names only the exception's class.
 * `alerts.store_exception_details` opts back into storing them.
 */
beforeEach(function (): void {
    Notification::fake();
    Health::check(ThrowingHealthCheck::class);

    $this->team = Team::create();
    User::create(['email' => 'oncall@example.test']);
});

it('keeps the exception message and frames out of the run and the alert', function (): void {
    $result = Health::for($this->team)->run(ThrowingHealthCheck::class);

    $run = HealthCheckRun::query()->sole();
    $alert = Alert::query()->sole();

    expect($result->message)->toBe('upstream exploded')
        ->and($result->meta)->toHaveKeys(['exception', 'exception_message', 'exception_trace'])
        ->and($run->message)->toBe('The check threw RuntimeException; the details are in the application log.')
        ->and($run->meta)->toBe(['exception' => RuntimeException::class])
        ->and($alert->message)->toBe($run->message)
        ->and($alert->meta)->toBe(['exception' => RuntimeException::class])
        ->and(json_encode([$run->toArray(), $alert->toArray()]))->not->toContain('upstream exploded');
});

it('never mails the exception message', function (): void {
    Health::for($this->team)->run(ThrowingHealthCheck::class);

    Notification::assertSentTo(User::query()->sole(), HealthCheckFailedNotification::class, function (HealthCheckFailedNotification $notification, array $channels, object $notifiable): bool {
        $mail = $notification->toMail($notifiable);

        expect($mail)->toBeInstanceOf(MailMessage::class)
            ->and(json_encode([$mail->toArray(), $notification->toArray($notifiable)]))
            ->not->toContain('upstream exploded')
            ->toContain('RuntimeException');

        return true;
    });
});

it('reports the throwable to the exception handler, so nothing is lost', function (): void {
    Exceptions::fake();

    Health::for($this->team)->run(ThrowingHealthCheck::class);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'upstream exploded');
});

it('stores the details again when the host opts in', function (): void {
    config()->set('alerts.store_exception_details', true);

    Health::for($this->team)->run(ThrowingHealthCheck::class);

    $run = HealthCheckRun::query()->sole();

    expect($run->message)->toBe('upstream exploded')
        ->and($run->meta)->toHaveKeys(['exception', 'exception_message', 'exception_trace'])
        ->and(Alert::query()->sole()->meta['exception_message'] ?? null)->toBe('upstream exploded');
});

it('keeps a built-in check\'s own message but not the error it caught', function (bool $details): void {
    config()->set('alerts.store_exception_details', $details);
    Health::check(DatabaseCheck::make('missing-connection')->as('db'));

    $result = Health::for($this->team)->run('db');

    $run = HealthCheckRun::query()->sole();

    expect($result->meta)->toHaveKey('error')
        ->and($run->message)->toBe('Database connection [missing-connection] is unreachable.')
        ->and($run->meta['connection'] ?? null)->toBe('missing-connection')
        ->and(array_key_exists('error', $run->meta ?? []))->toBe($details);
})->with(['default' => false, 'opted in' => true]);

it('reports the error a built-in check caught, since it is not stored either', function (Closure $check): void {
    Exceptions::fake();

    $result = $check();

    expect($result->status)->toBe(Status::Failed)
        ->and($result->meta['error'] ?? null)->toBeString()->not->toBe('');

    Exceptions::assertReportedCount(1);

    expect(Exceptions::reported()[0]->getMessage())->toBe($result->meta['error']);
})->with([
    'database' => [fn () => DatabaseCheck::make('missing-connection')->check()],
    'cache' => [fn () => CacheCheck::make('missing-store')->check()],
    'queue' => [fn () => QueueCheck::make('missing')->check()],
    'storage' => [function () {
        Storage::shouldReceive('disk->path')->andThrow(new RuntimeException('no disk'));

        return StorageCheck::make('broken')->check();
    }],
    'http' => [function () {
        Http::fake(fn () => throw new ConnectionException('connection refused'));

        return HttpPingCheck::make('https://internal.example.test')->check();
    }],
]);

it('never reports a timeout a built-in check caught', function (): void {
    Exceptions::fake();
    Http::fake(fn () => throw CheckTimedOut::after('ping', 1));

    expect(HttpPingCheck::make('https://internal.example.test')->check()->status)->toBe(Status::Failed);

    Exceptions::assertNothingReported();
});

it('keeps the timeout text, its budget and its class, but not its frames', function (): void {
    Exceptions::fake();

    Health::define('slow', fn () => throw CheckTimedOut::after('slow', 3));

    $result = Health::for($this->team)->run('slow');
    $run = HealthCheckRun::query()->sole();

    expect($result->status)->toBe(Status::Failed)
        ->and($run->message)->toBe('Health check [slow] timed out after 3s.')
        // Key by key: jsonb (and MySQL JSON) reorder object keys.
        ->and($run->meta)->toHaveCount(2)
        ->toMatchArray(['timed_out_after' => 3, 'exception' => CheckTimedOut::class]);

    Exceptions::assertNotReported(CheckTimedOut::class);
});

it('names the exception in the active locale', function (): void {
    app()->setLocale('sk');

    Health::for($this->team)->run(ThrowingHealthCheck::class);

    expect(HealthCheckRun::query()->sole()->message)
        ->toBe('Kontrola vyhodila výnimku RuntimeException; podrobnosti sú v aplikačnom logu.');
});

it('reads the switch strictly', function (mixed $value, bool $stored): void {
    config()->set('alerts.store_exception_details', $value);

    Health::for($this->team)->run(ThrowingHealthCheck::class);

    expect(array_key_exists('exception_message', HealthCheckRun::query()->sole()->meta ?? []))->toBe($stored);
})->with([
    'absent' => [null, false],
    'on' => ['on', true],
    'yes' => ['yes', true],
    'zero' => ['0', false],
]);

it('throws on a switch typo instead of reading it as off (strict config)', function (): void {
    config()->set('alerts.store_exception_details', 'sometimes');

    expect(fn () => Health::for($this->team)->run(ThrowingHealthCheck::class))
        ->toThrow(InvalidConfigurationException::class, 'alerts.store_exception_details');
});

it('keeps the fake\'s report free of the exception message', function (): void {
    Health::fake();

    $result = Health::for($this->team)->run(ThrowingHealthCheck::class);
    $check = Health::report()->checks()[0];

    expect($result->message)->toBe('upstream exploded')
        ->and($check->message)->toBe('The check threw RuntimeException; the details are in the application log.')
        ->and($check->meta)->toBe(['exception' => RuntimeException::class]);
});
