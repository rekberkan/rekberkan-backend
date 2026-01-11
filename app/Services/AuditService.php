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
     * SECURITY: Includes ALL critical fields to prevent tampering.
     * Ensures created_at is consistently formatted as ISO8601 string.
     */
    protected function calculateRecordHash(array $data): string
    {
        // Normalize created_at to consistent string format
        $createdAt = $data['created_at'] ?? null;
        
        if ($createdAt instanceof \DateTimeInterface) {
            // Carbon or DateTime object
            $createdAtString = $createdAt->format('c'); // ISO8601 format
        } elseif (is_string($createdAt)) {
            // String date, parse and reformat for consistency
            try {
                $createdAtString = \Carbon\Carbon::parse($createdAt)->format('c');
            } catch (\Exception $e) {
                $createdAtString = null;
            }
        } else {
            $createdAtString = null;
        }

        // Build hash input with sorted keys for consistency
        $hashData = [
            'tenant_id' => $data['tenant_id'],
            'event_type' => $data['event_type'],
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'actor_id' => $data['actor_id'] ?? null,
            'actor_type' => $data['actor_type'] ?? null,
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'prev_hash' => $data['prev_hash'] ?? null,
            'created_at' => $createdAtString,
        ];

        // Sort keys to ensure consistent ordering
        ksort($hashData);

        $hashInput = json_encode($hashData, JSON_THROW_ON_ERROR);

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
        $result = DB::selectOne("SELECT current_setting('app.current_tenant_id', true) as tenant_id");

        return $result && $result->tenant_id ? (int) $result->tenant_id : 0;
    }

    /**
     * Verify audit trail chain integrity.
     */
    public function verifyChainIntegrity(int $tenantId): array
    {
        $records = AuditLog::where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get();

        $issues = [];
        $prevHash = null;

        foreach ($records as $record) {
            // Check prev_hash chain
            if ($record->prev_hash !== $prevHash) {
                $issues[] = [
                    'record_id' => $record->id,
                    'issue' => 'prev_hash mismatch',
                    'expected' => $prevHash,
                    'actual' => $record->prev_hash,
                ];
            }

            // Verify record hash hasn't been tampered with
            $recordArray = [
                'tenant_id' => $record->tenant_id,
                'event_type' => $record->event_type,
                'subject_type' => $record->subject_type,
                'subject_id' => $record->subject_id,
                'actor_id' => $record->actor_id,
                'actor_type' => $record->actor_type,
                'ip_address' => $record->ip_address,
                'user_agent' => $record->user_agent,
                'metadata' => $record->metadata,
                'prev_hash' => $record->prev_hash,
                'created_at' => $record->created_at, // Carbon instance
            ];

            $expectedHash = $this->calculateRecordHash($recordArray);
            
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
