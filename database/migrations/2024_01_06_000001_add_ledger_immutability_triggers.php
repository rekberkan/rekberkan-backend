<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add triggers to enforce ledger immutability.
     * 
     * Financial ledger entries must be append-only.
     * Prevents UPDATE and DELETE operations on ledger tables.
     */
    public function up(): void
    {
        // Trigger function to prevent modifications
        DB::statement("
            CREATE OR REPLACE FUNCTION prevent_ledger_modification()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Ledger entries are immutable. Operation % not allowed on table %',
                    TG_OP, TG_TABLE_NAME
                USING ERRCODE = '23502';
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        ");

        // Apply to ledger_lines table
        DB::statement("
            CREATE TRIGGER prevent_ledger_lines_update
            BEFORE UPDATE ON ledger_lines
            FOR EACH ROW EXECUTE FUNCTION prevent_ledger_modification();
        ");

        DB::statement("
            CREATE TRIGGER prevent_ledger_lines_delete
            BEFORE DELETE ON ledger_lines
            FOR EACH ROW EXECUTE FUNCTION prevent_ledger_modification();
        ");

        // Apply to posting_batches table
        DB::statement("
            CREATE TRIGGER prevent_posting_batches_update
            BEFORE UPDATE ON posting_batches
            FOR EACH ROW EXECUTE FUNCTION prevent_ledger_modification();
        ");

        DB::statement("
            CREATE TRIGGER prevent_posting_batches_delete
            BEFORE DELETE ON posting_batches
            FOR EACH ROW EXECUTE FUNCTION prevent_ledger_modification();
        ");

        // Apply to financial_messages table
        DB::statement("
            CREATE TRIGGER prevent_financial_messages_update
            BEFORE UPDATE ON financial_messages
            FOR EACH ROW EXECUTE FUNCTION prevent_ledger_modification();
        ");

        DB::statement("
            CREATE TRIGGER prevent_financial_messages_delete
            BEFORE DELETE ON financial_messages
            FOR EACH ROW EXECUTE FUNCTION prevent_ledger_modification();
        ");

        // Add CHECK constraint for non-negative balances
        DB::statement("
            ALTER TABLE account_balances
            ADD CONSTRAINT positive_balance_check
            CHECK (balance >= 0)
        ");

        // Add CHECK constraint for ledger line amounts
        DB::statement("
            ALTER TABLE ledger_lines
            ADD CONSTRAINT positive_amount_check
            CHECK (amount > 0)
        ");
    }

    /**
     * Remove immutability triggers (for rollback only).
     */
    public function down(): void
    {
        // Drop triggers
        DB::statement('DROP TRIGGER IF EXISTS prevent_ledger_lines_update ON ledger_lines');
        DB::statement('DROP TRIGGER IF EXISTS prevent_ledger_lines_delete ON ledger_lines');
        DB::statement('DROP TRIGGER IF EXISTS prevent_posting_batches_update ON posting_batches');
        DB::statement('DROP TRIGGER IF EXISTS prevent_posting_batches_delete ON posting_batches');
        DB::statement('DROP TRIGGER IF EXISTS prevent_financial_messages_update ON financial_messages');
        DB::statement('DROP TRIGGER IF EXISTS prevent_financial_messages_delete ON financial_messages');

        // Drop function
        DB::statement('DROP FUNCTION IF EXISTS prevent_ledger_modification()');

        // Drop constraints
        DB::statement('ALTER TABLE account_balances DROP CONSTRAINT IF EXISTS positive_balance_check');
        DB::statement('ALTER TABLE ledger_lines DROP CONSTRAINT IF EXISTS positive_amount_check');
    }
};
