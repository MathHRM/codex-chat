<?php

namespace App\Services;

use App\Jobs\PrepareExecution;
use App\Models\Execution;
use App\Models\InboundMessage;
use App\Support\BotConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ProcessExecutions
{
    public function __construct(private readonly RunnerClient $runner, private readonly StoreResponse $responses, private readonly BotConfig $config) {}

    public function tick(): void
    {
        $tickLock = Cache::lock('bot:execution-tick', 60);
        if (! $tickLock->get()) {
            return;
        }
        try {
            $statuses = Cache::get('bot:paused', false) ? ['running'] : ['pending', 'running'];
            $message = InboundMessage::whereIn('status', $statuses)->orderBy('id')->first();
            if ($message === null) {
                return;
            }
            (new PrepareExecution($message->id))->handle();
            $execution = $message->execution()->firstOrFail();
            $lease = Cache::lock('bot:workspace', $this->config->leaseSeconds, $execution->id);
            if (! $lease->refresh() && ! $lease->get()) {
                return;
            }
            $execution = DB::transaction(function () use ($execution): Execution {
                $claimed = Execution::whereKey($execution->id)->lockForUpdate()->firstOrFail();
                if ($claimed->status === 'pending') {
                    $claimed->update(['status' => 'running', 'session_id' => $claimed->message->conversation->session_id, 'started_at' => now('UTC')]);
                    $claimed->message->update(['status' => 'running']);
                }

                return $claimed;
            }, attempts: 5);
            $result = $this->runner->reconcile($execution);
            if ($result === null || in_array($result['status'], ['pending', 'running'], true)) {
                return;
            }
            DB::transaction(function () use ($execution, $result): void {
                $finished = Execution::whereKey($execution->id)->lockForUpdate()->firstOrFail();
                if ($finished->status !== 'running') {
                    return;
                }
                $successful = $result['status'] === 'succeeded';
                $error = $successful ? null : $this->safeError($result['error'] ?? 'agent_error');
                $text = $successful ? $result['answer'] : $this->responses->notice($error);
                $finished->update(['status' => $result['status'], 'error_code' => $error, 'final_response' => $successful ? $text : null, 'finished_at' => now('UTC')]);
                $finished->message->update(['status' => $result['status']]);
                $finished->message->conversation->update(['session_id' => $successful ? $result['result_session_id'] : null]);
                $this->responses->store($finished, $text);
                Log::info('bot.execution_finished', ['execution_id' => $finished->id, 'runner_id' => $finished->runner_id, 'status' => $finished->status, 'error_code' => $error]);
            }, attempts: 5);
            $lease->release();
        } finally {
            $tickLock->release();
        }
    }

    private function safeError(mixed $error): string
    {
        return in_array($error, ['authentication', 'usage_limit', 'timeout', 'invalid_session', 'runner_interrupted', 'runner_conflict', 'invalid_result', 'instructions_missing', 'spawn_failed', 'output_limit'], true) ? $error : 'agent_error';
    }
}
