<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Status\CheckStatus;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;

final class ListChecks extends Command
{
    protected $signature = 'alerts:list {--tag= : Only list checks carrying this tag}';

    protected $description = 'List every registered check with its frequency, status, and tags';

    public function handle(HealthManager $health): int
    {
        $tag = $this->option('tag');
        $tag = is_string($tag) && $tag !== '' ? $tag : null;

        $checks = $health->all();

        if ($tag !== null) {
            $checks = $checks->filter(fn (Check $check): bool => in_array($tag, $check->tags(), true)
                || $this->rowHasTag($check->key(), $tag));
        }

        if ($checks->isEmpty()) {
            $this->info('No checks are registered.');

            return self::SUCCESS;
        }

        $statuses = collect($health->report()->checks())
            ->keyBy(fn (CheckStatus $status): string => $status->key);

        $rows = $checks->map(function (Check $check) use ($health, $statuses, $tag): array {
            $row = $this->scheduledRow($check->key(), $tag);
            $status = $statuses->get($check->key());

            return [
                $check->key(),
                $check->name(),
                $row === null ? '—' : $this->frequencyLabel($check, $row->frequency),
                $row?->latestRun()?->ran_at?->toDateTimeString() ?? '—',
                $status?->status->label() ?? '—',
                $this->isMuted($health, $check, $row) ? 'yes' : 'no',
                $this->tagList($check, $row),
            ];
        })->all();

        $this->table(
            ['Key', 'Name', 'Frequency', 'Last Run', 'Status', 'Muted?', 'Tags'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * The newest scheduled row for the key, falling back to an on-demand (run-now) row.
     */
    private function scheduledRow(string $key, ?string $tag): ?HealthCheck
    {
        $query = HealthCheckModel::query()->where('health_check', $key);

        if ($tag !== null) {
            $query->whereJsonContains('tags', $tag);
        }

        return (clone $query)->whereNotNull('frequency')->latest('id')->first()
            ?? $query->latest('id')->first();
    }

    private function rowHasTag(string $key, string $tag): bool
    {
        return HealthCheckModel::query()
            ->where('health_check', $key)
            ->whereJsonContains('tags', $tag)
            ->exists();
    }

    private function frequencyLabel(Check $check, ?string $cron): string
    {
        if ($cron === null) {
            return 'on demand';
        }

        return $check->frequencies()[$cron] ?? $cron;
    }

    /**
     * Muted the way the run pipeline decides it: a silence on the key, on any of the
     * check's (or its row's) tags, or the global '*'.
     */
    private function isMuted(HealthManager $health, Check $check, ?HealthCheck $row): bool
    {
        foreach ([$check->key(), ...$this->tags($check, $row), AlertSilence::GLOBAL_KEY] as $key) {
            if ($health->silences()->isMuted($key)) {
                return true;
            }
        }

        return false;
    }

    private function tagList(Check $check, ?HealthCheck $row): string
    {
        $tags = $this->tags($check, $row);

        return $tags === [] ? '—' : implode(', ', $tags);
    }

    /**
     * @return list<string>
     */
    private function tags(Check $check, ?HealthCheck $row): array
    {
        $rowTags = $row === null ? [] : ($row->tags ?? []);

        return array_values(array_unique([...$check->tags(), ...$rowTags]));
    }
}
