<?php

namespace App\Console\Commands;

use App\Jobs\ProcessBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class BotTick extends Command
{
    protected $signature = 'bot:tick';

    protected $description = 'Despacha reconciliação do runner e entrega durável';

    public function handle(): int
    {
        Cache::put('bot:heartbeat:scheduler', time(), 120);
        ProcessBot::dispatch();

        return self::SUCCESS;
    }
}
