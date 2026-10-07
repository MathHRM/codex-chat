<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationHead;
use App\Models\Execution;
use App\Models\InboundMessage;
use App\Services\AcceptMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConversationPolicyTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function input(string $id): array
    {
        return ['external_id' => $id, 'instance' => 'owner-bot', 'number' => '5511999990000', 'text' => 'Faça a tarefa '.$id];
    }

    public function test_first_receipt_creates_generation_and_uses_server_utc_time(): void
    {
        CarbonImmutable::setTestNow('2026-10-07 12:00:00 UTC');
        $message = app(AcceptMessage::class)->accept($this->input('first'));
        $this->assertSame(1, $message->local_order);
        $this->assertSame(1, $message->conversation->generation);
        $this->assertSame('2026-10-07 12:00:00', $message->accepted_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($message->conversation->session_id);
        $this->assertSame($message->conversation_id, $message->head->current_conversation_id);
    }

    public function test_599_seconds_preserves_context_and_exactly_600_starts_a_new_generation(): void
    {
        CarbonImmutable::setTestNow('2026-10-07 12:00:00 UTC');
        $first = app(AcceptMessage::class)->accept($this->input('first'));
        $first->conversation->update(['session_id' => 'original-session']);
        CarbonImmutable::setTestNow('2026-10-07 12:09:59 UTC');
        $followup = app(AcceptMessage::class)->accept($this->input('followup'));
        $this->assertSame($first->conversation_id, $followup->conversation_id);
        $this->assertSame('original-session', $followup->conversation->session_id);
        CarbonImmutable::setTestNow('2026-10-07 12:19:59 UTC');
        $reset = app(AcceptMessage::class)->accept($this->input('reset'));
        $this->assertNotSame($first->conversation_id, $reset->conversation_id);
        $this->assertSame(2, $reset->conversation->generation);
        $this->assertNull($reset->conversation->session_id);
        $this->assertSame([1, 2, 3], InboundMessage::orderBy('local_order')->pluck('local_order')->all());
    }

    public function test_duplicate_does_not_renew_activity_or_change_original_association(): void
    {
        CarbonImmutable::setTestNow('2026-10-07 12:00:00 UTC');
        $first = app(AcceptMessage::class)->accept($this->input('duplicate'));
        CarbonImmutable::setTestNow('2026-10-07 12:09:59 UTC');
        $duplicate = app(AcceptMessage::class)->accept($this->input('duplicate'));
        $this->assertSame($first->id, $duplicate->id);
        $this->assertSame(1, $duplicate->head->next_order);
        $this->assertTrue($duplicate->head->last_accepted_at->equalTo($first->accepted_at));
        CarbonImmutable::setTestNow('2026-10-07 12:10:00 UTC');
        $reset = app(AcceptMessage::class)->accept($this->input('reset'));
        $this->assertNotSame($first->conversation_id, $reset->conversation_id);
        $oldDuplicate = app(AcceptMessage::class)->accept($this->input('duplicate'));
        $this->assertSame($first->conversation_id, $oldDuplicate->conversation_id);
        $this->assertSame($reset->conversation_id, ConversationHead::firstOrFail()->current_conversation_id);
        $this->assertSame(2, Conversation::count());
    }

    public function test_queue_delay_and_active_old_execution_do_not_prevent_new_generation(): void
    {
        CarbonImmutable::setTestNow('2026-10-07 12:00:00 UTC');
        $first = app(AcceptMessage::class)->accept($this->input('old'));
        $execution = Execution::factory()->create(['inbound_message_id' => $first->id, 'status' => 'running']);
        CarbonImmutable::setTestNow('2026-10-07 12:10:00 UTC');
        $new = app(AcceptMessage::class)->accept($this->input('new'));
        $this->assertNotSame($first->conversation_id, $new->conversation_id);
        $execution->update(['status' => 'succeeded', 'final_response' => 'Final antigo']);
        $first->conversation->update(['session_id' => 'late-session']);
        $this->assertSame($new->conversation_id, $first->head->fresh()->current_conversation_id);
        $this->assertNull($new->conversation->fresh()->session_id);
        $this->assertSame($first->conversation_id, $execution->fresh()->message->conversation_id);
    }

    public function test_simultaneous_receipts_of_same_message_accept_only_once(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires Docker PostgreSQL and pcntl.');
        }
        DB::disconnect();
        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::purge();
                app(AcceptMessage::class)->accept($this->input('concurrent'));
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        DB::purge();
        $this->assertSame(1, InboundMessage::count());
        $this->assertSame(1, Conversation::count());
        $this->assertSame(1, ConversationHead::firstOrFail()->next_order);
    }
}
