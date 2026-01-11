<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enable Row Level Security on tenant-scoped tables.
     * 
     * RLS provides defense-in-depth for tenant isolation.
     * Even if application logic fails, database enforces boundaries.
     */
    public function up(): void
    {
        // Enable RLS on tenant-scoped tables
        $tenantTables = [
            'users',
            'wallets',
            'deposits',
            'withdrawals',
            'escrows',
            'escrow_timelines',
            'chat_messages',
            'financial_messages',
            'ledger_lines',
            'account_balances',
        ];

        foreach ($tenantTables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            
            // Policy: Users can only access their tenant's data
            DB::statement("
                CREATE POLICY tenant_isolation_policy ON {$table}
                USING (tenant_id = current_setting('app.tenant_id', TRUE)::uuid)
            ");
            
            // Policy: Allow inserts with correct tenant_id
            DB::statement("
                CREATE POLICY tenant_isolation_insert ON {$table}
                FOR INSERT
                WITH CHECK (tenant_id = current_setting('app.tenant_id', TRUE)::uuid)
            ");
        }

        // Platform wallets are not tenant-scoped (system-level)
        DB::statement("ALTER TABLE platform_wallets ENABLE ROW LEVEL SECURITY");
        DB::statement("
            CREATE POLICY platform_wallet_policy ON platform_wallets
            USING (true)
        ");

        // Posting batches are tenant-scoped by tenant_id
        DB::statement("ALTER TABLE posting_batches ENABLE ROW LEVEL SECURITY");
        DB::statement("
            CREATE POLICY posting_batch_policy ON posting_batches
            USING (
                tenant_id = current_setting('app.tenant_id', TRUE)::uuid
            )
        ");
    }

    /**
     * Disable Row Level Security (for rollback only).
     */
    public function down(): void
    {
        $tenantTables = [
            'users',
            'wallets',
            'deposits',
            'withdrawals',
            'escrows',
            'escrow_timelines',
            'chat_messages',
            'financial_messages',
            'ledger_lines',
            'account_balances',
            'platform_wallets',
            'posting_batches',
        ];

        foreach ($tenantTables as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON {$table}");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_insert ON {$table}");
            DB::statement("DROP POLICY IF EXISTS platform_wallet_policy ON {$table}");
            DB::statement("DROP POLICY IF EXISTS posting_batch_policy ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
