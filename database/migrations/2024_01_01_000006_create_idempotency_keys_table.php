<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('idempotency_key', 255);
            $table->string('request_path');
            $table->string('request_method', 10);
            $table->integer('response_status')->nullable();
            $table->timestamp('created_at');

            // Unique constraint: one idempotency key per tenant
            $table->unique(['tenant_id', 'idempotency_key'], 'tenant_idempotency_unique');
            $table->index('created_at');
        });

        // Auto-cleanup old idempotency keys (after 24 hours)
        // This will be handled by a scheduled job
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
