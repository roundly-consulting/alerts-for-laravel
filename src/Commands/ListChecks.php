<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Alerts\Actions\BuildHealthReportAction;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Status\CheckStatus;

final class ListChecks extends Command
{
    protected $signature = 'alerts:list {--tag= : Only list checks carrying this tag}';

    protected $description = 'List every registered check with its frequency, status, and tags';

    public function handle(BuildHealthReportAction $action): int
    {
        $tag = $this->option('tag');
        $tag = is_string($tag) && $tag !== '' ? $tag : null;

        $checks = Health::all();

        if ($tag !== null) {
            $checks = $checks->filter(fn (Check $check): bool => in_array($tag, $check->tags(), true)
                || $this->rowHasTag($check->key(), $tag));
        }

        if ($checks->isEmpty()) {
            $this->info('No checks are registered.');

            return self::SUCCESS;
        }

        $statuses = collect($action->execute()->checks())
            ->keyBy(fn (CheckStatus $status): string => $status->key);

        $rows = $checks->map(function (Check $check) use ($statuses, $tag): array {
            $row = $this->scheduledRow($check->key(), $tag);
            $status = $statuses->get($check->key());

            return [
                $check->key(),
                $check->name(),
                $row === null ? '—' : $this->frequencyLabel($check, $row->frequency),
                $row?->latestRun()?->ran_at?->toDateTimeString() ?? '—',
                $status?->status->label() ?? '—',
                Health::isMuted($check->key()) ? 'yes' : 'no',
                $this->tagList($check, $row),
            ];
        })->all();

        $this->table(
            ['Key', 'Name', 'Frequency', 'Last Run', 'Status', 'Muted?', 'Tags'],
            $rows,
        );

        return self::SUCCESS;
    }

    private function scheduledRow(string $key, ?string $tag): ?HealthCheck
    {
        /** @var class-string<HealthCheck> $model */
        $model = config('alerts.health-check', HealthCheck::class);

        $query = $model::query()->where('health_check', $key);

        if ($tag !== null) {
            $query->whereJsonContains('tags', $tag);
        }

        return $query->latest('id')->first();
    }

    private function rowHasTag(string $key, string $tag): bool
    {
        /** @var class-string<HealthCheck> $model */
        $model = config('alerts.health-check', HealthCheck::class);

        return $model::query()
            ->where('health_check', $key)
            ->whereJsonContains('tags', $tag)
            ->exists();
    }

    private function frequencyLabel(Check $check, string $cron): string
    {
        return $check->frequencies()[$cron] ?? $cron;
    }

    private function tagList(Check $check, ?HealthCheck $row): string
    {
        $rowTags = $row === null ? [] : ($row->tags ?? []);
        $tags = array_values(array_unique([...$check->tags(), ...$rowTags]));

        return $tags === [] ? '—' : implode(', ', $tags);
    }
}
