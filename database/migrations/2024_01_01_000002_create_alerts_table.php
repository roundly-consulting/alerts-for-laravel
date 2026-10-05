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

        Schema::create('alerts', function (Blueprint $table) use ($keyType): void {
            $table->id();
            $table->morphKey('notifiable', $keyType, nullable: false);
            $table->foreignId('health_check_id')->references('id')->on('health_checks')->onDelete('cascade');
            $table->string('status')->default('failed');
            $table->unsignedSmallInteger('escalation_level')->default(0);
            $table->text('message')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamp('recovered_at')->nullable();
            // 1 while this is the check's open alert, NULL once it recovers. NULLs never
            // collide in a unique index on MySQL and PostgreSQL (and SQLite), so the index
            // below admits any number of closed alerts but only ONE open alert per scheduled
            // check — two overlapping failing runs cannot both open one. SQL Server treats
            // NULLs as equal here and is not a supported database.
            $table->unsignedTinyInteger('open_slot')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['notifiable_type', 'notifiable_id', 'health_check_id'], 'alerts_notifiable_health_check_index');
            $table->unique(['health_check_id', 'open_slot'], 'alerts_one_open_per_health_check');
        });
    }
};
