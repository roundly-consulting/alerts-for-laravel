<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\Health;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthCheckRun;
use RoundlyConsulting\Testing\Arch\ArchPresets;

/**
 * Alerts shipped two arch rules: a debugging-leftovers check the presets now own, and a
 * bespoke cron-vendor ban with no preset equivalent, kept below.
 */
ArchPresets::strictTypes('RoundlyConsulting\Alerts');

/**
 * Four exemptions, each a real extension point rather than an oversight:
 *
 *  - the four models `config/alerts.php` invites a host to swap — pinned instead by the
 *    preset below, which is the deliberate tension the two presets exist to hold;
 *  - Check, the contract every health check (built-in or host-written) extends;
 *  - Health, which the shipped `Testing\HealthFake` extends — `Health::fake()` swaps the
 *    container binding for the subclass, so finalising it would break the package's own
 *    documented testing surface.
 */
ArchPresets::finalByDefault('RoundlyConsulting\Alerts')
    ->ignoring([
        Alert::class,
        AlertSilence::class,
        HealthCheck::class,
        HealthCheckRun::class,
        Check::class,
        Health::class,
    ]);

/**
 * The counter-weight, and the fleet's 7×-shipped fatal — alerts #25 was one of the seven.
 * `final` on a config-swappable model is a PHP fatal the moment a host uses the seam the
 * config documents. The preset also pins that each key really defaults to the packaged
 * model, so the seam cannot rot in the other direction.
 *
 * All FOUR seams are pinned. The row spec listed three; the config file ships four, and
 * `tests/Configured` has been driving all four for the package's whole life.
 */
ArchPresets::swappableModelsAreNotFinal([
    HealthCheck::class => 'alerts.health-check',
    Alert::class => 'alerts.alert',
    AlertSilence::class => 'alerts.silence-model',
    HealthCheckRun::class => 'alerts.history.model',
]);

/**
 * Alerts does no cryptography; the ban is a standing guard against a check signature or
 * silence token being hand-rolled here rather than in crypto-for-laravel.
 */
ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Alerts');

/**
 * Adopted rather than rejected as jwt rejected it: alerts has exactly the shape the preset
 * targets — four real Eloquent models behind config keys, with a Support seam — and needs
 * no late static binding (each resolver returns a class-string and every call site goes
 * through it).
 *
 * Both halves were proven to bite, but they are NOT equally strong here, and the weakness
 * is worth stating rather than discovering later:
 *
 *  - the late-static-binding half is fully live (`static::query()` in HealthCheckModel →
 *    red);
 *  - the stray-literal half covers exactly ONE of alerts' four seams. The preset finds a
 *    swap key by shape (`ModelSeam::MODEL_KEY` + a `model` / `models` / `*_model`
 *    segment), and only `alerts.history.model` matches. `alerts.alert` and
 *    `alerts.health-check` have no `model` segment at all, and `alerts.silence-model` is
 *    hyphenated where the preset tests for an underscore — a stray `config('alerts.alert')`
 *    in the provider was verified to leave the preset GREEN.
 *
 * So the bespoke rule at the bottom of this file is kept, not replaced: it is the only
 * thing covering the other three seams.
 */
ArchPresets::modelsResolveThroughSeam(__DIR__.'/../src', 'Support');

/**
 * The Dependency Policy as a test. No `alsoAllow`: alerts' `require` ships only
 * php/illuminate/roundly, and the workflow installs test tooling with `--dev`, so nothing
 * legitimately lands in `require` that this must forgive. If it goes red, the graph is
 * wrong — never widen the allow-list to quiet it.
 */
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../composer.json');

ArchPresets::noDebuggingLeftovers();

/**
 * Bespoke and kept: alerts parses cron expressions itself (Support/CronSchedule) rather
 * than taking a third-party cron vendor into `require`. No preset expresses this.
 */
it('does not depend on a third-party cron vendor')
    ->expect('RoundlyConsulting\Alerts')
    ->not->toUse('Cron');

/**
 * Bespoke and kept — this is the stray-literal guard for the three seams
 * `modelsResolveThroughSeam` cannot see (see the note on that preset above). It names the
 * four keys explicitly instead of inferring them from key shape, so alerts' naming
 * (`alert`, `health-check`, `silence-model`) is covered rather than silently skipped.
 *
 * Moved here from Provider/ConfigContractTest.php, where the tokenizer-based
 * `toSatisfyConfigContract` replaced everything around it. Deleting it with the rest of
 * that file would have dropped 3 of alerts' 4 seams on the floor.
 */
it('reads every model config key through a resolver, never inline', function (): void {
    $stray = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src', FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Support/')) {
            continue;
        }

        if (preg_match(
            "/(?:config|ModelResolver::for)\(\s*'alerts\.(health-check|alert|silence-model|history\.model)'/",
            (string) file_get_contents($file->getPathname()),
            $match,
        ) === 1) {
            $stray[] = basename($file->getPathname()).": 'alerts.{$match[1]}'";
        }
    }

    // Guard the guard: the seams really are read somewhere, so an empty scan of a
    // relocated src/ cannot make this pass vacuously.
    expect(glob(__DIR__.'/../src/Support/*Model.php'))->toHaveCount(4);

    expect($stray)->toBe([], 'These files read a model swap key outside the Support seam: '.implode(', ', $stray));
});
