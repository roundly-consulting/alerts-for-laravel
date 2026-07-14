<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * Every `alerts.…` key the source reads must exist in the SHIPPED config file. A key
 * the code reads but the package never ships is unreachable for a host — and
 * invisible to a suite that sets the key by hand (shops shipped a whole store-credit
 * feature behind `shops.payments.*` while the config file defined `payment`, and 330
 * green tests set the same wrong key the code read).
 *
 * Alerts had the mirror image of that bug: it SHIPPED an `escalation` key — and
 * documented it in the README and the technical docs — that no line of `src/` ever
 * read. So the contract is pinned in both directions.
 */
it('ships every config key the source reads', function (): void {
    /** @var array<string, mixed> $shipped */
    $shipped = require __DIR__.'/../../config/alerts.php';

    $read = alertsConfigKeysRead();

    // Guard the guard: the package really does read config, so an empty scrape cannot
    // make this test pass vacuously.
    expect($read)->not->toBeEmpty();

    foreach ($read as $key => $file) {
        expect(Arr::has($shipped, $key))->toBeTrue(
            "config/alerts.php ships no \"{$key}\" key, but ".basename($file).' reads it',
        );
    }
});

/**
 * ...and the other direction: a key the config file ships but nothing reads is a
 * documented feature that silently does nothing. `alerts.escalation` was exactly that.
 */
it('reads every config key it ships', function (): void {
    /** @var array<string, mixed> $shipped */
    $shipped = require __DIR__.'/../../config/alerts.php';

    $read = array_keys(alertsConfigKeysRead());

    foreach (array_keys(Arr::dot($shipped)) as $dotted) {
        // A leaf under a section the code reads whole (`checks`, `escalation`) is
        // covered by the section's own read.
        $sections = explode('.', (string) $dotted);

        $matched = false;

        for ($depth = count($sections); $depth > 0; $depth--) {
            if (in_array(implode('.', array_slice($sections, 0, $depth)), $read, true)) {
                $matched = true;

                break;
            }
        }

        expect($matched)->toBeTrue("config/alerts.php ships \"{$dotted}\", but nothing in src/ reads it");
    }
});

/**
 * The model keys are read through the Support resolvers, never inline — so the swap
 * seam cannot be honoured in some call sites and bypassed in others.
 */
it('reads every model config key through a resolver', function (): void {
    foreach (alertsSourceFiles() as $file) {
        if (str_contains($file, '/Support/')) {
            continue;
        }

        expect((string) file_get_contents($file))
            ->not->toMatch("/(?:config|ModelResolver::for)\(\s*'alerts\.(health-check|alert|silence-model|history\.model)'/");
    }
});

/**
 * Every read of a package key names it as a literal — through `config()` or through
 * the toolkit's ModelResolver. Keys built dynamically would be invisible here, which
 * is why the package never builds one.
 *
 * @return array<string, string> key => the file that reads it
 */
function alertsConfigKeysRead(): array
{
    $read = [];

    foreach (alertsSourceFiles() as $file) {
        preg_match_all(
            "/(?:config|ModelResolver::for)\(\s*'alerts\.([a-z0-9_.\-]+)'/",
            (string) file_get_contents($file),
            $matches,
        );

        foreach ($matches[1] as $key) {
            $read[$key] = $file;
        }
    }

    return $read;
}

/** @return list<string> */
function alertsSourceFiles(): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../../src'),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}
