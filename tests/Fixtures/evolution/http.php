<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
$directory = '/evolution/instances';
if ($path === '/_test/mode' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    file_put_contents($directory.'/mode', $input['mode']);
    echo '{}';

    return;
}
if ($path === '/_test/status') {
    $records = is_file($directory.'/sent.jsonl') ? file($directory.'/sent.jsonl', FILE_IGNORE_NEW_LINES) : [];
    echo json_encode(['sent' => array_map(fn ($line) => json_decode($line, true), $records)]);

    return;
}
if ($path === '/instance/connectionState/owner-bot') {
    echo '{"instance":{"state":"open"}}';

    return;
}
if ($path === '/') {
    echo '{}';

    return;
}
if ($path !== '/message/sendText/owner-bot' || ($_SERVER['HTTP_APIKEY'] ?? '') !== getenv('AUTHENTICATION_API_KEY')) {
    http_response_code(401);
    echo '{}';

    return;
}
$input = json_decode(file_get_contents('php://input'), true);
if (($input['number'] ?? '') !== '5511999990000') {
    http_response_code(403);
    echo '{}';

    return;
}
$mode = is_file($directory.'/mode') ? trim(file_get_contents($directory.'/mode')) : 'success';
if ($mode === 'reject') {
    http_response_code(429);
    echo '{}';

    return;
}
$id = 'fixture-'.bin2hex(random_bytes(8));
file_put_contents($directory.'/sent.jsonl', json_encode(['id' => $id, 'number' => $input['number'], 'text' => $input['text']])."\n", FILE_APPEND | LOCK_EX);
if ($mode === 'timeout') {
    sleep(20);
}
echo json_encode(['key' => ['id' => $id]]);
