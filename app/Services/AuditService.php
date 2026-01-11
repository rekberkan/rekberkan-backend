<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class AuditService
{
    public function log(array $data, ?Request $request = null): AuditLog
    {
        $tenantId = $data['tenant_id'] ?? $this->getCurrentTenantId();

        $prevHash = $this->getLastHashForTenant($tenantId);

        $recordData = [
            'tenant_id' => $tenantId,
            'event_type' => $data['event_type'],
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'actor_id' => $data['actor_id'] ?? null,
            'actor_type' => $data['actor_type'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'prev_hash' => $prevHash,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ];

        $recordHash = $this->calculateRecordHash($recordData);
        $recordData['record_hash'] = $recordHash;

        return AuditLog::create($recordData);
    }

    /**
     * Calculate hash for audit record.
     * 
     * SECURITY FIX: Now includes ALL critical fields to prevent tampering.
     * Previously missing: actor_id, actor_type, ip_address, user_agent
     */
    protected function calculateRecordHash(array $data): string
    {
        $hashInput = json_encode([
            'tenant_id' => $data['tenant_id'],
            'event_type' => $data['event_type'],
            'subject_type' => $data['subject_type'],
            'subject_id' => $data['subject_id'],
            'actor_id' => $data['actor_id'],
            'actor_type' => $data['actor_type'],
            'ip_address' => $data['ip_address'],
            'user_agent' => $data['user_agent'],
            'metadata' => $data['metadata'],
            'prev_hash' => $data['prev_hash'],
            'created_at' => $data['created_at']->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $hashInput);
    }

    protected function getLastHashForTenant(int $tenantId): ?string
    {
        return AuditLog::where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->value('record_hash');
    }

    protected function getCurrentTenantId(): int
    {
        return (int) DB::select("SELECT current_setting('app.current_tenant_id')::bigint as tenant_id")[0]->tenant_id;
    }

    public function verifyChainIntegrity(int $tenantId): array
    {
        $records = AuditLog::where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get();

        $issues = [];
        $prevHash = null;

        foreach ($records as $record) {
            if ($record->prev_hash !== $prevHash) {
                $issues[] = [
                    'record_id' => $record->id,
                    'issue' => 'prev_hash mismatch',
                    'expected' => $prevHash,
                    'actual' => $record->prev_hash,
                ];
            }

            $expectedHash = $this->calculateRecordHash($record->toArray());
            if ($record->record_hash !== $expectedHash) {
                $issues[] = [
                    'record_id' => $record->id,
                    'issue' => 'record_hash tampered',
                    'expected' => $expectedHash,
                    'actual' => $record->record_hash,
                ];
            }

            $prevHash = $record->record_hash;
        }

        return [
            'total_records' => $records->count(),
            'issues_found' => count($issues),
            'issues' => $issues,
            'integrity_valid' => count($issues) === 0,
        ];
    }
}
