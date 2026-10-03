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
        // `alerts.route.name` and `alerts.route.uri` are read through Support\AlertsConfig's
        // own string reader, which takes the key as a literal argument the scraper does not
        // follow into the reader.
        //
        // Named exactly rather than the blanket `'alerts.'`, and the difference is
        // load-bearing here: `extraReadPrefixes` counts ANY string literal under the prefix as
        // a read, wherever it appears. The route-name DEFAULT, `'alerts.health'`, sits in the
        // same call and in the package's own dotted namespace — under `'alerts.'` it would
        // scrape as a read of a config key `alerts.health`, which the file does not ship and
        // never should, and the forward direction would fail on a string that was never a
        // config key at all.
        'extraReadPrefixes' => [
            'alerts.route.name',
            'alerts.route.uri',
        ],

        // Deliberately NO `excludeFromReverse` for the provider. The testing README's own
        // example excludes the service provider on the grounds that "a render is not a
        // read" — but this provider's `contributesToAbout()` closure calls `config('alerts.…')`
        // for real, and `scheduleCommand()` reads `alerts.schedule.frequency` and
        // `alerts.history.enabled` to wire the scheduler. Excluding it would discard the
        // only reader of several bound keys and weaken the reverse direction for nothing.
    ]);
});
