<?php

namespace Tests\Feature;

use App\Services\EvolutionContract;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EvolutionContractTest extends TestCase
{
    private function adapter(): EvolutionContract
    {
        return new EvolutionContract('http://evolution:8080', 'fixture-key', 'owner-bot', '5511999990000');
    }

    private function payload(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/evolution/private-text.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_private_text_and_trusted_lid_mapping_are_extracted(): void
    {
        $payload = $this->payload();
        $expected = ['external_id' => 'EXAMPLE001', 'instance' => 'owner-bot', 'number' => '5511999990000', 'text' => 'Crie um arquivo exemplo.txt'];
        $this->assertSame($expected, $this->adapter()->extract($payload));
        $payload['data']['key']['remoteJid'] = '987654321@lid';
        $payload['data']['key']['remoteJidAlt'] = '5511999990000@s.whatsapp.net';
        $this->assertSame($expected, $this->adapter()->extract($payload));
        $payload['data']['messageType'] = 'extendedTextMessage';
        $payload['data']['message'] = ['extendedTextMessage' => ['text' => 'Continuação 😀']];
        $this->assertSame('Continuação 😀', $this->adapter()->extract($payload)['text']);
    }

    public static function ignoredMessages(): array
    {
        return [
            'other instance' => ['instance', 'other'],
            'other event' => ['event', 'messages.update'],
            'bot response' => ['data.message.conversation', "🤖 Codex:\nResposta"],
            'bot response with leading whitespace' => ['data.message.conversation', "  🤖 Codex:\nResposta"],
            'invalid sender direction' => ['data.key.fromMe', 'true'],
            'missing sender direction' => ['data.key.fromMe', null],
            'other number' => ['data.key.remoteJid', '5511222222222@s.whatsapp.net'],
            'unmapped lid' => ['data.key.remoteJid', '987654321@lid'],
            'group' => ['data.key.remoteJid', '5511999990000@g.us'],
            'status' => ['data.key.remoteJid', 'status@broadcast'],
            'image caption' => ['data.messageType', 'imageMessage'],
            'audio' => ['data.messageType', 'audioMessage'],
            'empty text' => ['data.message.conversation', '  '],
            'missing id' => ['data.key.id', null],
            'malformed key' => ['data.key', 'invalid'],
            'malformed data' => ['data', null],
        ];
    }

    #[DataProvider('ignoredMessages')]
    public function test_untrusted_or_unsupported_messages_are_ignored(string $path, mixed $value): void
    {
        $payload = $this->payload();
        data_set($payload, $path, $value);
        $this->assertNull($this->adapter()->extract($payload));
    }

    public function test_send_uses_configured_destination_key_and_release_contract(): void
    {
        Http::preventStrayRequests();
        Http::fake(['http://evolution:8080/message/sendText/owner-bot' => Http::response([
            'key' => ['id' => 'OUT001', 'fromMe' => true], 'status' => 'PENDING',
        ], 201)]);
        $payload = $this->payload();
        $payload['server_url'] = 'http://attacker.invalid';
        $payload['destination'] = 'http://attacker.invalid';
        $payload['sender'] = 'attacker';
        $this->assertNotNull($this->adapter()->extract($payload));
        $response = $this->adapter()->sendText('Resposta 😀');
        $this->assertSame(201, $response->status());
        $this->assertSame('OUT001', $response->json('key.id'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://evolution:8080/message/sendText/owner-bot'
            && $request->hasHeader('apikey', 'fixture-key')
            && $request->data() === ['number' => '5511999990000', 'text' => "🤖 Codex:\nResposta 😀", 'linkPreview' => false]);
        Http::assertSentCount(1);
    }
}
