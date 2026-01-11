<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escrows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            
            // Parties
            $table->foreignId('buyer_id')->constrained('users')->onDelete('restrict');
            $table->foreignId('seller_id')->constrained('users')->onDelete('restrict');
            $table->foreignId('buyer_wallet_id')->constrained('wallets')->onDelete('restrict');
            $table->foreignId('seller_wallet_id')->constrained('wallets')->onDelete('restrict');
            
            // Amounts
            $table->bigInteger('amount');
            $table->bigInteger('fee_amount');
            $table->string('currency', 3)->default('IDR');
            
            // State
            $table->string('status', 20)->default('CREATED');
            
            // Metadata
            $table->string('title');
            $table->text('description')->nullable();
            
            // Timestamps
            $table->timestamp('funded_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            
            // SLA deadlines
            $table->timestamp('sla_auto_release_at')->nullable();
            $table->timestamp('sla_auto_refund_at')->nullable();
            
            // Ledger references
            $table->foreignUuid('auth_posting_batch_id')->nullable()->constrained('posting_batches');
            $table->foreignUuid('settlement_posting_batch_id')->nullable()->constrained('posting_batches');
            
            // Idempotency
            $table->string('idempotency_key')->unique();
            $table->json('metadata')->nullable();
            
            $table->timestamps();

            // Indexes
            $table->index(['tenant_id', 'buyer_id']);
            $table->index(['tenant_id', 'seller_id']);
            $table->index('status');
            $table->index('sla_auto_release_at');
            $table->index('sla_auto_refund_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escrows');
    }
};
