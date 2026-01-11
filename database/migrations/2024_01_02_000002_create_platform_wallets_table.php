<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->bigInteger('available_balance')->default(0);
            $table->bigInteger('locked_balance')->default(0);
            $table->bigInteger('total_fees_collected')->default(0);
            $table->string('currency', 3)->default('IDR');
            $table->timestamps();

            $table->unique('tenant_id');
        });

        DB::statement('ALTER TABLE platform_wallets ADD CONSTRAINT chk_platform_available_positive CHECK (available_balance >= 0)');
        DB::statement('ALTER TABLE platform_wallets ADD CONSTRAINT chk_platform_locked_positive CHECK (locked_balance >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_wallets');
    }
};
