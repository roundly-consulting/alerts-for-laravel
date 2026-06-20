<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_checks', function (Blueprint $table): void {
            $table->id();
            $table->morphs('notifiable');
            $table->string('health_check');
            $table->string('frequency');
            $table->unsignedSmallInteger('max_attempts')->default(1);
            $table->unsignedSmallInteger('decay_minutes')->default(1);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('consecutive_successes')->default(0);
            $table->json('tags')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
