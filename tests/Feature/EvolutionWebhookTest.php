<?php

namespace Tests\Feature;

use App\Jobs\PrepareExecution;
use App\Models\ConversationHead;
use App\Models\InboundMessage;
use App\Models\OutboundPart;
use App\Services\EvolutionContract;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EvolutionWebhookTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bot.owner_number' => '5511999990000', 'bot.instance' => 'owner-bot',
            'bot.webhook_secret' => str_repeat('w', 32), 'bot.runner_token' => str_repeat('r', 32),
            'bot.evolution_key' => str_repeat('e', 32)]);
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function payload(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/evolution/private-text.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function invalidSecrets(): array
    {
        return ['missing' => [null], 'invalid' => ['incorrect']];
    }

    #[DataProvider('invalidSecrets')]
    public function test_authentication_rejects_before_payload_validation(?string $secret): void
    {
        $this->postJson('/api/v1/webhooks/evolution', [], $secret === null ? [] : ['X-Webhook-Secret' => $secret])->assertUnauthorized();
        $this->assertDatabaseCount('conversation_heads', 0);
        Queue::assertNothingPushed();
    }

    public function test_malformed_payload_is_rejected_without_activity(): void
    {
        $payload = $this->payload();
        $payload['data']['key'] = 'invalid';
        $this->postJson('/api/v1/webhooks/evolution', $payload, ['X-Webhook-Secret' => str_repeat('w', 32)])
            ->assertUnprocessable()->assertJsonValidationErrors(['data.key', 'data.key.id']);
        $this->assertDatabaseCount('conversation_heads', 0);
        Queue::assertNothingPushed();
    }

    public function test_authorized_input_is_persisted_and_acknowledged_without_contacting_runner(): void
    {
        $this->postJson('/api/v1/webhooks/evolution', $this->payload(), ['X-Webhook-Secret' => str_repeat('w', 32)])
            ->assertAccepted()->assertJsonPath('status', 'accepted');
        $message = InboundMessage::sole();
        $this->assertSame('Crie um arquivo exemplo.txt', $message->text);
        $this->assertSame(1, $message->conversation->generation);
        Queue::assertPushed(PrepareExecution::class, fn (PrepareExecution $job): bool => $job->messageId === $message->id);
        Http::assertNothingSent();
    }

    public function test_self_message_is_accepted_once_and_bot_echo_is_ignored_during_send(): void
    {
        $payload = $this->payload();
        $payload['data']['key']['fromMe'] = true;
        $headers = ['X-Webhook-Secret' => str_repeat('w', 32)];
        $this->postJson('/api/v1/webhooks/evolution', $payload, $headers)
            ->assertAccepted()->assertJsonPath('status', 'accepted');
        $this->postJson('/api/v1/webhooks/evolution', $payload, $headers)
            ->assertAccepted()->assertJsonPath('status', 'accepted');

        Http::fake(function ($request) use ($payload, $headers) {
            $this->assertSame("🤖 Codex:\nResposta", $request['text']);
            $payload['data']['key']['id'] = 'BOT-RESPONSE';
            $payload['data']['messageType'] = 'extendedTextMessage';
            $payload['data']['message'] = ['extendedTextMessage' => ['text' => $request['text']]];
            foreach ([true, false] as $fromMe) {
                $payload['data']['key']['fromMe'] = $fromMe;
                $this->postJson('/api/v1/webhooks/evolution', $payload, $headers)
                    ->assertAccepted()->assertJsonPath('status', 'ignored');
            }

            return Http::response(['key' => ['id' => 'BOT-RESPONSE']], 201);
        });
        app(EvolutionContract::class)->sendText('Resposta');

        $this->assertDatabaseCount('inbound_messages', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('outbound_parts', 0);
        Queue::assertPushed(PrepareExecution::class, 1);
        Http::assertSentCount(1);
    }

    public function test_prompt_whitespace_is_preserved(): void
    {
        $payload = $this->payload();
        $payload['data']['message']['conversation'] = "  Texto com espaços\n";
        $this->postJson('/api/v1/webhooks/evolution', $payload, ['X-Webhook-Secret' => str_repeat('w', 32)])->assertAccepted();
        $this->assertSame("  Texto com espaços\n", InboundMessage::sole()->text);
    }

    public function test_duplicate_receipt_preserves_activity_and_dispatches_once(): void
    {
        $payload = $this->payload();
        $headers = ['X-Webhook-Secret' => str_repeat('w', 32)];
        $this->postJson('/api/v1/webhooks/evolution', $payload, $headers)->assertAccepted();
        $acceptedAt = ConversationHead::sole()->last_accepted_at;
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/webhooks/evolution', $payload, $headers)->assertAccepted();
        $this->assertTrue($acceptedAt->equalTo(ConversationHead::sole()->last_accepted_at));
        $this->assertDatabaseCount('inbound_messages', 1);
        $this->assertDatabaseCount('conversations', 1);
        Queue::assertPushed(PrepareExecution::class, 1);
    }

    public static function ignoredPayloads(): array
    {
        return [
            'other instance' => ['instance', 'other'],
            'other event' => ['event', 'messages.update'],
            'bot response' => ['data.message.conversation', "🤖 Codex:\nResposta"],
            'other sender' => ['data.key.remoteJid', '5511222222222@s.whatsapp.net'],
            'unmapped lid' => ['data.key.remoteJid', '123@lid'],
            'group' => ['data.key.remoteJid', '123@g.us'],
            'broadcast' => ['data.key.remoteJid', 'status@broadcast'],
            'attachment' => ['data.messageType', 'imageMessage'],
            'empty' => ['data.message.conversation', '  '],
        ];
    }

    #[DataProvider('ignoredPayloads')]
    public function test_ignored_payloads_create_no_state_or_response(string $path, mixed $value): void
    {
        $payload = $this->payload();
        data_set($payload, $path, $value);
        $this->postJson('/api/v1/webhooks/evolution', $payload, ['X-Webhook-Secret' => str_repeat('w', 32)])
            ->assertAccepted()->assertJsonPath('status', 'ignored');
        $this->assertDatabaseCount('conversation_heads', 0);
        $this->assertDatabaseCount('outbound_parts', 0);
        Queue::assertNothingPushed();
    }

    public function test_trusted_lid_mapping_survives_validation(): void
    {
        $payload = $this->payload();
        $payload['data']['key']['remoteJid'] = '123@lid';
        $payload['data']['key']['remoteJidAlt'] = '5511999990000@s.whatsapp.net';
        $this->postJson('/api/v1/webhooks/evolution', $payload, ['X-Webhook-Secret' => str_repeat('w', 32)])
            ->assertAccepted()->assertJsonPath('status', 'accepted');
        $this->assertDatabaseCount('inbound_messages', 1);
    }

    public function test_concurrent_webhook_receipts_persist_one_message(): void
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
                $response = $this->postJson('/api/v1/webhooks/evolution', $this->payload(), ['X-Webhook-Secret' => str_repeat('w', 32)]);
                exit($response->status() === 202 ? 0 : 1);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        DB::purge();
        $this->assertDatabaseCount('inbound_messages', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertSame(1, ConversationHead::sole()->next_order);
    }

    public function test_oversized_prompt_creates_one_notice_without_storing_prompt_or_activity(): void
    {
        $payload = $this->payload();
        $headers = ['X-Webhook-Secret' => str_repeat('w', 32)];
        $this->postJson('/api/v1/webhooks/evolution', $payload, $headers)->assertAccepted();
        $acceptedAt = ConversationHead::sole()->last_accepted_at;
        $this->travel(11)->minutes();
        $payload['data']['key']['id'] = 'TOO-LONG';
        $payload['data']['message']['conversation'] = str_repeat('😀', 4097);
        $this->postJson('/api/v1/webhooks/evolution', $payload, $headers)
            ->assertAccepted()->assertJsonPath('reason', 'prompt_too_large');
        $this->postJson('/api/v1/webhooks/evolution', $payload, $headers)->assertAccepted();
        $this->assertTrue($acceptedAt->equalTo(ConversationHead::sole()->last_accepted_at));
        $this->assertDatabaseCount('inbound_messages', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('outbound_parts', 1);
        $this->assertSame('Mensagem muito longa. Envie um texto menor para continuar.', OutboundPart::sole()->text);
        Queue::assertPushed(PrepareExecution::class, 1);
    }
}
