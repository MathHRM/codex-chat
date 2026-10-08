<?php

declare(strict_types=1);

namespace BotRunner;

use Closure;
use RuntimeException;

final class Supervisor
{
    /** @var resource */
    private mixed $lock;

    public function __construct(private readonly RunStore $store, string $lockPath)
    {
        $this->lock = fopen($lockPath, 'c');
        if ($this->lock === false || ! flock($this->lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('supervisor_already_running');
        }
        foreach ($store->all() as $run) {
            if ($run['status'] === 'running') {
                if (isset($run['process_id']) && function_exists('posix_kill')
                    && str_contains((string) @file_get_contents('/proc/'.$run['process_id'].'/environ'), 'BOT_RUN_ID='.$run['id']."\0")) {
                    posix_kill(-$run['process_id'], SIGKILL);
                }
                $store->update($run['id'], ['status' => 'uncertain', 'error' => 'runner_interrupted', 'finished_at' => gmdate('c')]);
            }
        }
    }

    public function __destruct()
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
    }

    /** @param Closure(array<string, mixed>): array<string, mixed> $execute */
    public function tick(Closure $execute): bool
    {
        foreach ($this->store->all() as $run) {
            if ($run['status'] !== 'pending') {
                continue;
            }
            $this->store->update($run['id'], ['status' => 'running', 'started_at' => gmdate('c')]);
            $result = $execute($run);
            $this->store->update($run['id'], [...$result, 'finished_at' => gmdate('c')]);
            fwrite(STDERR, json_encode(['run_id' => $run['id'], 'status' => $result['status'], 'error' => $result['error'] ?? null]).PHP_EOL);

            return true;
        }

        return false;
    }
}
