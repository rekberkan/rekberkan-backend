<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 100)->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type', 50)->nullable();
            $table->json('metadata')->nullable();
            $table->string('prev_hash', 64)->nullable();
            $table->string('record_hash', 64)->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'event_type', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE audit_log ENABLE ROW LEVEL SECURITY");
        DB::statement("
            CREATE POLICY tenant_isolation_policy ON audit_log
            USING (tenant_id = current_setting('app.current_tenant_id')::bigint)
        ");

        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_audit_log_mutation() RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'audit_log table is WORM (Write-Once-Read-Many)';
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER prevent_audit_log_update
                BEFORE UPDATE ON audit_log
                FOR EACH ROW EXECUTE FUNCTION prevent_audit_log_mutation();

            CREATE TRIGGER prevent_audit_log_delete
                BEFORE DELETE ON audit_log
                FOR EACH ROW EXECUTE FUNCTION prevent_audit_log_mutation();
        ");
    }

    public function down(): void
    {
        DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON audit_log");
        DB::statement("DROP TRIGGER IF EXISTS prevent_audit_log_delete ON audit_log");
        DB::statement("DROP TRIGGER IF EXISTS prevent_audit_log_update ON audit_log");
        DB::statement("DROP FUNCTION IF EXISTS prevent_audit_log_mutation()");
        Schema::dropIfExists('audit_log');
    }
};
