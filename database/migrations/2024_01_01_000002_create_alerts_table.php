<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table): void {
            $table->id();
            $table->morphs('notifiable');
            $table->foreignId('health_check_id')->references('id')->on('health_checks')->onDelete('cascade');
            $table->string('status')->default('failed');
            $table->unsignedSmallInteger('escalation_level')->default(0);
            $table->string('message')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamp('recovered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['notifiable_type', 'notifiable_id', 'health_check_id'], 'alerts_notifiable_health_check_index');
        });
    }
};
