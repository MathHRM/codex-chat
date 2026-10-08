<?php

namespace App\Services;

use App\Models\ConversationHead;
use App\Models\InboundMessage;
use App\Models\OutboundPart;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class DeliverResponses
{
    public function __construct(private readonly EvolutionContract $evolution) {}

    public function tick(): void
    {
        $deadline = microtime(true) + 20;
        ConversationHead::orderBy('id')->chunkById(100, function ($heads) use ($deadline): bool {
            foreach ($heads as $head) {
                if (microtime(true) >= $deadline) {
                    return false;
                }
                $this->deliver($head);
            }

            return true;
        });
    }

    private function deliver(ConversationHead $head): void
    {
        $lock = Cache::lock('bot:delivery:'.$head->id, 60);
        if (! $lock->get()) {
            return;
        }
        try {
            $part = DB::transaction(function () use ($head): ?OutboundPart {
                $part = OutboundPart::where('conversation_head_id', $head->id)->where('status', '!=', 'sent')
                    ->orderBy('local_order')->orderBy('part_index')->lockForUpdate()->first();
                if ($part === null) {
                    return null;
                }
                if ($part->status === 'sending') {
                    $part->update(['status' => 'uncertain', 'error_code' => 'sender_interrupted']);
                }
                if ($part->status !== 'pending' || ($part->retry_at !== null && $part->retry_at->isFuture())
                    || InboundMessage::where('conversation_head_id', $head->id)->where('local_order', '<', $part->local_order)->whereIn('status', ['pending', 'running'])->exists()) {
                    return null;
                }
                $part->update(['status' => 'sending', 'attempts' => $part->attempts + 1]);

                return $part;
            }, attempts: 5);
            if ($part === null) {
                return;
            }
            try {
                $response = $this->evolution->sendTextTo($part->text, $head->instance, $head->number);
                $externalId = $response->json('key.id');
                if ($response->successful() && is_string($externalId) && $externalId !== '') {
                    $changes = ['status' => 'sent', 'evolution_id' => $externalId, 'sent_at' => now('UTC'), 'error_code' => null, 'retry_at' => null];
                } elseif (in_array($response->status(), [400, 401, 403, 404, 422, 429], true)) {
                    $changes = ['status' => $part->attempts >= (int) config('bot.delivery_max_attempts') ? 'failed' : 'pending', 'error_code' => 'evolution_rejected', 'retry_at' => now('UTC')->addSeconds(5 * (2 ** ($part->attempts - 1)))];
                } else {
                    $changes = ['status' => 'uncertain', 'error_code' => 'evolution_ambiguous'];
                }
            } catch (ConnectionException) {
                $changes = ['status' => 'uncertain', 'error_code' => 'evolution_timeout'];
            } catch (InvalidArgumentException) {
                $changes = ['status' => 'failed', 'error_code' => 'unauthorized_destination'];
            }
            $part->update($changes);
            Log::info('bot.delivery_finished', ['part_id' => $part->id, 'execution_id' => $part->execution_id, 'status' => $part->status, 'error_code' => $part->error_code]);
        } finally {
            $lock->release();
        }
    }
}
