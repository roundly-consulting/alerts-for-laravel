<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RoundlyConsulting\Alerts\AlertSilence;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Status\CheckStatus;
use RoundlyConsulting\Alerts\Status\HealthReport;
use RoundlyConsulting\Alerts\Support\HealthCheckModel;
use RoundlyConsulting\PackageToolkit\Support\Config;

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

        // One check is scheduled for many notifiables; its line shows the worst of them, so a
        // failure for one owner is never hidden behind another owner's healthy row.
        $statuses = collect($health->report()->checks())
            ->groupBy(fn (CheckStatus $status): string => $status->key)
            ->map(fn (Collection $group): Status => (new HealthReport(array_values($group->all())))->overall());

        $silenced = $this->globalSilences($health);

        $rows = $checks->map(function (Check $check) use ($statuses, $silenced, $tag): array {
            $row = $this->scheduledRow($check->key(), $tag);
            $status = $statuses->get($check->key());

            return [
                $check->key(),
                $check->name(),
                $row === null ? '—' : $this->frequencyLabel($check, $row->frequency),
                $row?->latestRun()?->ran_at?->toDateTimeString() ?? '—',
                $status?->label() ?? '—',
                $this->isMuted($silenced, $check, $row) ? 'yes' : 'no',
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
     * The keys of the active silences that apply to every notifiable. One scoped to a
     * single notifiable mutes that owner's runs only, so it never marks the whole check.
     *
     * @return list<string>
     */
    private function globalSilences(HealthManager $health): array
    {
        if (! Config::boolean('alerts.silence', true)) {
            return [];
        }

        return array_values($health->silences()->active()
            ->filter(fn (AlertSilence $silence): bool => $silence->notifiable_id === null)
            ->map(fn (AlertSilence $silence): string => $silence->key)
            ->all());
    }

    /**
     * Muted the way the run pipeline decides it for every notifiable: a global silence on
     * the key, on any of the check's (or its row's) tags, or the global '*'.
     *
     * @param  list<string>  $silenced
     */
    private function isMuted(array $silenced, Check $check, ?HealthCheck $row): bool
    {
        return array_intersect([$check->key(), ...$this->tags($check, $row), AlertSilence::GLOBAL_KEY], $silenced) !== [];
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
