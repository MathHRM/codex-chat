<?php

// Este helper usa somente PHP padrão para funcionar antes do build da aplicação.
$path = $argv[1] ?? '.env';
$owner = $argv[2] ?? '';
$hasVolumes = ($argv[3] ?? '0') === '1';

try {
    if (is_link($path)) {
        throw new RuntimeException('O arquivo de ambiente não pode ser um link simbólico.');
    }
    $text = is_file($path) ? file_get_contents($path) : file_get_contents(__DIR__.'/../.env.example');
    if ($text === false) {
        throw new RuntimeException('Não foi possível ler o arquivo de ambiente.');
    }
    $value = static function (string $key) use (&$text): string {
        preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $text, $match);

        return trim(trim($match[1] ?? ''), "\"'");
    };
    $set = static function (string $key, string $replacement) use (&$text): void {
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        $text = preg_match($pattern, $text)
            ? preg_replace_callback($pattern, static fn () => $key.'='.$replacement, $text)
            : rtrim($text)."\n".$key.'='.$replacement."\n";
    };
    $owner = $owner !== '' ? $owner : $value('BOT_OWNER_NUMBER');
    if (! preg_match('/^[1-9][0-9]{6,14}$/D', $owner)) {
        throw new RuntimeException('Informe --owner com país e DDD, somente dígitos (7 a 15).');
    }
    $keys = ['APP_KEY', 'POSTGRES_PASSWORD', 'APP_DB_PASSWORD', 'EVOLUTION_DB_PASSWORD', 'REDIS_PASSWORD', 'EVOLUTION_API_KEY', 'WEBHOOK_SECRET', 'RUNNER_TOKEN'];
    foreach ($keys as $key) {
        if ($value($key) !== '') {
            continue;
        }
        if ($hasVolumes) {
            throw new RuntimeException('Há volumes existentes e segredos ausentes. Restaure o .env correspondente antes de continuar.');
        }
        $set($key, $key === 'APP_KEY' ? 'base64:'.base64_encode(random_bytes(32)) : bin2hex(random_bytes(32)));
    }
    $set('BOT_OWNER_NUMBER', $owner);
    if ($value('BOT_INSTANCE') === '') {
        $set('BOT_INSTANCE', 'owner-bot');
    }
    umask(0077);
    $temporary = tempnam(dirname($path), '.setup-env-');
    if ($temporary === false) {
        throw new RuntimeException('Não foi possível criar o arquivo temporário de ambiente.');
    }
    try {
        if (file_put_contents($temporary, rtrim($text)."\n") === false || ! chmod($temporary, 0600) || ! rename($temporary, $path)) {
            throw new RuntimeException('Não foi possível salvar o arquivo de ambiente.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
    echo "Ambiente preparado; credenciais existentes preservadas.\n";
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
