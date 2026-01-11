<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_behavior_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('event_type', [
                'VOUCHER_REDEMPTION_ATTEMPT',
                'VOUCHER_REDEMPTION_SUCCESS',
                'VOUCHER_REDEMPTION_FAILED',
                'AUTH_FAILURE',
                'RAPID_WITHDRAWAL',
                'DEVICE_CHANGE',
                'IP_CHANGE',
                'ESCROW_CANCELLATION',
                'SUSPICIOUS_ACTIVITY'
            ]);
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['tenant_id', 'event_type', 'created_at']);
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE user_behavior_log ENABLE ROW LEVEL SECURITY");
        DB::statement("
            CREATE POLICY tenant_isolation_policy ON user_behavior_log
            USING (tenant_id = current_setting('app.current_tenant_id')::bigint)
        ");

        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_behavior_log_mutation() RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'user_behavior_log table is append-only';
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER prevent_behavior_log_update
                BEFORE UPDATE ON user_behavior_log
                FOR EACH ROW EXECUTE FUNCTION prevent_behavior_log_mutation();

            CREATE TRIGGER prevent_behavior_log_delete
                BEFORE DELETE ON user_behavior_log
                FOR EACH ROW EXECUTE FUNCTION prevent_behavior_log_mutation();
        ");
    }

    public function down(): void
    {
        DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON user_behavior_log");
        DB::statement("DROP TRIGGER IF EXISTS prevent_behavior_log_delete ON user_behavior_log");
        DB::statement("DROP TRIGGER IF EXISTS prevent_behavior_log_update ON user_behavior_log");
        DB::statement("DROP FUNCTION IF EXISTS prevent_behavior_log_mutation()");
        Schema::dropIfExists('user_behavior_log');
    }
};
