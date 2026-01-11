<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rls_prevents_cross_tenant_data_access(): void
    {
        // Create two tenants
        $tenant1Id = DB::table('tenants')->insertGetId([
            'name' => 'Tenant 1',
            'subdomain' => 'tenant1',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenant2Id = DB::table('tenants')->insertGetId([
            'name' => 'Tenant 2',
            'subdomain' => 'tenant2',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create users for each tenant
        $user1Id = DB::table('users')->insertGetId([
            'tenant_id' => $tenant1Id,
            'email' => 'user1@tenant1.com',
            'password' => bcrypt('password'),
            'name' => 'User One',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user2Id = DB::table('users')->insertGetId([
            'tenant_id' => $tenant2Id,
            'email' => 'user2@tenant2.com',
            'password' => bcrypt('password'),
            'name' => 'User Two',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Set tenant context to tenant1
        DB::statement("SET app.tenant_id = ?::bigint", [$tenant1Id]);

        // Should only see tenant1's user
        $visibleUsers = DB::table('users')->get();
        $this->assertCount(1, $visibleUsers);
        $this->assertEquals($user1Id, $visibleUsers->first()->id);

        // Switch to tenant2
        DB::statement("SET app.tenant_id = ?::bigint", [$tenant2Id]);

        // Should only see tenant2's user
        $visibleUsers = DB::table('users')->get();
        $this->assertCount(1, $visibleUsers);
        $this->assertEquals($user2Id, $visibleUsers->first()->id);

        // Attempt to insert with wrong tenant_id should fail
        DB::statement("SET app.tenant_id = ?::bigint", [$tenant1Id]);
        
        $this->expectException(\Exception::class);
        DB::table('users')->insert([
            'tenant_id' => $tenant2Id, // Wrong tenant!
            'email' => 'user3@tenant2.com',
            'password' => bcrypt('password'),
            'name' => 'User Three',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_no_tenant_context_prevents_access(): void
    {
        // Create tenant and user
        $tenantId = DB::table('tenants')->insertGetId([
            'name' => 'Tenant 1',
            'subdomain' => 'tenant1',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::statement("SET app.tenant_id = ?::bigint", [$tenantId]);
        
        DB::table('users')->insert([
            'tenant_id' => $tenantId,
            'email' => 'user1@tenant1.com',
            'password' => bcrypt('password'),
            'name' => 'User One',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Clear tenant context
        DB::statement("RESET app.tenant_id");

        // Should not see any users without tenant context
        $visibleUsers = DB::table('users')->get();
        $this->assertCount(0, $visibleUsers);
    }
}
