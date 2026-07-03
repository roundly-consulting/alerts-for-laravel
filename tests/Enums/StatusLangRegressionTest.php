<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Enums\Status;

it('no longer references the deleted status lang namespace', function () {
    $sources = collect((new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS)
    )))->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php');

    foreach ($sources as $file) {
        expect(file_get_contents($file->getPathname()))
            ->not->toContain('alerts::status');
    }
})->group('regression');

it('renders status labels without the status lang file', function () {
    // The status.php lang file is gone; the trait derives the label from the
    // case value, so this must still resolve without any translation lookup.
    expect(Status::Ok->label())->toBe('Ok');
});
