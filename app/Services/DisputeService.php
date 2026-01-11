<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\DisputeAction;
use App\Models\Admin;
use Illuminate\Support\Facades\DB;

class DisputeService
{
    public function __construct(
        protected AuditService $auditService,
        protected StepUpAuthService $stepUpAuthService
    ) {}

    public function submitAction(
        Dispute $dispute,
        Admin $makerAdmin,
        string $actionType,
        array $payload,
        ?string $notes = null,
        string $stepUpToken = null
    ): DisputeAction {
        $this->stepUpAuthService->verifyAndConsume(
            $stepUpToken,
            'Admin',
            $makerAdmin->id,
            'dispute_action_submit'
        );

        return DB::transaction(function () use ($dispute, $makerAdmin, $actionType, $payload, $notes) {
            $snapshotHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

            $action = DisputeAction::create([
                'tenant_id' => $dispute->tenant_id,
                'dispute_id' => $dispute->id,
                'action_type' => $actionType,
                'maker_admin_id' => $makerAdmin->id,
                'payload_snapshot' => $payload,
                'snapshot_hash' => $snapshotHash,
                'maker_notes' => $notes,
                'approval_status' => 'PENDING',
                'submitted_at' => now(),
            ]);

            $this->auditService->log([
                'event_type' => 'DISPUTE_ACTION_SUBMITTED',
                'subject_type' => DisputeAction::class,
                'subject_id' => $action->id,
                'actor_id' => $makerAdmin->id,
                'actor_type' => 'Admin',
                'metadata' => [
                    'dispute_id' => $dispute->id,
                    'action_type' => $actionType,
                ],
            ]);

            return $action;
        });
    }

    public function approveAction(
        DisputeAction $action,
        Admin $checkerAdmin,
        ?string $notes = null,
        string $stepUpToken = null
    ): void {
        if ($action->maker_admin_id === $checkerAdmin->id) {
            throw new \Exception('Checker cannot be the same as maker (four-eyes principle)');
        }

        $this->stepUpAuthService->verifyAndConsume(
            $stepUpToken,
            'Admin',
            $checkerAdmin->id,
            'dispute_action_approve'
        );

        DB::transaction(function () use ($action, $checkerAdmin, $notes) {
            $action->update([
                'checker_admin_id' => $checkerAdmin->id,
                'approval_status' => 'APPROVED',
                'checker_notes' => $notes,
                'approved_at' => now(),
            ]);

            $this->auditService->log([
                'event_type' => 'DISPUTE_ACTION_APPROVED',
                'subject_type' => DisputeAction::class,
                'subject_id' => $action->id,
                'actor_id' => $checkerAdmin->id,
                'actor_type' => 'Admin',
                'metadata' => [
                    'dispute_id' => $action->dispute_id,
                    'action_type' => $action->action_type,
                    'maker_admin_id' => $action->maker_admin_id,
                ],
            ]);

            $this->executeAction($action);
        });
    }

    protected function executeAction(DisputeAction $action): void
    {
        $action->update(['executed_at' => now()]);

        $this->auditService->log([
            'event_type' => 'DISPUTE_ACTION_EXECUTED',
            'subject_type' => DisputeAction::class,
            'subject_id' => $action->id,
            'metadata' => [
                'dispute_id' => $action->dispute_id,
                'action_type' => $action->action_type,
            ],
        ]);
    }
}
