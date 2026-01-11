<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Private channels require authentication.
| Presence channels track connected users.
*/

// Private user channel
Broadcast::channel('users.{userId}', function ($user, $userId) {
    return (string) $user->id === (string) $userId;
});

// Escrow channel (sender and recipient only)
Broadcast::channel('escrows.{escrowId}', function ($user, $escrowId) {
    $escrow = \Illuminate\Support\Facades\DB::table('escrows')
        ->where('id', $escrowId)
        ->first();

    if (!$escrow) {
        return false;
    }

    return in_array($user->id, [$escrow->sender_id, $escrow->recipient_id]);
});

// Escrow chat (presence channel)
Broadcast::channel('escrow-chat.{escrowId}', function ($user, $escrowId) {
    $escrow = \Illuminate\Support\Facades\DB::table('escrows')
        ->where('id', $escrowId)
        ->first();

    if (!$escrow) {
        return false;
    }

    if (in_array($user->id, [$escrow->sender_id, $escrow->recipient_id])) {
        return [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }

    return false;
});

// Tenant-wide notifications
Broadcast::channel('tenant.{tenantId}', function ($user, $tenantId) {
    return (string) $user->tenant_id === (string) $tenantId;
});
