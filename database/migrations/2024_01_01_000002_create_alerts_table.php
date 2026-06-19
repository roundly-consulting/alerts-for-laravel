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
            $table->string('message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamp('recovered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
