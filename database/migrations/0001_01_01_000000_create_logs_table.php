<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create logs table.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('level');
            $table->string('channel')->nullable();
            $table->longText('message');
            $table->json('context')->nullable();
            $table->json('extra')->nullable();
            // Use dateTime, not timestamp: MySQL TIMESTAMP has a 2038 ceiling
            // and session-timezone conversion. DATETIME(6) stores values
            // verbatim across MySQL, PostgreSQL, and SQLite.
            $table->dateTime('created_at', 6)->useCurrent();
            $table->index('level');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('logs');
    }
};
