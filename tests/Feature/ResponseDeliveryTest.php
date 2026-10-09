<?php

namespace Tests\Feature;

use App\Models\Execution;
use App\Models\OutboundPart;
use App\Services\AcceptMessage;
use App\Services\DeliverResponses;
use App\Services\RejectOversizedMessage;
use App\Services\StoreResponse;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResponseDeliveryTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bot.owner_number' => '5511999990000', 'bot.instance' => 'owner-bot',
            'bot.webhook_secret' => str_repeat('w', 32), 'bot.runner_token' => str_repeat('r', 32),
            'bot.evolution_key' => str_repeat('e', 32), 'bot.response_part_chars' => 4]);
        Http::preventStrayRequests();
    }

    private function response(string $id, string $text): Execution
    {
        $message = app(AcceptMessage::class)->accept(['external_id' => $id, 'instance' => 'owner-bot', 'number' => '5511999990000', 'text' => 'Tarefa']);
        $message->update(['status' => 'succeeded']);
        $execution = $message->execution;
        $execution->update(['status' => 'succeeded', 'final_response' => $text]);
        app(StoreResponse::class)->store($execution, $text);

        return $execution;
    }

    public function test_unicode_parts_preserve_integral_text_and_only_final_response_is_sent_in_order(): void
    {
        $text = "Olá😀\nação🚀🧑🏽‍💻 fim";
        $execution = $this->response('first', $text);
        app(StoreResponse::class)->store($execution, $text);
        $parts = $execution->parts()->orderBy('part_index')->get();
        $this->assertSame($text, $parts->pluck('text')->implode(''));
        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(4, mb_strlen($part->text));
            $this->assertTrue(mb_check_encoding($part->text, 'UTF-8'));
        }
        $sent = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$sent) {
            $this->assertStringStartsWith("🤖 Codex:\n", $request['text']);
            $sent[] = mb_substr($request['text'], mb_strlen("🤖 Codex:\n"));
            $this->assertSame('http://evolution:8080/message/sendText/owner-bot', $request->url());
            $this->assertSame('5511999990000', $request['number']);

            return Http::response(['key' => ['id' => 'external-'.count($sent)]], 201);
        });
        foreach ($parts as $part) {
            app(DeliverResponses::class)->tick();
        }
        $this->assertSame($text, implode('', $sent));
        $this->assertSame($parts->count(), OutboundPart::where('status', 'sent')->count());
    }

    public function test_definite_rejections_have_backoff_and_bounded_retries_without_agent_replay(): void
    {
        $execution = $this->response('first', 'ok');
        $later = $this->response('later', 'fim');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['message' => 'rejected'], 429)]);
        app(DeliverResponses::class)->tick();
        app(DeliverResponses::class)->tick();
        Http::assertSentCount(1);
        $part = $execution->parts()->sole();
        $this->assertSame('pending', $part->status);
        $this->travel(5)->seconds();
        app(DeliverResponses::class)->tick();
        $this->travel(10)->seconds();
        app(DeliverResponses::class)->tick();
        app(DeliverResponses::class)->tick();
        Http::assertSentCount(3);
        $this->assertSame('failed', $part->fresh()->status);
        $this->assertSame(0, $later->parts()->sole()->attempts);
        $this->assertSame('succeeded', $execution->fresh()->status);
    }

    public function test_ambiguous_timeout_blocks_parts_until_explicit_mark_sent_or_retry(): void
    {
        $execution = $this->response('first', 'abcdefgh');
        $this->response('later', 'fim');
        $part = $execution->parts()->orderBy('part_index')->first();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('accepted externally but response lost'));
        app(DeliverResponses::class)->tick();
        app(DeliverResponses::class)->tick();
        $this->assertSame('uncertain', $part->fresh()->status);
        $this->assertSame(1, OutboundPart::sum('attempts'));
        $this->artisan('bot:delivery')->expectsOutputToContain($part->id)->assertSuccessful();
        $this->artisan('bot:delivery', ['part' => $part->id, '--sent' => 'confirmed-external'])->assertSuccessful();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['key' => ['id' => 'next']], 201)]);
        app(DeliverResponses::class)->tick();
        $this->assertSame(2, OutboundPart::where('status', 'sent')->count());
        $last = OutboundPart::where('status', 'pending')->sole();
        $last->update(['status' => 'uncertain']);
        $this->artisan('bot:delivery', ['part' => $last->id, '--retry' => true])->assertSuccessful();
        app(DeliverResponses::class)->tick();
        $this->assertSame('sent', $last->fresh()->status);
        $this->assertSame(2, Execution::count());
    }

    public function test_crashed_sender_does_not_automatically_resend(): void
    {
        $execution = $this->response('first', 'ok');
        $part = $execution->parts()->sole();
        $part->update(['status' => 'sending', 'attempts' => 1]);
        app(DeliverResponses::class)->tick();
        $this->assertSame('uncertain', $part->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_earlier_unfinished_message_blocks_later_notice_and_oversized_notice_does_not_renew_activity(): void
    {
        $message = app(AcceptMessage::class)->accept(['external_id' => 'earlier', 'instance' => 'owner-bot', 'number' => '5511999990000', 'text' => 'Tarefa']);
        $acceptedAt = $message->head->last_accepted_at;
        app(RejectOversizedMessage::class)->reject(['external_id' => 'oversized', 'instance' => 'owner-bot', 'number' => '5511999990000', 'text' => 'large']);
        app(DeliverResponses::class)->tick();
        Http::assertNothingSent();
        $message->update(['status' => 'failed']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['key' => ['id' => 'notice']], 201)]);
        app(DeliverResponses::class)->tick();
        $this->assertSame('sent', OutboundPart::sole()->status);
        $this->assertTrue($acceptedAt->equalTo($message->head->fresh()->last_accepted_at));
    }

    public function test_stored_destination_mismatch_is_rejected_without_external_request(): void
    {
        $execution = $this->response('first', 'ok');
        $execution->message->head->update(['number' => '5511222222222']);
        app(DeliverResponses::class)->tick();
        $this->assertSame('unauthorized_destination', $execution->parts()->sole()->error_code);
        Http::assertNothingSent();
    }

    public function test_success_without_external_id_and_server_error_are_uncertain(): void
    {
        $execution = $this->response('first', 'ok');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['internal' => 'details'], 200)]);
        app(DeliverResponses::class)->tick();
        $this->assertSame('uncertain', $execution->parts()->sole()->status);
        $part = $execution->parts()->sole();
        $this->artisan('bot:delivery', ['part' => $part->id, '--retry' => true])->assertSuccessful();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 500)]);
        app(DeliverResponses::class)->tick();
        $this->assertSame('uncertain', $part->fresh()->status);
    }
}
