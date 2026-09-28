<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

return new class extends Migration
{
    public function up(): void
    {
        $keyType = KeyType::fromConfig('alerts.key_type');

        Schema::create('health_checks', function (Blueprint $table) use ($keyType): void {
            $table->id();
            $table->morphKey('notifiable', $keyType, nullable: false);
            $table->string('health_check');
            // NULL = an on-demand row seeded by "run now": it keeps counters, history and
            // alerts for that owner + check, but the scheduler never queues it.
            $table->string('frequency')->nullable();
            $table->unsignedSmallInteger('max_attempts')->default(1);
            $table->unsignedSmallInteger('decay_minutes')->default(1);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('consecutive_successes')->default(0);
            $table->jsonb('tags')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
