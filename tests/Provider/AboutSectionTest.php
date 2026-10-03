<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Alerts\Checks\DatabaseCheck;

/**
 * The secret-safe `about` capture (A).
 *
 * Purchases #13 is the bug this exists for: the fleet's most credential-heavy `about`
 * section was guarded by negative assertions against `app(Kernel::class)->output()`, which
 * returns `''`. Every "does not leak" check was vacuous.
 *
 * Alerts' two hand-rolled about tests (replaced by this file) were already on the right
 * side of that bug — they captured through `Artisan::output()` and carried a hand-written
 * "guard the guard" block. The expectation's contribution is that the positive half stops
 * being a thing the author remembered to write: `mustRender` is a required, non-empty
 * argument that throws at call time, and it is asserted BEFORE any secret check runs, so
 * this can never silently degrade into the purchases shape.
 *
 * What alerts must never render is not an API key: it is the host's own topology. A
 * registered check names what it watches (a connection, a disk, an internal URL); an
 * escalation policy names the groups it pages; the health endpoint is unauthenticated by
 * design, so hosts move it somewhere obscure. All three report as presence or a count.
 */
it('renders the alerts section without leaking the host topology it monitors', function (): void {
    config()->set('alerts.checks', [DatabaseCheck::class]);
    config()->set('alerts.escalation', [1 => 'payments-oncall', 3 => 'cto']);
    config()->set('alerts.route.uri', 'internal/ops/health-x9f2');

    expect('alerts')->toLeakNoSecrets(
        secrets: [
            // The on-call vocabulary is the host's org chart — never rendered.
            'payments-oncall',
            'cto',
            // The class of check names what is being watched.
            'DatabaseCheck',
            // The endpoint is unauthenticated; its path is the closest thing alerts has
            // to a credential.
            'internal/ops/health-x9f2',
        ],
        mustRender: [
            'Health check model',
            'Alert model',
            'Silence model',
            'Run model',
            // The counts themselves must render — the positive proof that each line is
            // reporting rather than silently empty.
            '1 registered',
            '2 level(s)',
            // The endpoint renders as presence only.
            'SET',
        ],
    );
});

it('reports a blank health endpoint uri as the default (strict config)', function (): void {
    config()->set('alerts.route.uri', '');

    Artisan::call('about', ['--only' => 'alerts']);

    expect(Artisan::output())->toMatch('/Health endpoint[ .]*DEFAULT/');
});

/**
 * The switches render as switches. Kept as a separate case: it is a rendering pin, not a
 * leak pin, and folding it into `mustRender` above would need the opposite config.
 */
it('reports the configured models and switches in the about section', function (): void {
    config()->set('alerts.silence', false);
    config()->set('alerts.history.enabled', false);
    config()->set('alerts.schedule.enabled', false);

    expect('alerts')->toLeakNoSecrets(
        secrets: ['payments-oncall'],
        mustRender: ['HealthCheck', 'AlertSilence', 'HealthCheckRun', 'OFF', 'NONE'],
    );
});
