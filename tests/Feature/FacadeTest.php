<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Facades\Health;

it('documents its root, is fakeable and reaches every action', function (): void {
    expect(Health::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});
