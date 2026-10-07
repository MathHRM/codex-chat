<?php

return [
    'owner_number' => env('BOT_OWNER_NUMBER', ''),
    'instance' => env('BOT_INSTANCE', ''),
    'webhook_secret' => env('WEBHOOK_SECRET', ''),
    'runner_token' => env('RUNNER_TOKEN', ''),
    'runner_url' => env('RUNNER_URL', 'http://codex-runner:8081'),
    'evolution_url' => env('EVOLUTION_URL', 'http://evolution:8080'),
    'evolution_key' => env('EVOLUTION_API_KEY', ''),
    'timeout_seconds' => env('BOT_TIMEOUT_SECONDS', 300),
    'prompt_max_bytes' => env('BOT_PROMPT_MAX_BYTES', 16384),
    'response_part_chars' => env('BOT_RESPONSE_PART_CHARS', 3000),
    'inactivity_seconds' => 600,
    'delivery_max_attempts' => 3,
];
