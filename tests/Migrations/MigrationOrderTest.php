<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use RoundlyConsulting\Alerts\AlertsServiceProvider;

/**
 * Four migrations wired together by two real foreign keys: `alerts.health_check_id`
 * and `health_check_runs.health_check_id`, both onto `health_checks`. Migrations are
 * publish-only, and publishing preserves the source directory's order — so that order
 * has to be runnable end to end from an empty database.
 *
 * SQLite happily creates a table referencing a missing parent (it only complains at
 * insert time), so the first two tests are the committed pin, not the proof: the order
 * was proved against a real PostgreSQL server, which rejects a dangling foreign key at
 * DDL time — the shipped order applied all four with both keys, and a negative control
 * (`create_alerts_table` first) was watched being rejected with `relation
 * "health_checks" does not exist`.
 *
 * These tests run the *published* files, under their published names, into a database
 * that starts empty — exactly what a host does.
 */
beforeEach(function (): void {
    $this->publishedPath = sys_get_temp_dir().'/alerts-migration-order-'.bin2hex(random_bytes(6));
    $this->publishedDatabase = $this->publishedPath.'/database.sqlite';

    File::makeDirectory($this->publishedPath, recursive: true);
    File::put($this->publishedDatabase, '');

    $published = ServiceProvider::pathsToPublish(AlertsServiceProvider::class, 'alerts-migrations');

    foreach ($published as $source => $target) {
        File::copy($source, $this->publishedPath.'/'.basename((string) $target));
    }

    config()->set('database.connections.published', [
        'driver' => 'sqlite',
        'database' => $this->publishedDatabase,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->publishedPath);
});

it('migrates the published files clean from an empty database', function (): void {
    $schema = Schema::connection('published');

    expect($schema->hasTable('health_checks'))->toBeFalse();

    $this->artisan('migrate', [
        '--database' => 'published',
        '--path' => $this->publishedPath,
        '--realpath' => true,
    ])->assertExitCode(0);

    foreach (['health_checks', 'alerts', 'alert_silences', 'health_check_runs'] as $table) {
        expect($schema->hasTable($table))->toBeTrue();
    }
});

it('keeps every foreign key intact in the published schema', function (): void {
    $this->artisan('migrate', [
        '--database' => 'published',
        '--path' => $this->publishedPath,
        '--realpath' => true,
    ])->assertExitCode(0);

    $schema = Schema::connection('published');

    $foreignKeys = static fn (string $table): array => array_map(
        static fn (array $key): string => $key['columns'][0].' → '.$key['foreign_table'],
        $schema->getForeignKeys($table),
    );

    expect($foreignKeys('alerts'))->toContain('health_check_id → health_checks')
        ->and($foreignKeys('health_check_runs'))->toContain('health_check_id → health_checks');
});

/**
 * The structural pin — the one that catches a broken order on SQLite, where the two
 * tests above stay green against a dangling foreign key (proved on shops #30 and teams
 * #31, where re-breaking the order left them both passing).
 *
 * Read every foreign key out of the migration sources and assert the parent's CREATE
 * really does sort before the child's. Alerts declares them in BOTH of Laravel's forms
 * — `->constrained('health_checks')` and `->references('id')->on('health_checks')` —
 * so both are parsed.
 */
it('creates every foreign key target before the table that references it', function (): void {
    $sources = glob(__DIR__.'/../../database/migrations/*.php');
    sort($sources);

    /** @var array<string, int> $createdAt */
    $createdAt = [];
    /** @var list<array{child: string, parent: string, at: int}> $edges */
    $edges = [];

    foreach ($sources as $position => $source) {
        $body = (string) file_get_contents($source);

        // A CREATE registers its table; an ALTER references one already created.
        preg_match("/Schema::(create|table)\('([a-z_]+)'/", $body, $schema);
        expect($schema)->not->toBeEmpty();

        $table = $schema[2];

        if ($schema[1] === 'create') {
            $createdAt[$table] = $position;
        } else {
            expect(array_key_exists($table, $createdAt))->toBeTrue("{$table} is altered before it is created");
        }

        // Form A: `foreignId('x_id')->constrained()` (parent derived from the column
        // name) or `->constrained('explicit_table')`.
        preg_match_all(
            "/foreignId\('([a-z_]+)'\).*?->constrained\(\s*(?:'([a-z_]+)')?\s*\)/s",
            $body,
            $constrained,
            PREG_SET_ORDER,
        );

        // Form B: `->references('id')->on('parent')`, the long hand alerts uses.
        preg_match_all(
            "/foreignId\('([a-z_]+)'\)->references\('[a-z_]+'\)->on\('([a-z_]+)'\)/",
            $body,
            $referenced,
            PREG_SET_ORDER,
        );

        // Guard the guard: every foreign key declared in the source was actually paired.
        expect($constrained)->toHaveCount(substr_count($body, '->constrained('))
            ->and($referenced)->toHaveCount(substr_count($body, '->references('));

        foreach ([...$constrained, ...$referenced] as $match) {
            $parent = ($match[2] ?? '') !== ''
                ? $match[2]
                : Str::plural(Str::beforeLast($match[1], '_id'));

            $edges[] = ['child' => $table, 'parent' => $parent, 'at' => $position];
        }
    }

    // The package really does emit the two foreign keys this test guards.
    expect($edges)->toHaveCount(2);

    foreach ($edges as $edge) {
        expect($createdAt)->toHaveKey($edge['parent']);

        // A self-referencing key would be created by its own file; alerts has none.
        $edge['parent'] === $edge['child']
            ? expect($createdAt[$edge['parent']])->toBe($edge['at'])
            : expect($createdAt[$edge['parent']])->toBeLessThan(
                $edge['at'],
                "{$edge['child']} references {$edge['parent']}, which must be created first",
            );
    }
});
