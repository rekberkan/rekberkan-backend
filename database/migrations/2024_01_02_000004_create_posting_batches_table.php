<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posting_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('rrn', 24)->index();
            $table->string('stan', 12)->index();
            $table->string('mti_phase', 20);
            $table->string('idempotency_key')->index();
            $table->bigInteger('total_debits')->default(0);
            $table->bigInteger('total_credits')->default(0);
            $table->timestamp('posted_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'rrn']);
            $table->index(['tenant_id', 'idempotency_key']);
            $table->index('posted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posting_batches');
    }
};
