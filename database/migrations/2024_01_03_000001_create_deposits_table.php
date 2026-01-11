<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('wallet_id')->constrained()->onDelete('cascade');
            
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('IDR');
            $table->string('payment_method', 30);
            $table->string('status', 20)->default('PENDING');
            
            // Gateway fields
            $table->string('gateway_transaction_id')->nullable();
            $table->string('gateway_reference')->nullable()->index();
            $table->json('gateway_response')->nullable();
            
            // Completion tracking
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            
            // Idempotency
            $table->string('idempotency_key')->unique();
            
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};
