<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateEscrowColumn('campaign_participations', 'SET NULL');
        $this->updateEscrowColumn('voucher_redemptions', 'SET NULL');
        $this->updateEscrowColumn('disputes', 'CASCADE');
    }

    public function down(): void
    {
        // Irreversible without losing UUID data.
    }

    private function updateEscrowColumn(string $table, string $onDelete): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'escrow_id')) {
            return;
        }

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_escrow_id_foreign");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN escrow_id TYPE uuid USING escrow_id::uuid");
        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$table}_escrow_id_foreign "
            . "FOREIGN KEY (escrow_id) REFERENCES escrows(id) ON DELETE {$onDelete}"
        );
    }
};
