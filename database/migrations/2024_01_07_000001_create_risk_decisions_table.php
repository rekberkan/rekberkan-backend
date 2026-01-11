<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->json('input_snapshot');
            $table->string('snapshot_hash', 64)->index();
            $table->unsignedTinyInteger('score');
            $table->enum('action', ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL']);
            $table->string('engine_version', 20);
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'subject_type', 'subject_id']);
            $table->index(['tenant_id', 'created_at']);
            $table->index('action');
        });

        DB::statement("ALTER TABLE risk_decisions ENABLE ROW LEVEL SECURITY");
        DB::statement("
            CREATE POLICY tenant_isolation_policy ON risk_decisions
            USING (tenant_id = current_setting('app.current_tenant_id')::bigint)
        ");

        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_risk_decision_mutation() RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'risk_decisions table is immutable';
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER prevent_risk_decision_update
                BEFORE UPDATE ON risk_decisions
                FOR EACH ROW EXECUTE FUNCTION prevent_risk_decision_mutation();

            CREATE TRIGGER prevent_risk_decision_delete
                BEFORE DELETE ON risk_decisions
                FOR EACH ROW EXECUTE FUNCTION prevent_risk_decision_mutation();
        ");
    }

    public function down(): void
    {
        DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON risk_decisions");
        DB::statement("DROP TRIGGER IF EXISTS prevent_risk_decision_delete ON risk_decisions");
        DB::statement("DROP TRIGGER IF EXISTS prevent_risk_decision_update ON risk_decisions");
        DB::statement("DROP FUNCTION IF EXISTS prevent_risk_decision_mutation()");
        Schema::dropIfExists('risk_decisions');
    }
};
