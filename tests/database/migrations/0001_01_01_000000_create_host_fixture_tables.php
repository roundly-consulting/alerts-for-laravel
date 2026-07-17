<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The host-owned tables the alerts fixtures live in.
 *
 * These are not the package's — a host brings its own notifiables, and alerts reaches them
 * only through `morphs('notifiable')`, which is deliberately unconstrained. They used to be
 * built by an inline `Schema::create()` in `tests/TestCase.php`; as a real migration source
 * they are loaded by the same code path as the package's own, which is what lets the base
 * case's drop-and-remigrate reset rebuild them on a real engine between tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
    }
};
