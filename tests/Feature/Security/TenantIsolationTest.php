<?php

namespace Tests\Feature\Security;

use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $tenant1User;
    private User $tenant2User;
    private Tenant $tenant1;
    private Tenant $tenant2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create two separate tenants
        $this->tenant1 = Tenant::factory()->create(['domain' => 'tenant1.rekberkan.com']);
        $this->tenant2 = Tenant::factory()->create(['domain' => 'tenant2.rekberkan.com']);

        // Create users for each tenant
        $this->tenant1User = User::factory()->create(['tenant_id' => $this->tenant1->id]);
        $this->tenant2User = User::factory()->create(['tenant_id' => $this->tenant2->id]);
    }

    /** @test */
    public function tenant_cannot_access_another_tenants_transactions()
    {
        // Create transactions for both tenants
        $tenant1Transaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant1->id,
            'user_id' => $this->tenant1User->id,
        ]);

        $tenant2Transaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant2->id,
            'user_id' => $this->tenant2User->id,
        ]);

        // Authenticate as tenant1 user
        $this->actingAs($this->tenant1User)
            ->withHeaders(['X-Tenant-ID' => $this->tenant1->id]);

        // Try to access tenant2's transaction - should fail
        $response = $this->getJson("/api/transactions/{$tenant2Transaction->id}");

        $response->assertStatus(404); // Not found (tenant isolation)
    }

    /** @test */
    public function tenant_cannot_modify_another_tenants_data()
    {
        $tenant2Transaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant2->id,
            'user_id' => $this->tenant2User->id,
        ]);

        // Try to update tenant2's transaction as tenant1 user
        $response = $this->actingAs($this->tenant1User)
            ->withHeaders(['X-Tenant-ID' => $this->tenant1->id])
            ->putJson("/api/transactions/{$tenant2Transaction->id}", [
                'status' => 'completed',
            ]);

        $response->assertStatus(404);
        
        // Verify transaction wasn't modified
        $this->assertDatabaseHas('transactions', [
            'id' => $tenant2Transaction->id,
            'status' => $tenant2Transaction->status, // Original status
        ]);
    }

    /** @test */
    public function tenant_id_manipulation_in_request_is_blocked()
    {
        $tenant1Transaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant1->id,
            'user_id' => $this->tenant1User->id,
        ]);

        // Try to create transaction with manipulated tenant_id
        $response = $this->actingAs($this->tenant1User)
            ->withHeaders(['X-Tenant-ID' => $this->tenant1->id])
            ->postJson('/api/transactions', [
                'tenant_id' => $this->tenant2->id, // Attempt to inject different tenant
                'amount' => 100000,
                'description' => 'Test',
            ]);

        // Check that created transaction has correct tenant_id
        if ($response->status() === 201) {
            $transaction = Transaction::find($response->json('data.id'));
            $this->assertEquals($this->tenant1->id, $transaction->tenant_id);
        }
    }

    /** @test */
    public function sql_injection_cannot_bypass_tenant_isolation()
    {
        Transaction::factory()->create([
            'tenant_id' => $this->tenant2->id,
            'user_id' => $this->tenant2User->id,
        ]);

        // Attempt SQL injection in search parameter
        $response = $this->actingAs($this->tenant1User)
            ->withHeaders(['X-Tenant-ID' => $this->tenant1->id])
            ->getJson('/api/transactions?search=' . urlencode("' OR tenant_id = {$this->tenant2->id} --"));

        $response->assertSuccessful();
        
        // Should only return tenant1's transactions (or none)
        $transactions = $response->json('data');
        foreach ($transactions as $transaction) {
            $this->assertEquals($this->tenant1->id, $transaction['tenant_id']);
        }
    }

    /** @test */
    public function direct_database_query_respects_tenant_scope()
    {
        // Create transactions for both tenants
        Transaction::factory()->count(5)->create(['tenant_id' => $this->tenant1->id]);
        Transaction::factory()->count(3)->create(['tenant_id' => $this->tenant2->id]);

        // Set current tenant context
        app()->instance('tenant', $this->tenant1);

        // Query should only return tenant1's transactions
        $transactions = Transaction::all();
        
        $this->assertCount(5, $transactions);
        foreach ($transactions as $transaction) {
            $this->assertEquals($this->tenant1->id, $transaction->tenant_id);
        }
    }

    /** @test */
    public function user_list_is_isolated_by_tenant()
    {
        // Create additional users
        User::factory()->count(3)->create(['tenant_id' => $this->tenant1->id]);
        User::factory()->count(2)->create(['tenant_id' => $this->tenant2->id]);

        // List users as tenant1 admin
        $response = $this->actingAs($this->tenant1User)
            ->withHeaders(['X-Tenant-ID' => $this->tenant1->id])
            ->getJson('/api/users');

        $response->assertSuccessful();
        
        $users = $response->json('data');
        foreach ($users as $user) {
            $this->assertEquals($this->tenant1->id, $user['tenant_id']);
        }
    }

    /** @test */
    public function tenant_switching_attack_is_prevented()
    {
        // Authenticate as tenant1 user
        $token = $this->actingAs($this->tenant1User)->json('POST', '/api/auth/login')->json('token');

        // Try to switch tenant via header manipulation
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenant2->id, // Attempt to switch tenant
        ])->getJson('/api/transactions');

        // Should either fail or only return tenant1's data
        if ($response->status() === 200) {
            $transactions = $response->json('data');
            foreach ($transactions as $transaction) {
                $this->assertNotEquals($this->tenant2->id, $transaction['tenant_id']);
            }
        } else {
            $response->assertStatus(403); // Forbidden
        }
    }
}
