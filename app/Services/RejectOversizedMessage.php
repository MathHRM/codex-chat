<?php

namespace App\Services;

use App\Models\ConversationHead;
use App\Models\InboundMessage;
use App\Models\OutboundPart;
use Illuminate\Support\Facades\DB;

final class RejectOversizedMessage
{
    /** @param array{external_id: string, instance: string, number: string, text: string} $input */
    public function reject(array $input): void
    {
        DB::transaction(function () use ($input): void {
            ConversationHead::query()->insertOrIgnore(['instance' => $input['instance'], 'number' => $input['number']]);
            $head = ConversationHead::where('instance', $input['instance'])->where('number', $input['number'])->lockForUpdate()->firstOrFail();
            $sourceKey = 'oversize:'.hash('sha256', $input['instance'].':'.$input['external_id']);
            if (InboundMessage::where('instance', $input['instance'])->where('external_id', $input['external_id'])->exists()
                || OutboundPart::where('source_key', $sourceKey)->exists()) {
                return;
            }
            $order = $head->next_order + 1;
            OutboundPart::create([
                'conversation_head_id' => $head->id, 'source_key' => $sourceKey,
                'local_order' => $order, 'part_index' => 0,
                'text' => 'Mensagem muito longa. Envie um texto menor para continuar.',
            ]);
            $head->update(['next_order' => $order]);
        }, attempts: 5);
    }
}
