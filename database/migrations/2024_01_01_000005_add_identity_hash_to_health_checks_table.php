<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The identity of a live schedule of a check that declares `uniqueBy()`: its notifiable, the
 * check key and the named meta values, hashed. The unique index makes `schedule()` of such a
 * check an upsert. NULL — on-demand rows, other checks, soft-deleted rows — never collides on
 * MySQL, PostgreSQL or SQLite.
 *
 * No backfill: the identity depends on the checks a host registers, which a migration cannot
 * see. A schedule written before 1.2 is adopted the first time `schedule()` meets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_checks', function (Blueprint $table): void {
            $table->char('identity_hash', 64)->nullable();
            $table->unique('identity_hash', 'health_checks_identity_hash_unique');
        });
    }
};
