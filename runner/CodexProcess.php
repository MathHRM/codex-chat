<?php

declare(strict_types=1);

namespace BotRunner;

final class CodexProcess
{
    public function __construct(private readonly RunStore $store, private readonly string $workspace, private readonly string $state, private readonly int $timeout, private readonly string $binary = 'codex', private readonly string $model = 'gpt-6-luna', private readonly string $reasoningEffort = 'low') {}

    /** @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    public function execute(array $run): array
    {
        if (! is_file($this->workspace.'/AGENTS.md') || trim((string) file_get_contents($this->workspace.'/AGENTS.md')) === '') {
            return ['status' => 'failed', 'error' => 'instructions_missing'];
        }
        $output = $this->state.'/'.$run['id'].'.answer';
        if (is_file($output)) {
            unlink($output);
        }
        $arguments = ['setsid', $this->binary, 'exec', '-c', 'model='.json_encode($this->model, JSON_THROW_ON_ERROR), '-c', 'model_reasoning_effort='.json_encode($this->reasoningEffort, JSON_THROW_ON_ERROR), '-c', 'approval_policy="never"', '-c', 'sandbox_mode="workspace-write"', '-c', 'sandbox_workspace_write.network_access=false', '--cd', $this->workspace];
        if ($run['session_id'] !== null) {
            $arguments[] = 'resume';
        }
        array_push($arguments, '--json', '--output-last-message', $output);
        if ($run['session_id'] !== null) {
            $arguments[] = $run['session_id'];
        }
        $arguments[] = '-';
        $environment = ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME'), 'CODEX_HOME' => (string) getenv('CODEX_HOME'), 'LANG' => 'C.UTF-8', 'BOT_RUN_ID' => $run['id']];
        $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->workspace, $environment);
        if ($process === false) {
            return ['status' => 'failed', 'error' => 'spawn_failed'];
        }
        $pid = proc_get_status($process)['pid'];
        $this->store->update($run['id'], ['process_id' => $pid]);
        fwrite($pipes[0], $run['prompt']);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $started = microtime(true);
        $buffer = '';
        $stderr = '';
        $eventError = '';
        $session = null;
        $completed = false;
        $error = null;
        $exitCode = -1;
        $exited = false;
        while (true) {
            $buffer .= stream_get_contents($pipes[1]);
            $stderr = substr($stderr.stream_get_contents($pipes[2]), -16384);
            while (($newline = strpos($buffer, "\n")) !== false) {
                $event = json_decode(substr($buffer, 0, $newline), true);
                $buffer = substr($buffer, $newline + 1);
                if (($event['type'] ?? '') === 'thread.started' && RunStore::validId($event['thread_id'] ?? '')) {
                    $session = $event['thread_id'];
                }
                if (($event['type'] ?? '') === 'turn.completed') {
                    $completed = true;
                }
                if (in_array($event['type'] ?? '', ['turn.failed', 'error'], true)) {
                    $error = 'agent_error';
                    $eventError = substr(json_encode($event), -16384);
                }
            }
            if (strlen($buffer) > 1048576) {
                $error = 'output_limit';
            }
            if ($exited) {
                break;
            }
            $status = proc_get_status($process);
            if (! $status['running']) {
                $exitCode = $status['exitcode'];
                $exited = true;

                continue;
            }
            if (microtime(true) - $started >= $this->timeout || $error === 'output_limit') {
                $error ??= 'timeout';
                break;
            }
            usleep(20000);
        }
        posix_kill(-$pid, SIGTERM);
        usleep(100000);
        posix_kill(-$pid, SIGKILL);
        foreach ([1, 2] as $index) {
            fclose($pipes[$index]);
        }
        proc_close($process);
        if ($error === 'agent_error' || ($error === null && $exitCode !== 0)) {
            $diagnostic = $stderr.$eventError;
            $error = match (true) {
                preg_match('/not logged in|unauthorized|authentication|401/i', $diagnostic) === 1 => 'authentication',
                preg_match('/rate.limit|quota|usage.limit|429/i', $diagnostic) === 1 => 'usage_limit',
                $run['session_id'] !== null && preg_match('/session.*(not found|invalid)|no session|thread.*not found/i', $diagnostic) === 1 => 'invalid_session',
                default => 'agent_error',
            };
        }
        if ($error !== null || ! $completed || $session === null || ! is_file($output)) {
            return ['status' => 'failed', 'error' => $error ?? 'invalid_result'];
        }
        $answer = file_get_contents($output);
        if ($answer === false || trim($answer) === '' || preg_match('//u', $answer) !== 1) {
            return ['status' => 'failed', 'error' => 'invalid_result'];
        }

        return ['status' => 'succeeded', 'result_session_id' => $session, 'answer' => $answer];
    }
}
