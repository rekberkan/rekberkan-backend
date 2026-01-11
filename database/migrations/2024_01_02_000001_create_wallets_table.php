<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->bigInteger('available_balance')->default(0);
            $table->bigInteger('locked_balance')->default(0);
            $table->string('currency', 3)->default('IDR');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index('user_id');
        });

        // Add check constraints to prevent negative balances
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_available_balance_positive CHECK (available_balance >= 0)');
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_locked_balance_positive CHECK (locked_balance >= 0)');

    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
