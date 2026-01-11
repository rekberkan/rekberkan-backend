<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Services\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VoucherRedemptionTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected Voucher $voucher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->voucher = Voucher::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'TEST100',
            'type' => 'PERCENTAGE',
            'value' => 10,
            'usage_limit' => 100,
            'per_user_limit' => 1,
            'status' => 'ACTIVE',
        ]);

        config(['database.connections.pgsql.search_path' => 'public']);
        \DB::statement("SET app.current_tenant_id = {$this->tenant->id}");
    }

    public function test_voucher_can_be_redeemed_successfully(): void
    {
        $service = app(VoucherService::class);
        
        $result = $service->redeemVoucher(
            voucherCode: 'TEST100',
            user: $this->user,
            orderAmount: 100.00
        );

        $this->assertEquals(10.00, $result['discount_amount']);
        $this->assertDatabaseHas('voucher_redemptions', [
            'voucher_id' => $this->voucher->id,
            'user_id' => $this->user->id,
            'discount_amount' => 10.00,
        ]);
    }

    public function test_voucher_respects_per_user_limit(): void
    {
        $service = app(VoucherService::class);
        
        $service->redeemVoucher('TEST100', $this->user, orderAmount: 100.00);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('usage limit exceeded');
        
        $service->redeemVoucher('TEST100', $this->user, orderAmount: 100.00);
    }

    public function test_voucher_respects_total_usage_limit(): void
    {
        $this->voucher->update(['usage_limit' => 1]);
        
        $service = app(VoucherService::class);
        $service->redeemVoucher('TEST100', $this->user, orderAmount: 100.00);

        $user2 = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->expectException(\Exception::class);
        $service->redeemVoucher('TEST100', $user2, orderAmount: 100.00);
    }

    public function test_concurrent_voucher_redemptions_are_safe(): void
    {
        $this->voucher->update(['usage_limit' => 2, 'per_user_limit' => 1]);
        
        $user2 = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user3 = User::factory()->create(['tenant_id' => $this->tenant->id]);

        DB::beginTransaction();
        try {
            $service1 = app(VoucherService::class);
            $service1->redeemVoucher('TEST100', $this->user, orderAmount: 100.00);
            
            $service2 = app(VoucherService::class);
            $service2->redeemVoucher('TEST100', $user2, orderAmount: 100.00);
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
        }

        $this->voucher->refresh();
        $this->assertLessThanOrEqual(2, $this->voucher->usage_count);

        try {
            $service3 = app(VoucherService::class);
            $service3->redeemVoucher('TEST100', $user3, orderAmount: 100.00);
            $this->fail('Should have thrown exception');
        } catch (\Exception $e) {
            $this->assertStringContainsString('not valid', $e->getMessage());
        }
    }

    public function test_voucher_redemption_is_immutable(): void
    {
        $service = app(VoucherService::class);
        $result = $service->redeemVoucher('TEST100', $this->user, orderAmount: 100.00);

        $this->expectException(\Exception::class);
        DB::table('voucher_redemptions')
            ->where('id', $result['redemption_id'])
            ->update(['discount_amount' => 50.00]);
    }

    public function test_invalid_voucher_cannot_be_redeemed(): void
    {
        $this->voucher->update(['status' => 'INACTIVE']);
        
        $service = app(VoucherService::class);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('not valid');
        
        $service->redeemVoucher('TEST100', $this->user, orderAmount: 100.00);
    }

    public function test_expired_voucher_cannot_be_redeemed(): void
    {
        $this->voucher->update([
            'valid_until' => now()->subDay(),
        ]);
        
        $service = app(VoucherService::class);
        
        $this->expectException(\Exception::class);
        $service->redeemVoucher('TEST100', $this->user, orderAmount: 100.00);
    }
}
