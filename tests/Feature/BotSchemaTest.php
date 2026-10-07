<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationHead;
use App\Models\Execution;
use App\Models\InboundMessage;
use App\Models\OutboundPart;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BotSchemaTest extends TestCase
{
    use DatabaseMigrations;

    public function test_state_relations_survive_database_reload(): void
    {
        $part = OutboundPart::factory()->create();
        $message = $part->execution->message;
        $head = $message->head;
        $head->update(['current_conversation_id' => $message->conversation_id]);
        $this->assertSame($message->conversation_id, $head->fresh()->currentConversation->id);
        $this->assertSame($part->id, $message->fresh()->execution->parts->first()->id);
        $this->assertSame($head->id, $part->head->id);
    }

    public function test_message_identity_is_unique_per_instance(): void
    {
        $message = InboundMessage::factory()->create();
        $attributes = $message->only(['conversation_head_id', 'conversation_id', 'instance', 'external_id', 'text', 'accepted_at']);
        $attributes['local_order'] = 2;
        $this->expectException(QueryException::class);
        InboundMessage::create($attributes);
    }

    public function test_only_one_execution_can_belong_to_a_message(): void
    {
        $execution = Execution::factory()->create();
        $this->expectException(QueryException::class);
        Execution::factory()->create(['inbound_message_id' => $execution->inbound_message_id]);
    }

    public function test_response_indexes_are_unique_per_execution(): void
    {
        $part = OutboundPart::factory()->create();
        $this->expectException(QueryException::class);
        OutboundPart::factory()->create($part->only(['execution_id', 'conversation_head_id', 'local_order', 'part_index']));
    }

    public function test_postgres_rejects_simultaneous_duplicate_inserts(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires Docker PostgreSQL and pcntl.');
        }
        $head = ConversationHead::factory()->create();
        $conversation = Conversation::factory()->create(['conversation_head_id' => $head->id]);
        $attributes = ['conversation_head_id' => $head->id, 'conversation_id' => $conversation->id,
            'instance' => $head->instance, 'external_id' => 'simultaneous-id', 'text' => 'Execute uma vez',
            'local_order' => 1, 'accepted_at' => now('UTC')];
        DB::disconnect();
        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::purge();
                try {
                    InboundMessage::create($attributes);
                    exit(0);
                } catch (QueryException $exception) {
                    exit(($exception->errorInfo[0] ?? '') === '23505' ? 10 : 20);
                }
            }
            $children[] = $pid;
        }
        $codes = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $codes[] = pcntl_wexitstatus($status);
        }
        sort($codes);
        $this->assertSame([0, 10], $codes);
        DB::purge();
        $this->assertSame(1, InboundMessage::where('external_id', 'simultaneous-id')->count());
    }
}
