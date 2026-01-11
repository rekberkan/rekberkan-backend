<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->enum('type', ['FIRST_ESCROW_FREE', 'REFERRAL_BONUS', 'CASHBACK', 'CUSTOM']);
            $table->decimal('budget_total', 20, 2)->nullable();
            $table->decimal('budget_used', 20, 2)->default(0);
            $table->unsignedInteger('max_participants')->nullable();
            $table->unsignedInteger('current_participants')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->enum('status', ['DRAFT', 'ACTIVE', 'PAUSED', 'COMPLETED', 'CANCELLED'])->default('DRAFT');
            $table->json('rules')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
        });

        DB::statement("ALTER TABLE campaigns ENABLE ROW LEVEL SECURITY");
        DB::statement("
            CREATE POLICY tenant_isolation_policy ON campaigns
            USING (tenant_id = current_setting('app.current_tenant_id')::bigint)
        ");
    }

    public function down(): void
    {
        DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON campaigns");
        Schema::dropIfExists('campaigns');
    }
};
