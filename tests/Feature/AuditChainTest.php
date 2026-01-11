<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\AuditLog;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditChainTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        config(['database.connections.pgsql.search_path' => 'public']);
        DB::statement("SET app.current_tenant_id = {$this->tenant->id}");
    }

    public function test_audit_log_creates_hash_chain(): void
    {
        $auditService = app(AuditService::class);

        $log1 = $auditService->log([
            'tenant_id' => $this->tenant->id,
            'event_type' => 'TEST_EVENT_1',
            'metadata' => ['data' => 'first'],
        ]);

        $this->assertNull($log1->prev_hash);
        $this->assertNotNull($log1->record_hash);

        $log2 = $auditService->log([
            'tenant_id' => $this->tenant->id,
            'event_type' => 'TEST_EVENT_2',
            'metadata' => ['data' => 'second'],
        ]);

        $this->assertEquals($log1->record_hash, $log2->prev_hash);
        $this->assertNotEquals($log1->record_hash, $log2->record_hash);
    }

    public function test_audit_chain_verification_passes_on_valid_chain(): void
    {
        $auditService = app(AuditService::class);

        for ($i = 0; $i < 5; $i++) {
            $auditService->log([
                'tenant_id' => $this->tenant->id,
                'event_type' => "TEST_EVENT_{$i}",
            ]);
        }

        $result = $auditService->verifyChainIntegrity($this->tenant->id);

        $this->assertTrue($result['integrity_valid']);
        $this->assertEquals(5, $result['total_records']);
        $this->assertEquals(0, $result['issues_found']);
    }

    public function test_audit_log_is_immutable(): void
    {
        $auditService = app(AuditService::class);
        $log = $auditService->log([
            'tenant_id' => $this->tenant->id,
            'event_type' => 'TEST_EVENT',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('WORM');

        DB::table('audit_log')
            ->where('id', $log->id)
            ->update(['event_type' => 'TAMPERED']);
    }

    public function test_audit_log_cannot_be_deleted(): void
    {
        $auditService = app(AuditService::class);
        $log = $auditService->log([
            'tenant_id' => $this->tenant->id,
            'event_type' => 'TEST_EVENT',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('WORM');

        DB::table('audit_log')
            ->where('id', $log->id)
            ->delete();
    }

    public function test_hash_chain_verification_detects_tampering(): void
    {
        $auditService = app(AuditService::class);

        $log1 = $auditService->log([
            'tenant_id' => $this->tenant->id,
            'event_type' => 'TEST_EVENT_1',
        ]);

        $log2 = $auditService->log([
            'tenant_id' => $this->tenant->id,
            'event_type' => 'TEST_EVENT_2',
        ]);

        DB::statement('ALTER TABLE audit_log DISABLE TRIGGER prevent_audit_log_update');
        DB::table('audit_log')
            ->where('id', $log1->id)
            ->update(['record_hash' => 'tampered_hash']);
        DB::statement('ALTER TABLE audit_log ENABLE TRIGGER prevent_audit_log_update');

        $result = $auditService->verifyChainIntegrity($this->tenant->id);

        $this->assertFalse($result['integrity_valid']);
        $this->assertGreaterThan(0, $result['issues_found']);
    }
}
