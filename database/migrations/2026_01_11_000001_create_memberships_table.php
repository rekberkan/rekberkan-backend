<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEW MIGRATION: Memberships table untuk tiered subscription system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id');
            
            $table->enum('tier', ['free', 'bronze', 'silver', 'gold', 'platinum'])
                ->default('free');
            
            $table->enum('status', ['active', 'expired', 'cancelled', 'suspended'])
                ->default('active');
            
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->nullable();
            
            $table->boolean('auto_renew')->default(true);
            $table->string('payment_method')->nullable();
            
            $table->jsonb('metadata')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('user_id');
            $table->index('tenant_id');
            $table->index(['user_id', 'status']);
            $table->index('expires_at');
            
            // Foreign keys
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });

        // Create index for finding expiring memberships
        Schema::table('memberships', function (Blueprint $table) {
            $table->index(['status', 'expires_at'], 'idx_expiring_memberships');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
