<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('account_type', 30);
            $table->unsignedBigInteger('account_id');
            $table->bigInteger('balance')->default(0);
            $table->string('currency', 3)->default('IDR');
            $table->foreignUuid('last_posting_batch_id')->nullable()->constrained('posting_batches');
            $table->timestamps();

            $table->unique(['tenant_id', 'account_type', 'account_id']);
            $table->index(['account_type', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_balances');
    }
};
