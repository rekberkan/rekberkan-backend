<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'ESCROW_STATUS_CHANGED',
                'WALLET_DEPOSIT',
                'WALLET_WITHDRAWAL',
                'WALLET_FEE',
                'DISPUTE_OPENED',
                'DISPUTE_UPDATED',
                'DISPUTE_RESOLVED',
                'RISK_ACTION',
                'CHAT_MESSAGE',
                'SYSTEM_ANNOUNCEMENT'
            ]);
            $table->string('title');
            $table->text('body');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['user_id', 'type']);
        });

        Schema::create('notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();

            $table->unique('notification_id');
        });

        DB::statement("ALTER TABLE notifications ENABLE ROW LEVEL SECURITY");
        DB::statement("
            CREATE POLICY tenant_isolation_policy ON notifications
            USING (tenant_id = current_setting('app.current_tenant_id')::bigint)
        ");

        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_notification_mutation() RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'notifications table is insert-only';
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER prevent_notification_update
                BEFORE UPDATE ON notifications
                FOR EACH ROW EXECUTE FUNCTION prevent_notification_mutation();

            CREATE TRIGGER prevent_notification_delete
                BEFORE DELETE ON notifications
                FOR EACH ROW EXECUTE FUNCTION prevent_notification_mutation();
        ");
    }

    public function down(): void
    {
        DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON notifications");
        DB::statement("DROP TRIGGER IF EXISTS prevent_notification_delete ON notifications");
        DB::statement("DROP TRIGGER IF EXISTS prevent_notification_update ON notifications");
        DB::statement("DROP FUNCTION IF EXISTS prevent_notification_mutation()");
        Schema::dropIfExists('notification_reads');
        Schema::dropIfExists('notifications');
    }
};
