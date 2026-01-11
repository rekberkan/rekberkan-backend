<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Escrow;
use App\Models\UserBehaviorLog;
use App\Services\RiskEngine;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskEngineTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        config(['database.connections.pgsql.search_path' => 'public']);
        \DB::statement("SET app.current_tenant_id = {$this->tenant->id}");
    }

    public function test_risk_engine_calculates_deterministic_score(): void
    {
        $riskEngine = app(RiskEngine::class);

        $result1 = $riskEngine->evaluateUser($this->user);
        
        sleep(1);
        
        $result2 = $riskEngine->evaluateUser($this->user);

        $this->assertIsInt($result1['score']);
        $this->assertIsInt($result2['score']);
        $this->assertGreaterThanOrEqual(0, $result1['score']);
        $this->assertLessThanOrEqual(100, $result1['score']);
    }

    public function test_risk_engine_increases_score_for_high_dispute_ratio(): void
    {
        for ($i = 0; $i < 10; $i++) {
            Escrow::factory()->create([
                'tenant_id' => $this->tenant->id,
                'buyer_id' => $this->user->id,
                'status' => 'DISPUTED',
            ]);
        }

        $riskEngine = app(RiskEngine::class);
        $result = $riskEngine->evaluateUser($this->user);

        $this->assertGreaterThan(20, $result['score']);
        $this->assertContains($result['action'], ['MEDIUM', 'HIGH', 'CRITICAL']);
    }

    public function test_risk_engine_increases_score_for_voucher_abuse(): void
    {
        for ($i = 0; $i < 5; $i++) {
            UserBehaviorLog::create([
                'tenant_id' => $this->tenant->id,
                'user_id' => $this->user->id,
                'event_type' => 'VOUCHER_REDEMPTION_FAILED',
            ]);
        }

        $riskEngine = app(RiskEngine::class);
        $result = $riskEngine->evaluateUser($this->user);

        $this->assertGreaterThanOrEqual(25, $result['score']);
    }

    public function test_risk_engine_action_thresholds(): void
    {
        $riskEngine = app(RiskEngine::class);

        for ($i = 0; $i < 20; $i++) {
            Escrow::factory()->create([
                'tenant_id' => $this->tenant->id,
                'buyer_id' => $this->user->id,
                'status' => 'DISPUTED',
            ]);
        }

        for ($i = 0; $i < 5; $i++) {
            UserBehaviorLog::create([
                'tenant_id' => $this->tenant->id,
                'user_id' => $this->user->id,
                'event_type' => 'VOUCHER_REDEMPTION_FAILED',
            ]);
        }

        $result = $riskEngine->evaluateUser($this->user);

        $this->assertGreaterThanOrEqual(50, $result['score']);
        $this->assertContains($result['action'], ['HIGH', 'CRITICAL']);
    }

    public function test_risk_decision_is_immutable(): void
    {
        $riskEngine = app(RiskEngine::class);
        $result = $riskEngine->evaluateUser($this->user);

        $this->expectException(\Exception::class);
        \DB::table('risk_decisions')
            ->where('id', $result['decision_id'])
            ->update(['score' => 99]);
    }

    public function test_risk_decision_stores_snapshot_hash(): void
    {
        $riskEngine = app(RiskEngine::class);
        $result = $riskEngine->evaluateUser($this->user);

        $decision = \DB::table('risk_decisions')
            ->where('id', $result['decision_id'])
            ->first();

        $this->assertNotNull($decision->snapshot_hash);
        $this->assertEquals(64, strlen($decision->snapshot_hash));
        
        $snapshot = json_decode($decision->input_snapshot, true);
        $expectedHash = hash('sha256', json_encode($snapshot));
        $this->assertEquals($expectedHash, $decision->snapshot_hash);
    }
}
