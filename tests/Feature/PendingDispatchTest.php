<?php

namespace Tests\Feature;

use App\Jobs\PrepareExecution;
use App\Models\Execution;
use App\Models\InboundMessage;
use App\Services\AcceptMessage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PendingDispatchTest extends TestCase
{
    use DatabaseMigrations;

    public function test_dispatch_waits_for_outer_commit_and_rollback_removes_message(): void
    {
        config(['queue.default' => 'database']);
        DB::beginTransaction();
        $message = app(AcceptMessage::class)->accept(['external_id' => 'committed', 'instance' => 'owner-bot', 'number' => '5511999990000', 'text' => 'Olá']);
        $this->assertDatabaseCount('jobs', 0);

        DB::commit();

        $this->assertDatabaseCount('jobs', 1);
        $this->assertModelExists($message);
        DB::beginTransaction();
        app(AcceptMessage::class)->accept(['external_id' => 'rolled-back', 'instance' => 'owner-bot', 'number' => '5511999990000', 'text' => 'Olá']);
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseMissing('inbound_messages', ['external_id' => 'rolled-back']);
    }

    public function test_scheduler_recovers_receipt_after_crash_before_enqueue(): void
    {
        $message = InboundMessage::factory()->create();
        $done = InboundMessage::factory()->create(['status' => 'succeeded']);
        Queue::fake([PrepareExecution::class]);

        $this->artisan('bot:dispatch-pending')->assertSuccessful();

        Queue::assertPushed(PrepareExecution::class, fn (PrepareExecution $job): bool => $job->messageId === $message->id);
        Queue::assertNotPushed(PrepareExecution::class, fn (PrepareExecution $job): bool => $job->messageId === $done->id);
    }

    public function test_repeated_dispatch_preserves_one_execution_and_runner_id(): void
    {
        $message = InboundMessage::factory()->create();
        (new PrepareExecution($message->id))->handle();
        $runnerId = $message->execution->runner_id;

        $this->artisan('bot:dispatch-pending')->assertSuccessful();
        (new PrepareExecution($message->id))->handle();

        $this->assertSame(1, Execution::count());
        $this->assertSame($runnerId, $message->fresh()->execution->runner_id);
    }

    public function test_duplicate_receipt_does_not_dispatch_again(): void
    {
        Queue::fake([PrepareExecution::class]);
        $input = ['external_id' => 'duplicate', 'instance' => 'owner-bot', 'number' => '5511999990000', 'text' => 'Olá'];
        app(AcceptMessage::class)->accept($input);

        app(AcceptMessage::class)->accept($input);

        Queue::assertPushed(PrepareExecution::class, 1);
        $this->assertDatabaseCount('inbound_messages', 1);
    }
}
