<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('escrow_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('discount_amount', 20, 2);
            $table->foreignUuid('posting_batch_id')->nullable()->constrained('posting_batches');
            $table->string('idempotency_key', 100)->unique();
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'voucher_id']);
            $table->index(['tenant_id', 'user_id']);
            $table->index(['voucher_id', 'user_id']);
        });

        DB::statement("ALTER TABLE voucher_redemptions ENABLE ROW LEVEL SECURITY");
        DB::statement("
            CREATE POLICY tenant_isolation_policy ON voucher_redemptions
            USING (tenant_id = current_setting('app.current_tenant_id')::bigint)
        ");

        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_voucher_redemption_mutation() RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'voucher_redemptions table is immutable';
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER prevent_voucher_redemption_update
                BEFORE UPDATE ON voucher_redemptions
                FOR EACH ROW EXECUTE FUNCTION prevent_voucher_redemption_mutation();

            CREATE TRIGGER prevent_voucher_redemption_delete
                BEFORE DELETE ON voucher_redemptions
                FOR EACH ROW EXECUTE FUNCTION prevent_voucher_redemption_mutation();
        ");
    }

    public function down(): void
    {
        DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON voucher_redemptions");
        DB::statement("DROP TRIGGER IF EXISTS prevent_voucher_redemption_delete ON voucher_redemptions");
        DB::statement("DROP TRIGGER IF EXISTS prevent_voucher_redemption_update ON voucher_redemptions");
        DB::statement("DROP FUNCTION IF EXISTS prevent_voucher_redemption_mutation()");
        Schema::dropIfExists('voucher_redemptions');
    }
};
