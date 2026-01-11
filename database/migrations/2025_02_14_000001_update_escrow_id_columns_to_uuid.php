<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            [
                'name' => 'disputes',
                'nullable' => false,
                'on_delete' => 'cascade',
            ],
            [
                'name' => 'campaign_participations',
                'nullable' => true,
                'on_delete' => 'set null',
            ],
            [
                'name' => 'voucher_redemptions',
                'nullable' => true,
                'on_delete' => 'set null',
            ],
        ];

        foreach ($tables as $table) {
            $column = DB::selectOne(
                "SELECT data_type FROM information_schema.columns WHERE table_name = ? AND column_name = 'escrow_id'",
                [$table['name']]
            );

            if (!$column || $column->data_type === 'uuid') {
                continue;
            }

            DB::statement("ALTER TABLE {$table['name']} DROP CONSTRAINT IF EXISTS {$table['name']}_escrow_id_foreign");
            DB::statement("ALTER TABLE {$table['name']} ALTER COLUMN escrow_id TYPE uuid USING (escrow_id::uuid)");

            Schema::table($table['name'], function (Blueprint $blueprint) use ($table) {
                $constraint = "{$table['name']}_escrow_id_foreign";
                $foreign = $blueprint->foreign('escrow_id', $constraint)->references('id')->on('escrows');

                if ($table['on_delete'] === 'cascade') {
                    $foreign->cascadeOnDelete();
                }

                if ($table['on_delete'] === 'set null') {
                    $foreign->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        $tables = [
            [
                'name' => 'disputes',
                'nullable' => false,
                'on_delete' => 'cascade',
            ],
            [
                'name' => 'campaign_participations',
                'nullable' => true,
                'on_delete' => 'set null',
            ],
            [
                'name' => 'voucher_redemptions',
                'nullable' => true,
                'on_delete' => 'set null',
            ],
        ];

        foreach ($tables as $table) {
            $column = DB::selectOne(
                "SELECT data_type FROM information_schema.columns WHERE table_name = ? AND column_name = 'escrow_id'",
                [$table['name']]
            );

            if (!$column || $column->data_type !== 'uuid') {
                continue;
            }

            DB::statement("ALTER TABLE {$table['name']} DROP CONSTRAINT IF EXISTS {$table['name']}_escrow_id_foreign");
            DB::statement("ALTER TABLE {$table['name']} ALTER COLUMN escrow_id TYPE bigint USING (escrow_id::bigint)");

            Schema::table($table['name'], function (Blueprint $blueprint) use ($table) {
                $constraint = "{$table['name']}_escrow_id_foreign";
                $foreign = $blueprint->foreign('escrow_id', $constraint)->references('id')->on('escrows');

                if ($table['on_delete'] === 'cascade') {
                    $foreign->cascadeOnDelete();
                }

                if ($table['on_delete'] === 'set null') {
                    $foreign->nullOnDelete();
                }
            });
        }
    }
};
