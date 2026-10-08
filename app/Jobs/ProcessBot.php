<?php

namespace App\Jobs;

use App\Services\DeliverResponses;
use App\Services\ProcessExecutions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ProcessBot implements ShouldQueue
{
    use Queueable;

    public int $timeout = 55;

    public function handle(ProcessExecutions $executions, DeliverResponses $delivery): void
    {
        Cache::put('bot:heartbeat:worker', time(), 120);
        $executions->tick();
        $delivery->tick();
    }
}
