<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['tenant_id', 'email']);
            $table->index('frozen_at');
        });

        // Enable Row-Level Security
        DB::statement('ALTER TABLE users ENABLE ROW LEVEL SECURITY');

        // Create RLS policy for tenant isolation
        DB::statement("
            CREATE POLICY tenant_isolation_policy ON users
            USING (tenant_id = current_setting('app.current_tenant_id')::bigint)
        ");
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_policy ON users');
        Schema::dropIfExists('users');
    }
};
