<?php

namespace App\Services;

use App\Jobs\PrepareExecution;
use App\Models\Conversation;
use App\Models\ConversationHead;
use App\Models\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AcceptMessage
{
    /** @param array{external_id: string, instance: string, number: string, text: string} $input */
    public function accept(array $input): InboundMessage
    {
        return DB::transaction(function () use ($input): InboundMessage {
            ConversationHead::query()->insertOrIgnore([
                'instance' => $input['instance'], 'number' => $input['number'],
            ]);
            $head = ConversationHead::where('instance', $input['instance'])->where('number', $input['number'])
                ->lockForUpdate()->firstOrFail();
            $duplicate = InboundMessage::where('instance', $input['instance'])->where('external_id', $input['external_id'])->first();
            if ($duplicate !== null) {
                return $duplicate;
            }
            $now = CarbonImmutable::now('UTC');
            $conversation = $head->currentConversation;
            if ($conversation === null || $head->last_accepted_at === null || $now->greaterThanOrEqualTo($head->last_accepted_at->addSeconds(600))) {
                $conversation = Conversation::create([
                    'conversation_head_id' => $head->id,
                    'generation' => $conversation === null ? 1 : $conversation->generation + 1,
                    'started_at' => $now, 'last_accepted_at' => $now,
                ]);
            }
            $order = $head->next_order + 1;
            $message = InboundMessage::create([
                'conversation_head_id' => $head->id, 'conversation_id' => $conversation->id,
                'instance' => $input['instance'], 'external_id' => $input['external_id'],
                'text' => $input['text'], 'local_order' => $order, 'accepted_at' => $now,
            ]);
            $conversation->update(['last_accepted_at' => $now]);
            $head->update(['current_conversation_id' => $conversation->id, 'next_order' => $order, 'last_accepted_at' => $now]);

            DB::afterCommit(function () use ($message): void {
                try {
                    PrepareExecution::dispatch($message->id);
                } catch (Throwable) {
                    Log::warning('bot.dispatch_pending', ['message_id' => $message->id]);
                }
            });

            return $message;
        }, attempts: 5);
    }
}
