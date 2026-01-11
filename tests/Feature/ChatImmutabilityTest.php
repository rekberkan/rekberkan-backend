<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Escrow;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChatImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $buyer;
    protected User $seller;
    protected Escrow $escrow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->buyer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->seller = User::factory()->create(['tenant_id' => $this->tenant->id]);
        
        $this->escrow = Escrow::factory()->create([
            'tenant_id' => $this->tenant->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
        ]);

        config(['database.connections.pgsql.search_path' => 'public']);
        DB::statement("SET app.current_tenant_id = {$this->tenant->id}");
    }

    public function test_chat_message_can_be_sent(): void
    {
        $chatService = app(ChatService::class);
        
        $message = $chatService->sendMessage(
            escrow: $this->escrow,
            sender: $this->buyer,
            body: 'Hello, when will you ship the item?'
        );

        $this->assertDatabaseHas('chat_messages', [
            'id' => $message->id,
            'escrow_id' => $this->escrow->id,
            'sender_id' => $this->buyer->id,
        ]);
    }

    public function test_chat_message_cannot_be_updated(): void
    {
        $chatService = app(ChatService::class);
        $message = $chatService->sendMessage(
            escrow: $this->escrow,
            sender: $this->buyer,
            body: 'Original message'
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('immutable');

        DB::table('chat_messages')
            ->where('id', $message->id)
            ->update(['body' => 'Modified message']);
    }

    public function test_chat_message_cannot_be_deleted(): void
    {
        $chatService = app(ChatService::class);
        $message = $chatService->sendMessage(
            escrow: $this->escrow,
            sender: $this->buyer,
            body: 'Test message'
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('immutable');

        DB::table('chat_messages')
            ->where('id', $message->id)
            ->delete();
    }

    public function test_non_participant_cannot_send_message(): void
    {
        $nonParticipant = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $chatService = app(ChatService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('not a participant');

        $chatService->sendMessage(
            escrow: $this->escrow,
            sender: $nonParticipant,
            body: 'Unauthorized message'
        );
    }

    public function test_empty_message_cannot_be_sent(): void
    {
        $chatService = app(ChatService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('cannot be empty');

        $chatService->sendMessage(
            escrow: $this->escrow,
            sender: $this->buyer,
            body: '   '
        );
    }

    public function test_message_exceeding_max_length_rejected(): void
    {
        $chatService = app(ChatService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('exceeds maximum length');

        $chatService->sendMessage(
            escrow: $this->escrow,
            sender: $this->buyer,
            body: str_repeat('a', 5001)
        );
    }
}
