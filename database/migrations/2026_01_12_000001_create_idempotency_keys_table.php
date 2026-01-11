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
            $table->string('idempotency_key', 128);
            $table->string('request_path');
            $table->string('request_method', 10);
            $table->integer('response_status');
            $table->json('response_headers')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamps();

            // Composite unique constraint including path and method
            $table->unique(
                ['tenant_id', 'idempotency_key', 'request_path', 'request_method'],
                'idempotency_unique'
            );

            // Index for cleanup queries
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
