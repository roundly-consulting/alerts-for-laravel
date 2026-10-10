<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Alerts\Support\Identity;

/**
 * Gives every immediate silence (`starts_at` null — all `mute()` writes) an identity: its key
 * and its scope, hashed. A unique index on it makes `mute()` an upsert.
 *
 * Before 1.2 every `mute()` inserted, so a host may hold several immediate silences of one
 * identity. They are collapsed first without changing what is muted: the newest row
 * survives, open-ended if any of them was, else until the latest end, with the newest
 * reason. A silence with `starts_at` set is left as it is, without an identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_silences', function (Blueprint $table): void {
            $table->char('identity_hash', 64)->nullable();
        });

        $this->collapseDuplicates();
        $this->backfill();

        Schema::table('alert_silences', function (Blueprint $table): void {
            $table->unique('identity_hash', 'alert_silences_identity_hash_unique');
        });
    }

    private function collapseDuplicates(): void
    {
        $groups = $this->silences()
            ->whereNull('starts_at')
            ->select(['key', 'notifiable_type', 'notifiable_id'])
            ->groupBy(['key', 'notifiable_type', 'notifiable_id'])
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $rows = $this->silences()
                ->whereNull('starts_at')
                ->where('key', $group->key)
                ->where(fn (Builder $q) => $group->notifiable_type === null
                    ? $q->whereNull('notifiable_type')
                    : $q->where('notifiable_type', $group->notifiable_type))
                ->where(fn (Builder $q) => $group->notifiable_id === null
                    ? $q->whereNull('notifiable_id')
                    : $q->where('notifiable_id', $group->notifiable_id))
                ->orderByDesc('id')
                ->get();

            $keep = $rows->first();

            $endsAt = $rows->contains(fn (object $row): bool => $row->ends_at === null)
                ? null
                : $rows->max('ends_at');

            $this->silences()->where('id', $keep->id)->update([
                'ends_at' => $endsAt,
                'reason' => $rows->first(fn (object $row): bool => $row->reason !== null)?->reason,
            ]);

            $this->silences()
                ->whereIn('id', $rows->skip(1)->pluck('id')->all())
                ->delete();
        }
    }

    private function backfill(): void
    {
        $this->silences()
            ->whereNull('starts_at')
            ->whereNull('identity_hash')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $this->silences()->where('id', $row->id)->update([
                        'identity_hash' => Identity::silence($row->key, $row->notifiable_type, $row->notifiable_id),
                    ]);
                }
            });
    }

    private function silences(): Builder
    {
        return Schema::getConnection()->table('alert_silences');
    }
};
