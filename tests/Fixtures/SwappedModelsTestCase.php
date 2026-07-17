<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests\Fixtures;

use RoundlyConsulting\Alerts\Tests\Models\CustomAlert;
use RoundlyConsulting\Alerts\Tests\Models\CustomAlertSilence;
use RoundlyConsulting\Alerts\Tests\Models\CustomHealthCheck;
use RoundlyConsulting\Alerts\Tests\Models\CustomHealthCheckRun;
use RoundlyConsulting\Alerts\Tests\TestCase;

/**
 * The suite's base case with all FOUR alerts model seams already pointed at host
 * subclasses BEFORE the providers boot.
 *
 * Boot order is the whole point. The provider hangs its check list, its schedule and its
 * observers on whatever these keys name at boot, so a `config()->set()` inside a test body
 * (or a `beforeEach`, which is what `tests/Configured/ConfiguredModelsTest.php` did before
 * this row) reads back correctly while leaving every listener on the packaged model. That
 * is the exact shape media #28 shipped behind.
 *
 * Note the `array_merge(parent::configBeforeBoot(), …)`: dropping it would silently discard
 * the base's own wiring (`mail.default`), with no error and no red — the same decapitation
 * an un-parented `defineEnvironment()` override causes one level up.
 *
 * @see TestCase
 */
abstract class SwappedModelsTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), [
            'alerts.health-check' => CustomHealthCheck::class,
            'alerts.alert' => CustomAlert::class,
            'alerts.silence-model' => CustomAlertSilence::class,
            'alerts.history.model' => CustomHealthCheckRun::class,
        ]);
    }
}
