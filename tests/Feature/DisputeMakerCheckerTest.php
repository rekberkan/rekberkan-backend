<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Admin;
use App\Models\Dispute;
use App\Models\Escrow;
use App\Services\DisputeService;
use App\Services\StepUpAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisputeMakerCheckerTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Admin $makerAdmin;
    protected Admin $checkerAdmin;
    protected Dispute $dispute;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->makerAdmin = Admin::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->checkerAdmin = Admin::factory()->create(['tenant_id' => $this->tenant->id]);
        
        $escrow = Escrow::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->dispute = Dispute::factory()->create([
            'tenant_id' => $this->tenant->id,
            'escrow_id' => $escrow->id,
        ]);

        config(['database.connections.pgsql.search_path' => 'public']);
        \DB::statement("SET app.current_tenant_id = {$this->tenant->id}");
    }

    public function test_maker_can_submit_dispute_action_with_step_up(): void
    {
        $stepUpService = app(StepUpAuthService::class);
        $token = $stepUpService->generate(
            $this->tenant->id,
            'Admin',
            $this->makerAdmin->id,
            'dispute_action_submit'
        );

        $disputeService = app(DisputeService::class);
        
        $action = $disputeService->submitAction(
            dispute: $this->dispute,
            makerAdmin: $this->makerAdmin,
            actionType: 'PARTIAL_RELEASE',
            payload: ['amount' => 500000],
            notes: 'Evidence reviewed, partial release justified',
            stepUpToken: $token
        );

        $this->assertDatabaseHas('dispute_actions', [
            'id' => $action->id,
            'dispute_id' => $this->dispute->id,
            'maker_admin_id' => $this->makerAdmin->id,
            'approval_status' => 'PENDING',
        ]);
    }

    public function test_maker_cannot_submit_without_valid_step_up_token(): void
    {
        $disputeService = app(DisputeService::class);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid or expired step-up token');

        $disputeService->submitAction(
            dispute: $this->dispute,
            makerAdmin: $this->makerAdmin,
            actionType: 'PARTIAL_RELEASE',
            payload: ['amount' => 500000],
            stepUpToken: 'invalid_token'
        );
    }

    public function test_checker_cannot_be_same_as_maker(): void
    {
        $stepUpService = app(StepUpAuthService::class);
        $submitToken = $stepUpService->generate(
            $this->tenant->id,
            'Admin',
            $this->makerAdmin->id,
            'dispute_action_submit'
        );

        $disputeService = app(DisputeService::class);
        $action = $disputeService->submitAction(
            dispute: $this->dispute,
            makerAdmin: $this->makerAdmin,
            actionType: 'PARTIAL_RELEASE',
            payload: ['amount' => 500000],
            stepUpToken: $submitToken
        );

        $approveToken = $stepUpService->generate(
            $this->tenant->id,
            'Admin',
            $this->makerAdmin->id,
            'dispute_action_approve'
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('four-eyes principle');

        $disputeService->approveAction(
            action: $action,
            checkerAdmin: $this->makerAdmin,
            stepUpToken: $approveToken
        );
    }

    public function test_checker_can_approve_action_with_step_up(): void
    {
        $stepUpService = app(StepUpAuthService::class);
        
        $submitToken = $stepUpService->generate(
            $this->tenant->id,
            'Admin',
            $this->makerAdmin->id,
            'dispute_action_submit'
        );

        $disputeService = app(DisputeService::class);
        $action = $disputeService->submitAction(
            dispute: $this->dispute,
            makerAdmin: $this->makerAdmin,
            actionType: 'PARTIAL_RELEASE',
            payload: ['amount' => 500000],
            stepUpToken: $submitToken
        );

        $approveToken = $stepUpService->generate(
            $this->tenant->id,
            'Admin',
            $this->checkerAdmin->id,
            'dispute_action_approve'
        );

        $disputeService->approveAction(
            action: $action,
            checkerAdmin: $this->checkerAdmin,
            notes: 'Approved after review',
            stepUpToken: $approveToken
        );

        $action->refresh();

        $this->assertEquals('APPROVED', $action->approval_status);
        $this->assertEquals($this->checkerAdmin->id, $action->checker_admin_id);
        $this->assertNotNull($action->approved_at);
        $this->assertNotNull($action->executed_at);
    }

    public function test_step_up_token_can_only_be_used_once(): void
    {
        $stepUpService = app(StepUpAuthService::class);
        $token = $stepUpService->generate(
            $this->tenant->id,
            'Admin',
            $this->makerAdmin->id,
            'dispute_action_submit'
        );

        $disputeService = app(DisputeService::class);
        
        $disputeService->submitAction(
            dispute: $this->dispute,
            makerAdmin: $this->makerAdmin,
            actionType: 'PARTIAL_RELEASE',
            payload: ['amount' => 500000],
            stepUpToken: $token
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid or expired');

        $disputeService->submitAction(
            dispute: $this->dispute,
            makerAdmin: $this->makerAdmin,
            actionType: 'FULL_REFUND',
            payload: ['reason' => 'test'],
            stepUpToken: $token
        );
    }
}
