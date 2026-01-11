<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            
            // ISO 8583 fields
            $table->string('mti_phase', 20); // AUTH, REVERSAL, PRESENTMENT, ADJUSTMENT
            $table->string('processing_code', 30); // DEPOSIT, WITHDRAW, ESCROW_LOCK, etc.
            $table->string('stan', 12)->index(); // System Trace Audit Number
            $table->string('rrn', 24)->index(); // Retrieval Reference Number
            $table->string('idempotency_key')->index();
            
            // Transaction details
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('IDR');
            
            // Related entity (e.g., Escrow, Deposit, Withdrawal)
            $table->string('related_entity_type')->nullable();
            $table->uuid('related_entity_id')->nullable();
            $table->string('originating_channel')->nullable();
            
            // Lifecycle linking
            $table->unsignedBigInteger('auth_id')->nullable();
            $table->unsignedBigInteger('capture_id')->nullable();
            $table->unsignedBigInteger('reversal_id')->nullable();
            
            // Response
            $table->string('response_code', 30);
            $table->text('response_message')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamps();

            $table->index(['tenant_id', 'rrn']);
            $table->index(['tenant_id', 'stan']);
            $table->index(['tenant_id', 'idempotency_key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_messages');
    }
};
