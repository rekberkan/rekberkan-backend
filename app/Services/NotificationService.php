<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationRead;
use App\Models\User;
use App\Events\NotificationCreated;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    public function send(
        User $user,
        string $type,
        string $title,
        string $body,
        ?array $metadata = null
    ): Notification {
        return DB::transaction(function () use ($user, $type, $title, $body, $metadata) {
            $notification = Notification::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'metadata' => $metadata,
            ]);

            broadcast(new NotificationCreated($notification))->toOthers();

            return $notification;
        });
    }

    public function markAsRead(Notification $notification): void
    {
        if (!$notification->isRead()) {
            NotificationRead::create([
                'notification_id' => $notification->id,
            ]);
        }
    }

    public function notifyEscrowStatusChange(int $tenantId, int $userId, int $escrowId, string $newStatus): void
    {
        $user = User::find($userId);
        if (!$user) {
            return;
        }

        $this->send(
            user: $user,
            type: 'ESCROW_STATUS_CHANGED',
            title: 'Escrow Status Updated',
            body: "Escrow #{$escrowId} status changed to {$newStatus}",
            metadata: ['escrow_id' => $escrowId, 'new_status' => $newStatus]
        );
    }

    public function notifyWalletDeposit(int $tenantId, int $userId, float $amount): void
    {
        $user = User::find($userId);
        if (!$user) {
            return;
        }

        $this->send(
            user: $user,
            type: 'WALLET_DEPOSIT',
            title: 'Deposit Received',
            body: "Your wallet has been credited with Rp " . number_format($amount, 2),
            metadata: ['amount' => $amount]
        );
    }

    public function notifyWalletWithdrawal(int $tenantId, int $userId, float $amount): void
    {
        $user = User::find($userId);
        if (!$user) {
            return;
        }

        $this->send(
            user: $user,
            type: 'WALLET_WITHDRAWAL',
            title: 'Withdrawal Processed',
            body: "Rp " . number_format($amount, 2) . " has been withdrawn from your wallet",
            metadata: ['amount' => $amount]
        );
    }

    public function notifyDisputeOpened(int $tenantId, int $userId, int $disputeId, int $escrowId): void
    {
        $user = User::find($userId);
        if (!$user) {
            return;
        }

        $this->send(
            user: $user,
            type: 'DISPUTE_OPENED',
            title: 'Dispute Opened',
            body: "A dispute has been opened for escrow #{$escrowId}",
            metadata: ['dispute_id' => $disputeId, 'escrow_id' => $escrowId]
        );
    }

    public function notifyRiskAction(int $tenantId, int $userId, string $action, string $reason): void
    {
        $user = User::find($userId);
        if (!$user) {
            return;
        }

        $titles = [
            'MEDIUM' => 'Account Under Review',
            'HIGH' => 'Wallet Frozen',
            'CRITICAL' => 'Account Locked',
        ];

        $this->send(
            user: $user,
            type: 'RISK_ACTION',
            title: $titles[$action] ?? 'Security Notice',
            body: $reason,
            metadata: ['action' => $action]
        );
    }
}
