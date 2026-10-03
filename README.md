<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/alerts-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=alerts-for-laravel">
    <img src="art/hero.png" alt="Alerts for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/alerts-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/alerts-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/alerts-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/alerts-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/alerts-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/alerts-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=alerts-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# Alerts for Laravel

Schedule recurring health checks against any notifiable model — your own `Check` classes or the
built-in database, cache, queue, storage and HTTP checks. A failing check opens an alert and sends
throttled, escalating notifications; a recovered one closes it, with run history, uptime and a
status report on top.

## Installation

Requires PHP 8.4 and Laravel 12 or 13.

```bash
composer require roundly-consulting/alerts-for-laravel
php artisan vendor:publish --tag="alerts-migrations"
php artisan migrate
```

If your owner models have UUID/ULID keys, set `ALERTS_KEY_TYPE` **before** migrating. Due checks
are dispatched as queued jobs by the auto-scheduled `alerts:perform-health-checks`, so keep the
scheduler and a queue worker running.

## Usage

Register checks once, in a service provider's `boot()` — the built-ins ship a default notification:

```php
use RoundlyConsulting\Alerts\Checks\DatabaseCheck;
use RoundlyConsulting\Alerts\Checks\HttpPingCheck;
use RoundlyConsulting\Alerts\Facades\Health;

Health::check(DatabaseCheck::make());
Health::check(HttpPingCheck::make('https://api.example.com')->timeout(5)->slowerThan(800));
```

Let the owner model decide who gets notified:

```php
use Closure;
use RoundlyConsulting\Alerts\Interfaces\HasNotifiablesForAlerts;
use RoundlyConsulting\Alerts\Traits\UsesHealthChecks;

final class Team extends Model implements HasNotifiablesForAlerts
{
    use UsesHealthChecks;

    public function forEachNotifiableForAlerts(Closure $callback): void
    {
        $this->members->each(fn (User $member) => $callback($member));
    }
}
```

Monitor a check for that owner — a failure opens an alert and notifies the team, a recovery
closes it:

```php
Health::for($team)->monitor(HttpPingCheck::class)
    ->everyFiveMinutes()
    ->failAfter(3)                                // open an alert only after 3 failures in a row
    ->save();

Health::for($team)->run(HttpPingCheck::class);    // or run it now: a CheckResult
Health::report()->isHealthy();                    // bool, across every scheduled check
```

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/alerts-for-laravel](https://roundly-consulting.com/open-source/docs/alerts-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=alerts-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=alerts-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=alerts-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
