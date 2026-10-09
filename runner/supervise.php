<?php

declare(strict_types=1);
use BotRunner\CodexProcess;
use BotRunner\RunStore;
use BotRunner\Supervisor;

require_once __DIR__.'/RunStore.php';
require_once __DIR__.'/Supervisor.php';
require_once __DIR__.'/CodexProcess.php';

$store = new RunStore('/state/runs');
$supervisor = new Supervisor($store, '/state/supervisor.lock');
$executor = new CodexProcess($store, '/workspace', '/state', (int) (getenv('BOT_TIMEOUT_SECONDS') ?: 300), model: (string) (getenv('CODEX_MODEL') ?: 'gpt-6-luna'), reasoningEffort: (string) (getenv('CODEX_REASONING_EFFORT') ?: 'low'));
$stopping = false;
pcntl_async_signals(true);
$stop = static function () use (&$stopping): void {
    $stopping = true;
};
pcntl_signal(SIGTERM, $stop);
pcntl_signal(SIGINT, $stop);
while (! $stopping) {
    if (! $supervisor->tick($executor->execute(...))) {
        usleep(200000);
    }
}
