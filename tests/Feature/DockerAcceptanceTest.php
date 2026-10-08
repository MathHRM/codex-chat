<?php

namespace Tests\Feature;

use App\Models\ConversationHead;
use App\Models\Execution;
use App\Models\InboundMessage;
use App\Models\OutboundPart;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DockerAcceptanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DOCKER_ACCEPTANCE') !== '1') {
            $this->markTestSkipped('Requires the isolated Compose acceptance project.');
        }
    }

    public function test_core_flow_with_real_http_database_queue_and_runner(): void
    {
        $started = microtime(true);
        $first = $this->ingress('core-first', 'first');
        $this->assertLessThan(2, microtime(true) - $started);
        $this->assertSame($first->id, $this->ingress('core-first', 'first')->id);
        $this->await(fn () => $first->fresh()->status === 'succeeded' && $this->delivered($first));
        $session = $first->conversation->fresh()->session_id;
        $this->assertNotNull($session);
        $this->assertSame('Arquivo alterado pelo agente simulado.', file_get_contents('/acceptance-workspace/acceptance.txt'));
        $followup = $this->ingress('core-followup', 'followup');
        $this->await(fn () => $followup->fresh()->status === 'succeeded' && $this->delivered($followup));
        $this->assertSame($first->conversation_id, $followup->conversation_id);
        $this->assertSame($session, $followup->execution->fresh()->session_id);
        ConversationHead::sole()->update(['last_accepted_at' => now('UTC')->subSeconds(600)]);
        $reset = $this->ingress('core-reset', 'reset');
        $this->await(fn () => $reset->fresh()->status === 'succeeded' && $this->delivered($reset));
        $this->assertNotSame($first->conversation_id, $reset->conversation_id);
        $this->assertNull($reset->execution->fresh()->session_id);
        $this->assertNotSame($session, $reset->conversation->fresh()->session_id);
        $before = ConversationHead::sole()->last_accepted_at;
        $payload = $this->payload('foreign', 'must-not-execute');
        $payload['data']['key']['remoteJid'] = '5511888880000@s.whatsapp.net';
        $response = Http::withHeaders(['X-Webhook-Secret' => getenv('WEBHOOK_SECRET')])->post('http://app:8000/api/v1/webhooks/evolution', $payload);
        $this->assertSame('ignored', $response->json('status'));
        $this->assertTrue($before->equalTo(ConversationHead::sole()->last_accepted_at));
        $this->assertSame(3, InboundMessage::count());
        $effects = $this->effects();
        $this->assertCount(3, $effects);
        $this->assertSame([false, true, false], array_column($effects, 'resume'));
        $this->assertSame([true, true, true], array_column($effects, 'instructions'));
        $sent = Http::get('http://evolution:8080/_test/status')->json('sent');
        $this->assertSame(['Concluído 😀 first', 'Concluído 😀 followup', 'Concluído 😀 reset'], array_column($sent, 'text'));
        $this->assertSame(['5511999990000'], array_values(array_unique(array_column($sent, 'number'))));
    }

    public function test_prepare_interrupted_execution_after_effect(): void
    {
        $message = $this->ingress('crash', 'crash-after-effect');
        $this->ingress('after-crash', 'after-crash');
        $this->await(fn () => $message->fresh()->status === 'running' && count($this->effects()) === 4);
        $this->assertSame('pending', InboundMessage::where('external_id', 'after-crash')->sole()->status);
        $this->assertSame('running', $message->execution->fresh()->status);
    }

    public function test_reconcile_interrupted_runner_without_repeating_effect_and_start_new_session(): void
    {
        $message = InboundMessage::where('external_id', 'crash')->sole();
        $next = InboundMessage::where('external_id', 'after-crash')->sole();
        $this->await(fn () => $message->fresh()->status === 'uncertain' && $next->fresh()->status === 'succeeded' && $this->delivered($next));
        $this->assertSame('runner_interrupted', $message->execution->fresh()->error_code);
        $this->assertNull($next->execution->fresh()->session_id);
        $effects = $this->effects();
        $this->assertCount(5, $effects);
        $this->assertCount(1, array_filter($effects, fn ($effect) => $effect['run_id'] === $message->execution->runner_id));
        $this->assertStringNotContainsString('PRIVATE_TOOL_LOG', OutboundPart::where('execution_id', $message->execution->id)->sole()->text);
    }

    public function test_timeout_after_external_acceptance_requires_explicit_reconciliation(): void
    {
        Http::post('http://evolution:8080/_test/mode', ['mode' => 'timeout'])->throw();
        $message = $this->ingress('delivery-timeout', 'delivery-timeout');
        $this->await(fn () => $message->fresh()->status === 'succeeded' && OutboundPart::where('execution_id', $message->execution->id)->where('status', 'uncertain')->exists());
        $part = OutboundPart::where('execution_id', $message->execution->id)->sole();
        $this->assertSame('evolution_timeout', $part->error_code);
        $accepted = array_values(array_filter(Http::get('http://evolution:8080/_test/status')->json('sent'), fn ($sent) => $sent['text'] === 'Concluído 😀 delivery-timeout'));
        $this->assertCount(1, $accepted);
        Http::post('http://evolution:8080/_test/mode', ['mode' => 'success'])->throw();
        $later = $this->ingress('blocked-delivery', 'blocked-delivery');
        $this->await(fn () => $later->fresh()->status === 'succeeded');
        $this->assertSame('pending', OutboundPart::where('execution_id', $later->execution->id)->sole()->status);
        $this->assertSame(1, $part->fresh()->attempts);
        $this->artisan('bot:delivery', ['part' => $part->id, '--sent' => $accepted[0]['id']])->assertSuccessful();
        $this->await(fn () => $this->delivered($later));
        $this->assertCount(7, $this->effects());
        $this->assertSame(1, $part->fresh()->attempts);
    }

    public function test_normal_recreation_and_pause_preserve_data_and_resume_pending_work(): void
    {
        $this->assertSame(7, Execution::count());
        $this->assertCount(7, $this->effects());
        $this->assertFileExists('/acceptance-workspace/.git/HEAD');
        $this->artisan('bot:status --pause')->assertSuccessful();
        $message = $this->ingress('paused', 'paused');
        sleep(6);
        $this->assertSame('pending', $message->fresh()->status);
        $this->assertCount(7, $this->effects());
        $this->artisan('bot:status --resume')->assertSuccessful();
        $this->await(fn () => $message->fresh()->status === 'succeeded' && $this->delivered($message));
        $this->assertCount(8, $this->effects());
        $this->assertSame(8, Execution::count());
    }

    public function test_diagnostics_report_stopped_external_services_and_stale_heartbeats(): void
    {
        $this->artisan('bot:status --external')
            ->expectsOutput('runner=indisponivel')->expectsOutput('whatsapp=indisponivel')->assertSuccessful();
        $this->artisan('bot:health worker')->assertSuccessful();
        $this->artisan('bot:health scheduler')->assertSuccessful();
        Cache::put('bot:heartbeat:worker', time() - 121, 120);
        $this->artisan('bot:health worker')->assertFailed();
        Cache::put('bot:heartbeat:worker', time(), 120);
        $this->assertSame(8, Execution::count());
    }

    private function payload(string $id, string $text): array
    {
        $payload = json_decode(file_get_contents(__DIR__.'/../Fixtures/evolution/private-text.json'), true);
        $payload['data']['key']['id'] = $id;
        $payload['data']['message']['conversation'] = $text;

        return $payload;
    }

    private function ingress(string $id, string $text): InboundMessage
    {
        $response = Http::withHeaders(['X-Webhook-Secret' => getenv('WEBHOOK_SECRET')])->timeout(3)->post('http://app:8000/api/v1/webhooks/evolution', $this->payload($id, $text));
        $this->assertSame(202, $response->status());
        $this->assertSame('accepted', $response->json('status'));

        return InboundMessage::findOrFail($response->json('message_id'));
    }

    private function delivered(InboundMessage $message): bool
    {
        return OutboundPart::where('execution_id', $message->execution->id)->exists()
            && ! OutboundPart::where('execution_id', $message->execution->id)->where('status', '!=', 'sent')->exists();
    }

    private function effects(): array
    {
        return is_file('/acceptance-workspace/effects.jsonl') ? array_map(fn ($line) => json_decode($line, true), file('/acceptance-workspace/effects.jsonl', FILE_IGNORE_NEW_LINES)) : [];
    }

    private function await(callable $condition): void
    {
        $deadline = microtime(true) + 100;
        do {
            if ($condition()) {
                $this->assertTrue(true);

                return;
            }
            usleep(250000);
        } while (microtime(true) < $deadline);
        $this->fail('Integrated services did not reach the expected durable state.');
    }
}
