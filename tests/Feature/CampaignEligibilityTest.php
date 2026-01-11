<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Campaign;
use App\Models\Escrow;
use App\Services\CampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->campaign = Campaign::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'First Escrow Free',
            'slug' => 'first-escrow-free',
            'type' => 'FIRST_ESCROW_FREE',
            'max_participants' => 1000,
            'status' => 'ACTIVE',
        ]);

        config(['database.connections.pgsql.search_path' => 'public']);
        \DB::statement("SET app.current_tenant_id = {$this->tenant->id}");
    }

    public function test_user_can_enroll_in_first_escrow_campaign(): void
    {
        $service = app(CampaignService::class);
        
        $participation = $service->enrollUser(
            campaign: $this->campaign,
            user: $this->user
        );

        $this->assertDatabaseHas('campaign_participations', [
            'campaign_id' => $this->campaign->id,
            'user_id' => $this->user->id,
            'status' => 'PENDING',
        ]);
    }

    public function test_user_with_existing_escrow_cannot_enroll_in_first_escrow_campaign(): void
    {
        Escrow::factory()->create([
            'tenant_id' => $this->tenant->id,
            'buyer_id' => $this->user->id,
        ]);

        $service = app(CampaignService::class);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('not eligible');
        
        $service->enrollUser($this->campaign, $this->user);
    }

    public function test_user_cannot_enroll_twice_in_same_campaign(): void
    {
        $service = app(CampaignService::class);
        
        $service->enrollUser($this->campaign, $this->user);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('already enrolled');
        
        $service->enrollUser($this->campaign, $this->user);
    }

    public function test_campaign_respects_max_participants(): void
    {
        $this->campaign->update(['max_participants' => 1]);
        
        $service = app(CampaignService::class);
        $service->enrollUser($this->campaign, $this->user);

        $user2 = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('not active');
        
        $service->enrollUser($this->campaign, $user2);
    }

    public function test_campaign_respects_budget_limit(): void
    {
        $this->campaign->update([
            'budget_total' => 100.00,
            'budget_used' => 100.00,
        ]);
        
        $service = app(CampaignService::class);
        
        $this->expectException(\Exception::class);
        $service->enrollUser($this->campaign, $this->user);
    }

    public function test_inactive_campaign_cannot_accept_enrollments(): void
    {
        $this->campaign->update(['status' => 'PAUSED']);
        
        $service = app(CampaignService::class);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('not active');
        
        $service->enrollUser($this->campaign, $this->user);
    }
}
