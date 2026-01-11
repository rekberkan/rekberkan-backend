<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('escrow_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('benefit_amount', 20, 2)->nullable();
            $table->foreignUuid('posting_batch_id')->nullable()->constrained('posting_batches');
            $table->enum('status', ['PENDING', 'COMPLETED', 'REVERSED'])->default('PENDING');
            $table->string('idempotency_key', 100)->unique();
            $table->timestamp('created_at');

            $table->unique(['campaign_id', 'user_id'], 'campaign_user_unique');
            $table->index(['tenant_id', 'campaign_id']);
            $table->index(['tenant_id', 'user_id']);
        });

        DB::statement("ALTER TABLE campaign_participations ENABLE ROW LEVEL SECURITY");
        DB::statement("
            CREATE POLICY tenant_isolation_policy ON campaign_participations
            USING (tenant_id = current_setting('app.current_tenant_id')::bigint)
        ");
    }

    public function down(): void
    {
        DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON campaign_participations");
        Schema::dropIfExists('campaign_participations');
    }
};
