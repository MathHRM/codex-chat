<?php

declare(strict_types=1);

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'GET' && parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/health') {
    echo json_encode(['service' => 'codex-runner', 'status' => 'ok'], JSON_THROW_ON_ERROR);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'not_found'], JSON_THROW_ON_ERROR);
}
