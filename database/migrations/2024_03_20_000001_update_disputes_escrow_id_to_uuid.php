<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->uuid('escrow_id_new')->nullable();
        });

        DB::statement("UPDATE disputes SET escrow_id_new = escrow_id::uuid WHERE escrow_id IS NOT NULL");

        Schema::table('disputes', function (Blueprint $table) {
            $table->dropForeign('disputes_escrow_id_foreign');
            $table->dropIndex('disputes_escrow_id_status_index');
            $table->dropColumn('escrow_id');
        });

        Schema::table('disputes', function (Blueprint $table) {
            $table->renameColumn('escrow_id_new', 'escrow_id');
        });

        Schema::table('disputes', function (Blueprint $table) {
            $table->foreignUuid('escrow_id')->constrained()->cascadeOnDelete();
            $table->index(['escrow_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->unsignedBigInteger('escrow_id_old')->nullable();
        });

        Schema::table('disputes', function (Blueprint $table) {
            $table->dropForeign('disputes_escrow_id_foreign');
            $table->dropIndex('disputes_escrow_id_status_index');
            $table->dropColumn('escrow_id');
        });

        Schema::table('disputes', function (Blueprint $table) {
            $table->renameColumn('escrow_id_old', 'escrow_id');
        });

        Schema::table('disputes', function (Blueprint $table) {
            $table->foreignId('escrow_id')->constrained()->cascadeOnDelete();
            $table->index(['escrow_id', 'status']);
        });
    }
};
