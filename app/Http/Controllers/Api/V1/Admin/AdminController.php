<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Domain\Escrow\Enums\EscrowStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
    /**
     * NEW CONTROLLER: Admin panel untuk manajemen sistem.
     * 
     * SECURITY: Requires admin role check (implement in middleware).
     */

    /**
     * List all users with filters.
     */
    public function users(Request $request)
    {
        try {
            $perPage = min((int) $request->input('per_page', 20), 100);
            $search = $request->input('search');
            $status = $request->input('status'); // active, suspended, banned

            $query = DB::table('users')
                ->select('id', 'name', 'email', 'status', 'created_at', 'last_login_at');

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', "%{$search}%")
                      ->orWhere('email', 'ilike', "%{$search}%");
                });
            }

            if ($status) {
                $query->where('status', $status);
            }

            $users = $query->orderByDesc('created_at')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $users,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch users',
            ], 500);
        }
    }

    /**
     * Get user details.
     */
    public function userDetails(Request $request, int $userId)
    {
        try {
            $user = DB::table('users')->find($userId);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // Get additional stats
            $stats = [
                'total_deposits' => DB::table('deposits')->where('user_id', $userId)->sum('amount'),
                'total_withdrawals' => DB::table('withdrawals')->where('user_id', $userId)->sum('amount'),
                'escrow_count' => DB::table('escrows')->where('sender_id', $userId)->orWhere('recipient_id', $userId)->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $user,
                    'stats' => $stats,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user details',
            ], 500);
        }
    }

    /**
     * Update user status (suspend/activate/ban).
     */
    public function updateUserStatus(Request $request, int $userId)
    {
        $validated = $request->validate([
            'status' => 'required|in:active,suspended,banned',
            'reason' => 'required|string|max:500',
        ]);

        try {
            DB::table('users')
                ->where('id', $userId)
                ->update([
                    'status' => $validated['status'],
                    'updated_at' => now(),
                ]);

            // Log admin action
            Log::info('Admin updated user status', [
                'admin_id' => $request->user()->id,
                'user_id' => $userId,
                'status' => $validated['status'],
                'reason' => $validated['reason'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User status updated',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user status',
            ], 500);
        }
    }

    /**
     * List pending KYC verifications.
     */
    public function kycPending(Request $request)
    {
        try {
            $perPage = min((int) $request->input('per_page', 20), 100);

            $kyc = DB::table('kyc_verifications')
                ->join('users', 'kyc_verifications.user_id', '=', 'users.id')
                ->where('kyc_verifications.status', 'pending')
                ->select('kyc_verifications.*', 'users.name', 'users.email')
                ->orderBy('kyc_verifications.created_at', 'asc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $kyc,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch pending KYC',
            ], 500);
        }
    }

    /**
     * Approve or reject KYC.
     */
    public function kycDecision(Request $request, int $kycId)
    {
        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $kyc = DB::table('kyc_verifications')->find($kycId);

            if (!$kyc || $kyc->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'KYC not found or already processed',
                ], 404);
            }

            DB::beginTransaction();

            // Update KYC status
            DB::table('kyc_verifications')
                ->where('id', $kycId)
                ->update([
                    'status' => $validated['action'] === 'approve' ? 'approved' : 'rejected',
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                    'review_notes' => $validated['notes'],
                    'updated_at' => now(),
                ]);

            // Update user KYC status
            if ($validated['action'] === 'approve') {
                DB::table('users')
                    ->where('id', $kyc->user_id)
                    ->update(['kyc_verified' => true]);
            }

            DB::commit();

            Log::info('Admin KYC decision', [
                'admin_id' => $request->user()->id,
                'kyc_id' => $kycId,
                'action' => $validated['action'],
            ]);

            return response()->json([
                'success' => true,
                'message' => "KYC {$validated['action']}d successfully",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to process KYC',
            ], 500);
        }
    }

    /**
     * Get security event logs.
     */
    public function securityLogs(Request $request)
    {
        try {
            $perPage = min((int) $request->input('per_page', 50), 100);
            $severity = $request->input('severity'); // low, medium, high, critical
            $eventType = $request->input('event_type');
            $suspicious = $request->input('suspicious'); // true/false

            $query = DB::table('security_events')
                ->orderByDesc('created_at');

            if ($severity) {
                $query->where('severity', $severity);
            }

            if ($eventType) {
                $query->where('event_type', $eventType);
            }

            if ($suspicious !== null) {
                $query->where('is_suspicious', $suspicious === 'true');
            }

            $logs = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $logs,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch security logs',
            ], 500);
        }
    }

    /**
     * Get dashboard statistics.
     */
    public function dashboardStats(Request $request)
    {
        try {
            $stats = [
                'total_users' => DB::table('users')->count(),
                'active_users' => DB::table('users')->where('status', 'active')->count(),
                'pending_kyc' => DB::table('kyc_verifications')->where('status', 'pending')->count(),
                'total_escrows' => DB::table('escrows')->count(),
                'active_escrows' => DB::table('escrows')->whereIn('status', [
                    EscrowStatus::FUNDED->value,
                    EscrowStatus::IN_PROGRESS->value,
                    EscrowStatus::DELIVERED->value,
                    EscrowStatus::DISPUTED->value,
                ])->count(),
                'total_volume' => DB::table('escrows')->sum('amount'),
                'suspicious_events_24h' => DB::table('security_events')
                    ->where('is_suspicious', true)
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch stats',
            ], 500);
        }
    }
}
