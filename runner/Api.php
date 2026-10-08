<?php

declare(strict_types=1);

namespace BotRunner;

use JsonException;

final class Api
{
    public function __construct(private readonly RunStore $store, private readonly string $token, private readonly int $promptMaxBytes) {}

    /** @return array{status: int, body: array<string, mixed>} */
    public function handle(string $method, string $path, string $authorization, string $body): array
    {
        if ($method === 'GET' && $path === '/health') {
            return $this->response(200, ['service' => 'codex-runner', 'status' => 'ok']);
        }
        if ($this->token === '' || ! hash_equals('Bearer '.$this->token, $authorization)) {
            return $this->response(401, ['error' => 'unauthorized']);
        }
        if ($method === 'GET' && preg_match('#\A/runs/([^/]+)\z#', $path, $matches) === 1) {
            if (! RunStore::validId($matches[1])) {
                return $this->response(422, ['error' => 'invalid_id']);
            }
            $run = $this->store->find($matches[1]);

            return $run === null ? $this->response(404, ['error' => 'not_found']) : $this->response(200, RunStore::publicResult($run));
        }
        if ($method !== 'POST' || $path !== '/runs') {
            return $this->response(404, ['error' => 'not_found']);
        }
        if (strlen($body) > $this->promptMaxBytes * 6 + 1024) {
            return $this->response(413, ['error' => 'prompt_too_large']);
        }
        try {
            $input = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->response(422, ['error' => 'invalid_request']);
        }
        if (! is_array($input) || array_diff(array_keys($input), ['id', 'prompt', 'session_id']) !== []
            || ! is_string($input['id'] ?? null) || ! RunStore::validId($input['id'])
            || ! is_string($input['prompt'] ?? null) || trim($input['prompt']) === ''
            || (isset($input['session_id']) && (! is_string($input['session_id']) || ! RunStore::validId($input['session_id'])))) {
            return $this->response(422, ['error' => 'invalid_request']);
        }
        if (strlen($input['prompt']) > $this->promptMaxBytes) {
            return $this->response(413, ['error' => 'prompt_too_large']);
        }

        return $this->store->accept($input['id'], $input['prompt'], $input['session_id'] ?? null);
    }

    /** @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>}
     */
    private function response(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
