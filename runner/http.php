<?php

declare(strict_types=1);
use BotRunner\Api;
use BotRunner\RunStore;

require_once __DIR__.'/RunStore.php';
require_once __DIR__.'/Api.php';

header('Content-Type: application/json');
try {
    $api = new Api(new RunStore('/state/runs'), (string) getenv('RUNNER_TOKEN'), (int) (getenv('BOT_PROMPT_MAX_BYTES') ?: 16384));
    $limit = (int) (getenv('BOT_PROMPT_MAX_BYTES') ?: 16384) * 6 + 1025;
    $result = $api->handle($_SERVER['REQUEST_METHOD'], (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $_SERVER['HTTP_AUTHORIZATION'] ?? '', (string) file_get_contents('php://input', false, null, 0, $limit));
} catch (Throwable) {
    $result = ['status' => 503, 'body' => ['error' => 'state_unavailable']];
}
http_response_code($result['status']);
echo json_encode($result['body'], JSON_THROW_ON_ERROR);
