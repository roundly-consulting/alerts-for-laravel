<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_check_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('health_check_id')->constrained('health_checks')->cascadeOnDelete();
            $table->string('status');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('ran_at')->index();

            $table->index(['health_check_id', 'ran_at']);
        });
    }
};
