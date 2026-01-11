<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escrow_timelines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('escrow_id')->constrained()->onDelete('cascade');
            $table->string('event', 50);
            $table->string('actor_type', 50)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['escrow_id', 'created_at']);
            $table->index('event');
        });

        // Create trigger to prevent updates/deletes
        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_timeline_modification()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Escrow timeline records are immutable';
            END;
            $$ LANGUAGE plpgsql;
        ");

        DB::unprepared("
            CREATE TRIGGER prevent_timeline_update
            BEFORE UPDATE ON escrow_timelines
            FOR EACH ROW EXECUTE FUNCTION prevent_timeline_modification();
        ");

        DB::unprepared("
            CREATE TRIGGER prevent_timeline_delete
            BEFORE DELETE ON escrow_timelines
            FOR EACH ROW EXECUTE FUNCTION prevent_timeline_modification();
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_timeline_delete ON escrow_timelines');
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_timeline_update ON escrow_timelines');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_timeline_modification()');
        Schema::dropIfExists('escrow_timelines');
    }
};
