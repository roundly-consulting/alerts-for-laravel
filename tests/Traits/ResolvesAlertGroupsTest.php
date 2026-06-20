<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Tests\Models\Team;
use RoundlyConsulting\Alerts\Tests\Models\User;

it('returns the default-group notifiables for any group name by default', function () {
    $alice = User::create(['email' => 'alice@x.com']);
    $bob = User::create(['email' => 'bob@x.com']);

    $team = Team::create();

    $owners = iterator_to_array((function () use ($team) {
        yield from $team->notifiablesForAlertGroup('owner');
    })());

    expect($owners)->toHaveCount(2)
        ->and(collect($owners)->pluck('id')->all())->toBe([$alice->id, $bob->id]);
});
