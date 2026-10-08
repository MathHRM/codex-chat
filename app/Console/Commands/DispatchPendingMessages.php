<?php

namespace App\Console\Commands;

use App\Jobs\PrepareExecution;
use App\Models\InboundMessage;
use Illuminate\Console\Command;

class DispatchPendingMessages extends Command
{
    protected $signature = 'bot:dispatch-pending';

    protected $description = 'Recover durable pending messages whose queue dispatch may have been lost';

    public function handle(): int
    {
        InboundMessage::where('status', 'pending')->orderBy('id')->chunkById(100, function ($messages): void {
            foreach ($messages as $message) {
                PrepareExecution::dispatch($message->id)->afterCommit();
            }
        });

        return self::SUCCESS;
    }
}
