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

        Schema::create('alert_silences', function (Blueprint $table) use ($keyType): void {
            $table->id();
            $table->string('key')->index();                      // a HealthCheck key, a tag, or '*' (global)
            $table->morphKey('notifiable', $keyType, nullable: true);  // optional scope to one notifiable
            $table->string('reason')->nullable();
            $table->timestamp('starts_at')->nullable();  // null = active immediately
            $table->timestamp('ends_at')->nullable();    // null = until manually unmuted
            $table->timestamps();
        });
    }
};
