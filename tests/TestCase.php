<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Alerts\AlertsServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    /**
     * Every provider alerts hard-requires, in registration order. A host auto-discovers
     * these; the suite must list them or the test environment is a fiction.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function packageProviders(): array
    {
        return [AlertsServiceProvider::class];
    }

    /**
     * The alerts migrations, named by provider class (never by filename), plus the
     * host-owned fixture tables the notifiables live in.
     *
     * @return list<class-string<ServiceProvider>|string>
     */
    protected function migrationSources(): array
    {
        return [
            AlertsServiceProvider::class,
            __DIR__.'/database/migrations',
        ];
    }

    /**
     * Migrate once per worker and schema signature; each test still starts on empty tables
     * with the key counters where a fresh migrate leaves them.
     */
    protected function keepsMigratedSchema(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return ['mail.default' => 'array'];
    }
}
