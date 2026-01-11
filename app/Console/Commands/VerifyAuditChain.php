<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\AuditService;
use Illuminate\Console\Command;

class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify {--tenant= : Specific tenant ID to verify}';

    protected $description = 'Verify audit log hash-chain integrity for tamper detection';

    public function handle(AuditService $auditService): int
    {
        $tenantId = $this->option('tenant');

        if ($tenantId) {
            return $this->verifyTenant((int) $tenantId, $auditService);
        }

        $tenants = Tenant::all();
        $failedTenants = [];

        foreach ($tenants as $tenant) {
            $this->info("Verifying tenant: {$tenant->name} (ID: {$tenant->id})");
            
            if ($this->verifyTenant($tenant->id, $auditService) !== 0) {
                $failedTenants[] = $tenant->id;
            }
        }

        if (count($failedTenants) > 0) {
            $this->error('\nIntegrity check FAILED for tenants: ' . implode(', ', $failedTenants));
            return 1;
        }

        $this->info('\nAll tenants passed integrity check');
        return 0;
    }

    protected function verifyTenant(int $tenantId, AuditService $auditService): int
    {
        try {
            $result = $auditService->verifyChainIntegrity($tenantId);

            if ($result['integrity_valid']) {
                $this->info("  ✓ Verified {$result['total_records']} records - PASS");
                return 0;
            }

            $this->error("  ✗ Found {$result['issues_found']} integrity issues:");
            foreach ($result['issues'] as $issue) {
                $this->error("    Record #{$issue['record_id']}: {$issue['issue']}");
                if (isset($issue['expected'])) {
                    $this->line("      Expected: {$issue['expected']}");
                    $this->line("      Actual: {$issue['actual']}");
                }
            }

            return 1;
        } catch (\Exception $e) {
            $this->error("  ✗ Error verifying tenant {$tenantId}: {$e->getMessage()}");
            return 1;
        }
    }
}
