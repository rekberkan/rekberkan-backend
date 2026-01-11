<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('withdraw_delay_until')->nullable()->after('status');
            $table->timestamp('wallet_frozen_at')->nullable()->after('withdraw_delay_until');
            $table->string('wallet_frozen_reason')->nullable()->after('wallet_frozen_at');
            $table->boolean('kyc_required')->default(false)->after('wallet_frozen_reason');
            
            $table->index('withdraw_delay_until');
            $table->index('wallet_frozen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'withdraw_delay_until',
                'wallet_frozen_at',
                'wallet_frozen_reason',
                'kyc_required',
            ]);
        });
    }
};
