<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_chat_message_mutation() RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'chat_messages table is immutable';
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER prevent_chat_message_update
                BEFORE UPDATE ON chat_messages
                FOR EACH ROW EXECUTE FUNCTION prevent_chat_message_mutation();

            CREATE TRIGGER prevent_chat_message_delete
                BEFORE DELETE ON chat_messages
                FOR EACH ROW EXECUTE FUNCTION prevent_chat_message_mutation();
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TRIGGER IF EXISTS prevent_chat_message_delete ON chat_messages");
        DB::statement("DROP TRIGGER IF EXISTS prevent_chat_message_update ON chat_messages");
        DB::statement("DROP FUNCTION IF EXISTS prevent_chat_message_mutation()");
    }
};
