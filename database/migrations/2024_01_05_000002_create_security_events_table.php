<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('user_id')->nullable()->index();
            $table->string('event_type', 50)->index(); // login, logout, token_refresh, device_change, etc.
            $table->string('ip_address', 45);
            $table->string('user_agent', 500)->nullable();
            $table->uuid('device_id')->nullable()->index();
            $table->string('country_code', 2)->nullable();
            $table->json('metadata')->nullable(); // Additional context
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->boolean('is_suspicious')->default(false)->index();
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'created_at']);
            $table->index(['user_id', 'event_type', 'created_at']);
            $table->index(['is_suspicious', 'severity']);
        });

        // Partition by month for performance (TimescaleDB)
        $extensionExists = DB::table('pg_extension')
            ->where('extname', 'timescaledb')
            ->exists();

        if (! $extensionExists) {
            return;
        }

        try {
            DB::statement("
                SELECT create_hypertable('security_events', 'created_at', if_not_exists => TRUE)
            ");
        } catch (Throwable $exception) {
            // Skip hypertable creation if TimescaleDB is unavailable or restricted.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
