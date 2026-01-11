<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('webhook_id')->unique();
            $table->string('event_type', 50);
            $table->json('payload');
            $table->text('signature');
            $table->boolean('signature_verified')->default(false);
            $table->boolean('processed')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamps();

            $table->index('webhook_id');
            $table->index(['event_type', 'processed']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_logs');
    }
};
