<?php

namespace Tests\Feature;

use App\Models\Execution;
use App\Models\InboundMessage;
use App\Models\OutboundPart;
use App\Services\AcceptMessage;
use App\Services\ProcessExecutions;
use App\Support\BotConfig;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExecutionRecoveryTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bot.owner_number' => '5511999990000', 'bot.instance' => 'owner-bot',
            'bot.webhook_secret' => str_repeat('w', 32), 'bot.runner_token' => str_repeat('r', 32),
            'bot.evolution_key' => str_repeat('e', 32)]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function accept(string $id): InboundMessage
    {
        return app(AcceptMessage::class)->accept(['external_id' => $id, 'instance' => 'owner-bot', 'number' => '5511999990000', 'text' => 'Faça '.$id.' $(touch /tmp/never)']);
    }

    public function test_lost_post_response_reconciles_same_id_after_worker_restart_without_replay(): void
    {
        $message = $this->accept('first');
        $execution = $message->execution;
        $session = (string) Str::uuid();
        $accepted = false;
        $posts = 0;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$accepted, &$posts, $execution, $session) {
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.str_repeat('r', 32)));
            if ($request->method() === 'POST') {
                $posts++;
                $accepted = true;
                $this->assertSame($execution->runner_id, $request['id']);
                $this->assertSame($execution->message->text, $request['prompt']);
                throw new ConnectionException('lost response');
            }

            return $accepted ? Http::response(['id' => $execution->runner_id, 'status' => 'succeeded', 'result_session_id' => $session, 'answer' => 'Pronto 😀']) : Http::response([], 404);
        });
        app(ProcessExecutions::class)->tick();
        $this->assertSame('running', $execution->fresh()->status);
        $this->app->forgetInstance(ProcessExecutions::class);
        app(ProcessExecutions::class)->tick();
        app(ProcessExecutions::class)->tick();
        $this->assertSame(1, $posts);
        $this->assertSame('succeeded', $message->fresh()->status);
        $this->assertSame($session, $message->conversation->fresh()->session_id);
        $this->assertSame('Pronto 😀', OutboundPart::sole()->text);
    }

    public function test_burst_stays_serial_across_generations_and_late_result_updates_original_conversation(): void
    {
        CarbonImmutable::setTestNow('2026-10-07 12:00:00 UTC');
        $first = $this->accept('first');
        $followup = $this->accept('followup');
        CarbonImmutable::setTestNow('2026-10-07 12:10:00 UTC');
        $reset = $this->accept('reset');
        $firstExecution = $first->execution;
        $session = (string) Str::uuid();
        $done = false;
        $contacts = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$done, &$contacts, $firstExecution, $session) {
            $contacts[] = $request->url();

            return Http::response(['id' => $firstExecution->runner_id, 'status' => $done ? 'succeeded' : 'running', 'result_session_id' => $session, 'answer' => 'Concluído']);
        });
        app(ProcessExecutions::class)->tick();
        app(ProcessExecutions::class)->tick();
        $this->assertSame('pending', $followup->execution->fresh()->status);
        $this->assertSame('pending', $reset->execution->fresh()->status);
        $this->assertSame([$contacts[0], $contacts[0]], $contacts);
        $done = true;
        app(ProcessExecutions::class)->tick();
        $this->assertSame($session, $first->conversation->fresh()->session_id);
        $this->assertNull($reset->conversation->fresh()->session_id);
        $this->assertSame($reset->conversation_id, $reset->head->fresh()->current_conversation_id);
        $followupExecution = $followup->execution;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['id' => $followupExecution->runner_id, 'status' => 'running'])]);
        app(ProcessExecutions::class)->tick();
        $this->assertSame($session, $followupExecution->fresh()->session_id);
        $this->assertSame('pending', $reset->execution->fresh()->status);
    }

    public function test_global_lease_and_duplicate_tick_prevent_different_execution_claims(): void
    {
        $message = $this->accept('first');
        $lock = Cache::lock('bot:workspace', app(BotConfig::class)->leaseSeconds, 'another-owner');
        $this->assertTrue($lock->get());
        app(ProcessExecutions::class)->tick();
        $this->assertSame('pending', $message->execution->fresh()->status);
        Http::assertNothingSent();
        $lock->release();
        $tick = Cache::lock('bot:execution-tick', 60);
        $tick->get();
        app(ProcessExecutions::class)->tick();
        Http::assertNothingSent();
    }

    public static function failures(): array
    {
        return [['failed', 'invalid_session'], ['uncertain', 'runner_interrupted'], ['failed', 'timeout'], ['failed', 'authentication'], ['failed', 'usage_limit'], ['failed', 'secret=do-not-publish']];
    }

    #[DataProvider('failures')]
    public function test_failures_clear_session_and_do_not_replay_current_prompt(string $status, string $error): void
    {
        $first = $this->accept('first');
        $first->conversation->update(['session_id' => (string) Str::uuid()]);
        $execution = $first->execution;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['id' => $execution->runner_id, 'status' => $status, 'error' => $error])]);
        app(ProcessExecutions::class)->tick();
        app(ProcessExecutions::class)->tick();
        Http::assertSentCount(1);
        $this->assertSame($status, $first->fresh()->status);
        $this->assertNull($first->conversation->fresh()->session_id);
        $this->assertStringNotContainsString('secret=', OutboundPart::sole()->text);
        $next = $this->accept('next');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['id' => $next->execution->runner_id, 'status' => 'running'])]);
        app(ProcessExecutions::class)->tick();
        $this->assertNull($next->execution->fresh()->session_id);
        $this->assertSame(2, Execution::count());
    }

    public function test_polling_unavailable_preserves_running_id_and_blocks_new_work(): void
    {
        $first = $this->accept('first');
        $second = $this->accept('second');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('unavailable'));
        app(ProcessExecutions::class)->tick();
        $runnerId = $first->execution->runner_id;
        $this->travel(20)->minutes();
        app(ProcessExecutions::class)->tick();
        $this->assertSame($runnerId, $first->execution->fresh()->runner_id);
        $this->assertSame('running', $first->execution->fresh()->status);
        $this->assertSame('pending', $second->execution->fresh()->status);
    }

    public function test_pause_drains_started_execution_and_blocks_next_claim_until_resume(): void
    {
        $first = $this->accept('first');
        $second = $this->accept('second');
        $execution = $first->execution;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['id' => $execution->runner_id, 'status' => 'running'])]);
        app(ProcessExecutions::class)->tick();
        $this->artisan('bot:status --pause')->assertSuccessful();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['id' => $execution->runner_id, 'status' => 'succeeded', 'answer' => 'Pronto', 'result_session_id' => (string) Str::uuid()])]);
        app(ProcessExecutions::class)->tick();
        app(ProcessExecutions::class)->tick();
        Http::assertSentCount(1);
        $this->assertSame('succeeded', $first->fresh()->status);
        $this->assertSame('pending', $second->execution->fresh()->status);
        $this->artisan('bot:status --resume')->assertSuccessful();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['id' => $second->execution->runner_id, 'status' => 'running'])]);
        app(ProcessExecutions::class)->tick();
        $this->assertSame('running', $second->execution->fresh()->status);
    }
}
