<?php

declare(strict_types=1);

namespace BotRunner;

use RuntimeException;

final class RunStore
{
    public function __construct(private readonly string $directory)
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('state_unavailable');
        }
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $path = $this->path($id);
        if (! is_file($path)) {
            return null;
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public function accept(string $id, string $prompt, ?string $sessionId): array
    {
        $lock = fopen($this->directory.'/store.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('state_unavailable');
        }

        try {
            $existing = $this->find($id);
            if ($existing !== null) {
                if ($existing['prompt'] !== $prompt || $existing['session_id'] !== $sessionId) {
                    return ['status' => 409, 'body' => ['error' => 'run_conflict']];
                }

                return ['status' => 202, 'body' => self::publicResult($existing)];
            }

            $run = ['id' => $id, 'prompt' => $prompt, 'session_id' => $sessionId,
                'status' => 'pending', 'accepted_at' => gmdate('Y-m-d\TH:i:s\Z')];
            $run['sequence'] = max(array_column($this->all(), 'sequence') ?: [0]) + 1;
            $this->write($run);

            return ['status' => 202, 'body' => self::publicResult($run)];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $runs = [];
        foreach (glob($this->directory.'/*.json') as $path) {
            $runs[] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }
        usort($runs, fn (array $first, array $second): int => ($first['sequence'] ?? 0) <=> ($second['sequence'] ?? 0));

        return $runs;
    }

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): void
    {
        $lock = fopen($this->directory.'/store.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('state_unavailable');
        }
        try {
            $run = $this->find($id);
            if ($run === null) {
                throw new RuntimeException('run_not_found');
            }
            $this->write([...$run, ...$changes]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $run */
    private function write(array $run): void
    {
        $id = $run['id'];
        $temporary = tempnam($this->directory, '.write-');
        if ($temporary === false) {
            throw new RuntimeException('state_unavailable');
        }
        try {
            $file = fopen($temporary, 'wb');
            if ($file === false) {
                throw new RuntimeException('state_unavailable');
            }
            try {
                $json = json_encode($run, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                if (fwrite($file, $json) !== strlen($json) || ! fflush($file) || ! fsync($file)) {
                    throw new RuntimeException('state_unavailable');
                }
            } finally {
                fclose($file);
            }
            if (! rename($temporary, $this->path($id))) {
                throw new RuntimeException('state_unavailable');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

    }

    public static function validId(string $id): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $id) === 1;
    }

    /** @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    public static function publicResult(array $run): array
    {
        unset($run['prompt'], $run['process_id']);

        return $run;
    }

    private function path(string $id): string
    {
        if (! self::validId($id)) {
            throw new RuntimeException('invalid_id');
        }

        return $this->directory.'/'.strtolower($id).'.json';
    }
}
