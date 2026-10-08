<?php

namespace App\Jobs;

use App\Models\Execution;
use App\Models\InboundMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrepareExecution implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $messageId) {}

    public function handle(): void
    {
        DB::transaction(function (): void {
            $message = InboundMessage::whereKey($this->messageId)->lockForUpdate()->first();
            if ($message === null || $message->status !== 'pending') {
                return;
            }

            Execution::firstOrCreate(['inbound_message_id' => $message->id], ['runner_id' => (string) Str::uuid()]);
        }, attempts: 5);
    }
}
