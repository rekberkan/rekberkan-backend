<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispute_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->enum('action_type', ['PARTIAL_RELEASE', 'FULL_REFUND', 'FULL_RELEASE', 'REJECT', 'REQUEST_INFO']);
            $table->foreignId('maker_admin_id')->constrained('admins')->cascadeOnDelete();
            $table->foreignId('checker_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->enum('approval_status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->json('payload_snapshot');
            $table->string('snapshot_hash', 64);
            $table->text('maker_notes')->nullable();
            $table->text('checker_notes')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->foreignUuid('posting_batch_id')->nullable()->constrained('posting_batches');

            $table->index(['tenant_id', 'dispute_id']);
            $table->index(['maker_admin_id', 'approval_status']);
            $table->index('approval_status');
        });

        DB::statement("ALTER TABLE dispute_actions ENABLE ROW LEVEL SECURITY");
        DB::statement("
            CREATE POLICY tenant_isolation_policy ON dispute_actions
            USING (tenant_id = current_setting('app.current_tenant_id')::bigint)
        ");
    }

    public function down(): void
    {
        DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON dispute_actions");
        Schema::dropIfExists('dispute_actions');
    }
};
