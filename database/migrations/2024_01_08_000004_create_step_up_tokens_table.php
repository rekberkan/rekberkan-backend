<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('step_up_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('subject_type', 50);
            $table->unsignedBigInteger('subject_id');
            $table->string('purpose', 100);
            $table->string('device_fingerprint')->nullable();
            $table->boolean('used')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'subject_type', 'subject_id']);
            $table->index(['token_hash', 'used', 'expires_at']);
        });

        DB::statement("ALTER TABLE step_up_tokens ENABLE ROW LEVEL SECURITY");
        DB::statement("
            CREATE POLICY tenant_isolation_policy ON step_up_tokens
            USING (tenant_id = current_setting('app.current_tenant_id')::bigint)
        ");
    }

    public function down(): void
    {
        DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON step_up_tokens");
        Schema::dropIfExists('step_up_tokens');
    }
};
