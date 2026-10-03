<?php

declare(strict_types=1);

/**
 * The config contract, pinned in both directions.
 *
 * This replaces a hand-rolled pair of regex scans over raw file text. The regex was
 * honest work, but media #27 is the reason it goes: a regex over raw text is satisfied by
 * a **docblock mention** of a key, and stayed green there with the fix reverted. The
 * expectation scrapes reads from source **tokens**, so a comment is a comment and never a
 * read.
 *
 *  - forward — every key the code reads is shipped. This is shops #18, whose whole
 *    store-credit feature read `shops.payments.*` while the file shipped `payment.*`;
 *    330 tests stayed green because the suite set the same wrong key the code read.
 *  - reverse — every shipped leaf is read. Alerts had the mirror image of that bug and is
 *    the fleet's named case for it: #24, an `escalation` key shipped and documented three
 *    times over that no line of src/ ever read. A documented key nothing reads is dead
 *    config that lies to the host.
 *
 * The bespoke "every model key is read through a resolver" rule that used to live in this
 * file has moved to tests/ArchTest.php — it is an architecture rule, and it covers three
 * seams `modelsResolveThroughSeam` structurally cannot see. It was kept, not replaced.
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/alerts.php')->toSatisfyConfigContract([__DIR__.'/../../src', __DIR__.'/../../database'], [
        // The four model keys are read through the toolkit's `ModelResolver::for('alerts.…')`
        // seam rather than a `config()` call. They are real reads — they drive the whole
        // swap — but they are not `config(` tokens, so a prefix is what makes them visible
        // to the scraper.
        //
        // The four keys are named exactly rather than using the blanket `'alerts.'` the
        // playbook suggests, and the difference is load-bearing here: `extraReadPrefixes`
        // counts ANY string literal under the prefix as a read, wherever it appears. Alerts
        // registers its route as `->name(config('alerts.route.name', 'alerts.health'))` —
        // a route NAME that lives in the package's own dotted namespace. Under `'alerts.'`
        // that default value is scraped as a read of a config key `alerts.health`, which
        // the file does not ship and never should, and the forward direction fails on a
        // string that was never a config key at all. Naming the seams exactly reads every
        // real seam and nothing else.
        'extraReadPrefixes' => [
            'alerts.health-check',
            'alerts.alert',
            'alerts.silence-model',
            'alerts.history.model',
            // `alerts.key_type` is read through `KeyType::fromConfig('alerts.key_type')`
            // in the migrations (hence `database` in the scanned dirs) — it decides the
            // shipped morph column types, but it is not a `config(` token.
            'alerts.key_type',
            // Read strictly through `Support\AlertsConfig` — `Config::oneOf()` and a
            // non-empty-string check — neither of which is a `config(` token.
            'alerts.schedule.frequency',
            'alerts.route.name',
        ],

        // Deliberately NO `excludeFromReverse` for the provider. The testing README's own
        // example excludes the service provider on the grounds that "a render is not a
        // read" — but this provider's `contributesToAbout()` closure calls `config('alerts.…')`
        // for real, and `scheduleCommand()` reads `alerts.schedule.frequency` and
        // `alerts.history.enabled` to wire the scheduler. Excluding it would discard the
        // only reader of several bound keys and weaken the reverse direction for nothing.
    ]);
});
