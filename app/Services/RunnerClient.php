<?php

namespace App\Services;

use App\Models\Execution;
use App\Support\BotConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class RunnerClient
{
    public function __construct(private readonly BotConfig $config) {}

    /** @return array<string, mixed>|null */
    public function reconcile(Execution $execution): ?array
    {
        try {
            $request = Http::withToken($this->config->runnerToken)->acceptJson()->timeout(10)->connectTimeout(3);
            $response = $request->get($this->config->runnerUrl.'/runs/'.$execution->runner_id);
            if ($response->status() === 404) {
                $response = $request->post($this->config->runnerUrl.'/runs', [
                    'id' => $execution->runner_id, 'prompt' => $execution->message->text, 'session_id' => $execution->session_id,
                ]);
            }
            if ($response->status() === 409) {
                return ['status' => 'uncertain', 'error' => 'runner_conflict'];
            }
            if (! $response->successful()) {
                return null;
            }
            $result = $response->json();
            if (! is_array($result) || ($result['id'] ?? null) !== $execution->runner_id
                || ! in_array($result['status'] ?? '', ['pending', 'running', 'succeeded', 'failed', 'uncertain'], true)) {
                return null;
            }
            if ($result['status'] === 'succeeded' && (! is_string($result['answer'] ?? null)
                || trim($result['answer']) === '' || ! mb_check_encoding($result['answer'], 'UTF-8')
                || ! Str::isUuid($result['result_session_id'] ?? ''))) {
                return ['status' => 'uncertain', 'error' => 'invalid_result'];
            }

            return $result;
        } catch (ConnectionException) {
            return null;
        }
    }
}
