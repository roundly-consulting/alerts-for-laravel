<?php

declare(strict_types=1);

it('will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

it('does not depend on a third-party cron vendor')
    ->expect('RoundlyConsulting\Alerts')
    ->not->toUse('Cron');
