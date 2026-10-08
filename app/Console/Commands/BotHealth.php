<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class BotHealth extends Command
{
    protected $signature = 'bot:health {component : worker ou scheduler}';

    protected $description = 'Verifica heartbeat de operação sem exibir dados sensíveis';

    public function handle(): int
    {
        $component = $this->argument('component');
        if (! in_array($component, ['worker', 'scheduler'], true)) {
            return self::FAILURE;
        }
        $heartbeat = Cache::get('bot:heartbeat:'.$component);

        $validTimestamp = is_int($heartbeat) || (is_string($heartbeat) && ctype_digit($heartbeat));

        return $validTimestamp && time() - (int) $heartbeat < 120 ? self::SUCCESS : self::FAILURE;
    }
}
