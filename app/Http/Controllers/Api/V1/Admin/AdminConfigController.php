<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\RiskEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminConfigController extends Controller
{
    /**
     * Admin configuration for risk engine & system settings.
     */

    /**
     * Get current risk engine configuration.
     */
    public function getRiskEngineConfig(Request $request)
    {
        try {
            // Try to get from cache first, then database, then config file
            $config = Cache::remember('risk_engine_config', 3600, function () {
                $dbRecord = DB::table('system_configs')->where('key', 'risk_engine')->first();
                
                if ($dbRecord) {
                    return json_decode($dbRecord->value, true);
                }
                
                // Fallback to default config
                return [
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
            });

            return response()->json([
                'success' => true,
                'data' => $config,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch risk engine config', ['error' => $e->getMessage()]);
            
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
            'risk_thresholds.low' => 'nullable|integer|min:0|max:100',
            'risk_thresholds.medium' => 'nullable|integer|min:0|max:100',
            'risk_thresholds.high' => 'nullable|integer|min:0|max:100',
            'risk_thresholds.critical' => 'nullable|integer|min:0|max:100',
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
            // Get current config
            $currentConfig = Cache::get('risk_engine_config');
            if (!$currentConfig) {
                $dbRecord = DB::table('system_configs')->where('key', 'risk_engine')->first();
                $currentConfig = $dbRecord ? json_decode($dbRecord->value, true) : [];
            }
            
            // Merge with updates
            $newConfig = array_replace_recursive($currentConfig, $validated);
            
            // Save to database
            DB::table('system_configs')->updateOrInsert(
                ['key' => 'risk_engine'],
                [
                    'value' => json_encode($newConfig),
                    'updated_at' => now(),
                    'updated_by' => $request->user()->id,
                ]
            );
            
            // Update cache
            Cache::put('risk_engine_config', $newConfig, now()->addDay());

            Log::info('Admin updated risk engine config', [
                'admin_id' => $request->user()->id,
                'changes' => $validated,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Risk engine configuration updated',
                'data' => $newConfig,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update risk engine config', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
            ]);
            
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
            // Try cache first, then database, then config file
            $config = Cache::remember('system_config', 3600, function () {
                $dbRecord = DB::table('system_configs')->where('key', 'system')->first();
                
                if ($dbRecord) {
                    return json_decode($dbRecord->value, true);
                }
                
                // Fallback to default
                return [
                    'maintenance_mode' => config('app.maintenance', false),
                    'registration_enabled' => config('app.registration_enabled', true),
                    'kyc_required' => config('app.kyc_required', true),
                    'min_deposit' => config('payment.limits.deposit.min', 10000),
                    'max_deposit' => config('payment.limits.deposit.max', 100000000),
                    'min_withdrawal' => config('payment.limits.withdrawal.min', 50000),
                    'max_withdrawal' => config('payment.limits.withdrawal.max', 50000000),
                    'fees' => config('payment.fees', []),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $config,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch system config', ['error' => $e->getMessage()]);
            
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
            'min_deposit' => 'nullable|integer|min:0',
            'max_deposit' => 'nullable|integer|min:0',
            'min_withdrawal' => 'nullable|integer|min:0',
            'max_withdrawal' => 'nullable|integer|min:0',
        ]);

        try {
            // Get current config
            $currentConfig = Cache::get('system_config');
            if (!$currentConfig) {
                $dbRecord = DB::table('system_configs')->where('key', 'system')->first();
                $currentConfig = $dbRecord ? json_decode($dbRecord->value, true) : [];
            }
            
            // Merge with updates
            $newConfig = array_merge($currentConfig, $validated);
            
            // Save to database
            DB::table('system_configs')->updateOrInsert(
                ['key' => 'system'],
                [
                    'value' => json_encode($newConfig),
                    'updated_at' => now(),
                    'updated_by' => $request->user()->id,
                ]
            );
            
            // Update cache
            Cache::put('system_config', $newConfig, now()->addDay());

            Log::info('Admin updated system config', [
                'admin_id' => $request->user()->id,
                'changes' => $validated,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'System configuration updated',
                'data' => $newConfig,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update system config', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update config',
            ], 500);
        }
    }
}
