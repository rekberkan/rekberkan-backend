<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->onDelete('cascade');
            $table->index(['tenant_id', 'escrow_id']);
        });

        DB::statement("
            UPDATE chat_messages
            SET tenant_id = escrows.tenant_id
            FROM escrows
            WHERE chat_messages.escrow_id = escrows.id
        ");

        DB::statement('ALTER TABLE chat_messages ALTER COLUMN tenant_id SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'escrow_id']);
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
