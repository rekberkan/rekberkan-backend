<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\RiskEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AdminConfigController extends Controller
{
    /**
     * NEW CONTROLLER: Admin configuration untuk risk engine & system settings.
     */

    /**
     * Get current risk engine configuration.
     */
    public function getRiskEngineConfig(Request $request)
    {
        try {
            $config = [
                'risk_thresholds' => [
                    'low' => config('risk.thresholds.low', 30),
                    'medium' => config('risk.thresholds.medium', 50),
                    'high' => config('risk.thresholds.high', 70),
                    'critical' => config('risk.thresholds.critical', 90),
                ],
                'auto_block_threshold' => config('risk.auto_block_threshold', 90),
                'manual_review_threshold' => config('risk.manual_review_threshold', 70),
                'max_transaction_amount' => config('risk.max_transaction_amount', 100000000),
                'velocity_checks' => [
                    'enabled' => config('risk.velocity_checks.enabled', true),
                    'max_daily_transactions' => config('risk.velocity_checks.max_daily', 10),
                    'max_daily_volume' => config('risk.velocity_checks.max_volume', 50000000),
                ],
                'geo_restrictions' => [
                    'enabled' => config('risk.geo_restrictions.enabled', false),
                    'blocked_countries' => config('risk.geo_restrictions.blocked_countries', []),
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $config,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch config',
            ], 500);
        }
    }

    /**
     * Update risk engine configuration.
     */
    public function updateRiskEngineConfig(Request $request)
    {
        $validated = $request->validate([
            'auto_block_threshold' => 'nullable|integer|min:0|max:100',
            'manual_review_threshold' => 'nullable|integer|min:0|max:100',
            'max_transaction_amount' => 'nullable|integer|min:0',
            'velocity_checks.enabled' => 'nullable|boolean',
            'velocity_checks.max_daily_transactions' => 'nullable|integer|min:1',
            'velocity_checks.max_daily_volume' => 'nullable|integer|min:0',
            'geo_restrictions.enabled' => 'nullable|boolean',
            'geo_restrictions.blocked_countries' => 'nullable|array',
            'geo_restrictions.blocked_countries.*' => 'string|size:2',
        ]);

        try {
            // Update runtime config (stored in cache)
            // In production, consider storing in database
            foreach ($validated as $key => $value) {
                $cacheKey = 'risk.config.' . str_replace('.', '_', $key);
                Cache::put($cacheKey, $value, 86400); // 24 hours
            }

            Log::info('Admin updated risk engine config', [
                'admin_id' => $request->user()->id,
                'changes' => $validated,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Risk engine configuration updated',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update config',
            ], 500);
        }
    }

    /**
     * Get system configuration.
     */
    public function getSystemConfig(Request $request)
    {
        try {
            $config = [
                'maintenance_mode' => config('app.maintenance', false),
                'registration_enabled' => config('app.registration_enabled', true),
                'kyc_required' => config('app.kyc_required', true),
                'min_deposit' => config('payment.limits.deposit.min'),
                'max_deposit' => config('payment.limits.deposit.max'),
                'min_withdrawal' => config('payment.limits.withdrawal.min'),
                'max_withdrawal' => config('payment.limits.withdrawal.max'),
                'fees' => config('payment.fees'),
            ];

            return response()->json([
                'success' => true,
                'data' => $config,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch system config',
            ], 500);
        }
    }

    /**
     * Update system configuration.
     */
    public function updateSystemConfig(Request $request)
    {
        $validated = $request->validate([
            'maintenance_mode' => 'nullable|boolean',
            'registration_enabled' => 'nullable|boolean',
            'kyc_required' => 'nullable|boolean',
        ]);

        try {
            // Update runtime config
            foreach ($validated as $key => $value) {
                Cache::put('system.config.' . $key, $value, 86400);
            }

            Log::info('Admin updated system config', [
                'admin_id' => $request->user()->id,
                'changes' => $validated,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'System configuration updated',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update config',
            ], 500);
        }
    }
}
