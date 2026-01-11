<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('engine_version', 20);
            $table->unsignedTinyInteger('risk_score');
            $table->enum('risk_tier', ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL']);
            $table->json('signals');
            $table->json('actions_taken');
            $table->json('metadata');
            $table->timestamp('assessed_at');

            $table->index(['tenant_id', 'user_id', 'assessed_at']);
            $table->index(['tenant_id', 'risk_tier']);
            $table->index('assessed_at');
        });

        DB::statement("ALTER TABLE risk_assessments ENABLE ROW LEVEL SECURITY");
        DB::statement("
            CREATE POLICY tenant_isolation_policy ON risk_assessments
            USING (tenant_id = current_setting('app.current_tenant_id')::bigint)
        ");

        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_risk_assessment_mutation() RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'risk_assessments table is immutable';
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER prevent_risk_assessment_update
                BEFORE UPDATE ON risk_assessments
                FOR EACH ROW EXECUTE FUNCTION prevent_risk_assessment_mutation();

            CREATE TRIGGER prevent_risk_assessment_delete
                BEFORE DELETE ON risk_assessments
                FOR EACH ROW EXECUTE FUNCTION prevent_risk_assessment_mutation();
        ");
    }

    public function down(): void
    {
        DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON risk_assessments");
        DB::statement("DROP TRIGGER IF EXISTS prevent_risk_assessment_delete ON risk_assessments");
        DB::statement("DROP TRIGGER IF EXISTS prevent_risk_assessment_update ON risk_assessments");
        DB::statement("DROP FUNCTION IF EXISTS prevent_risk_assessment_mutation()");
        Schema::dropIfExists('risk_assessments');
    }
};
