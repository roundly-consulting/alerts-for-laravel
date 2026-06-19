<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Enums\Status;

it('builds alerts via the factory states', function () {
    expect(Alert::factory()->warning()->make()->status)->toBe(Status::Warning)
        ->and(Alert::factory()->failed()->make()->status)->toBe(Status::Failed)
        ->and(Alert::factory()->skipped()->make()->status)->toBe(Status::Skipped)
        ->and(Alert::factory()->recovered()->make()->recovered_at)->not->toBeNull();
});
