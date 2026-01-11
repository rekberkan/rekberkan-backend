<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('posting_batch_id')->constrained()->onDelete('restrict');
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            
            // Account identification
            $table->string('account_type', 30); // CUSTOMER_AVAILABLE, CUSTOMER_LOCKED, etc.
            $table->unsignedBigInteger('account_id'); // Wallet ID or PlatformWallet ID
            
            // Double-entry amounts (only one should be > 0)
            $table->bigInteger('debit_amount')->default(0);
            $table->bigInteger('credit_amount')->default(0);
            $table->bigInteger('balance_after');
            
            $table->string('currency', 3)->default('IDR');
            $table->string('description')->nullable();
            $table->timestamp('created_at');

            $table->index(['posting_batch_id']);
            $table->index(['tenant_id', 'account_type', 'account_id']);
            $table->index('created_at');
        });

        // Create trigger to prevent updates and deletes
        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_ledger_modification()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Ledger lines are immutable and cannot be modified or deleted';
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        ");

        DB::unprepared("
            CREATE TRIGGER prevent_ledger_update
            BEFORE UPDATE ON ledger_lines
            FOR EACH ROW EXECUTE FUNCTION prevent_ledger_modification();
        ");

        DB::unprepared("
            CREATE TRIGGER prevent_ledger_delete
            BEFORE DELETE ON ledger_lines
            FOR EACH ROW EXECUTE FUNCTION prevent_ledger_modification();
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_ledger_delete ON ledger_lines');
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_ledger_update ON ledger_lines');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_ledger_modification()');
        Schema::dropIfExists('ledger_lines');
    }
};
