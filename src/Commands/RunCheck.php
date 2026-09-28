<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\Support\SafeCheck;

final class RunCheck extends Command
{
    protected $signature = 'alerts:check
        {key : The registered check key}
        {--notifiable= : Optional "Model:id" to run side effects against}';

    protected $description = 'Run a single registered check now and print its result';

    public function handle(): int
    {
        /** @var string $key */
        $key = $this->argument('key');

        $check = Health::find($key);

        if ($check === null) {
            $this->error("No registered check matches [{$key}].");
            $this->line('Registered checks: '.($this->registeredKeys() ?: '—'));

            return self::FAILURE;
        }

        $notifiable = $this->resolveNotifiable();

        if ($notifiable === false) {
            return self::FAILURE;
        }

        // A probe runs with no side effects, but under the same exception guard as a
        // real run: a throwing check prints as a failed result, never a stack trace.
        $result = $notifiable instanceof Model
            ? Health::for($notifiable)->run($check)
            : SafeCheck::run($check, null, $check->key());

        $this->render($result);

        return $result->status->isAlertable() ? self::FAILURE : self::SUCCESS;
    }

    private function render(CheckResult $result): void
    {
        $rows = [
            ['Status', $result->status->label()],
            ['Message', $result->message !== '' ? $result->message : '—'],
            ['Latency (ms)', (string) ($result->meta['latency_ms'] ?? $result->meta['duration_ms'] ?? '—')],
        ];

        foreach ($result->meta as $name => $value) {
            if (in_array($name, ['latency_ms', 'duration_ms'], true)) {
                continue;
            }

            $rows[] = ['meta.'.$name, $this->stringify($value)];
        }

        $this->table(['Field', 'Value'], $rows);
    }

    private function stringify(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) ($value ?? '—');
        }

        return (string) json_encode($value);
    }

    /**
     * @return Model|null|false A model when --notifiable resolves, null when absent,
     *                          false when the option is malformed.
     */
    private function resolveNotifiable(): Model|null|false
    {
        $option = $this->option('notifiable');

        if (! is_string($option) || $option === '') {
            return null;
        }

        if (! str_contains($option, ':')) {
            $this->error('The --notifiable option must be in "Model:id" form.');

            return false;
        }

        [$class, $id] = explode(':', $option, 2);

        if (! is_a($class, Model::class, true)) {
            $this->error("[{$class}] is not an Eloquent model.");

            return false;
        }

        $model = $class::query()->find($id);

        if (! $model instanceof Model) {
            $this->error("No [{$class}] found with id [{$id}].");

            return false;
        }

        return $model;
    }

    private function registeredKeys(): string
    {
        return Health::all()->keys()->implode(', ');
    }
}
