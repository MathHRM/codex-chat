<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $mode = $argv[1] ?? 'configure';
    $instance = rawurlencode(config('bot.instance'));
    $client = Http::baseUrl(config('bot.evolution_url'))
        ->withHeaders(['apikey' => config('bot.evolution_key')])
        ->connectTimeout(5)->timeout(20);
    $request = static function (string $method, string $path, array $data = []) use ($client) {
        $response = $method === 'GET' ? $client->get($path) : $client->post($path, $data);
        if (! $response->successful()) {
            throw new RuntimeException('Evolution recusou '.$method.' '.$path.' (HTTP '.$response->status().').');
        }

        return $response;
    };
    if ($mode === 'state') {
        echo $request('GET', '/instance/connectionState/'.$instance)->json('instance.state')."\n";
    } elseif ($mode === 'configure') {
        $instances = $request('GET', '/instance/fetchInstances')->json();
        if (! is_array($instances)) {
            throw new RuntimeException('Evolution retornou uma lista de instâncias inválida.');
        }
        $exists = collect($instances)->contains(fn ($item) => ($item['name'] ?? null) === config('bot.instance'));
        if (! $exists) {
            $request('POST', '/instance/create', [
                'instanceName' => config('bot.instance'),
                'integration' => 'WHATSAPP-BAILEYS',
                'qrcode' => false,
            ]);
        }
        $webhook = [
            'enabled' => true,
            'url' => 'http://app:8000/api/v1/webhooks/evolution',
            'headers' => ['X-Webhook-Secret' => config('bot.webhook_secret')],
            'byEvents' => false,
            'base64' => false,
            'events' => ['MESSAGES_UPSERT'],
        ];
        $request('POST', '/webhook/set/'.$instance, ['webhook' => $webhook]);
        $configured = $request('GET', '/webhook/find/'.$instance)->json();
        if (($configured['enabled'] ?? null) !== true
            || ($configured['url'] ?? null) !== $webhook['url']
            || ($configured['headers']['X-Webhook-Secret'] ?? null) !== config('bot.webhook_secret')
            || ($configured['events'] ?? null) !== ['MESSAGES_UPSERT']
            || ($configured['webhookByEvents'] ?? null) !== false
            || ($configured['webhookBase64'] ?? null) !== false) {
            throw new RuntimeException('A configuração do webhook não corresponde ao esperado.');
        }
        echo "Instância Evolution e webhook configurados e verificados.\n";
    } elseif ($mode === 'qr' || $mode === 'qr-json') {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $payload = $request('GET', '/instance/connect/'.$instance)->json();
            $base64 = $payload['base64'] ?? null;
            if (is_string($base64) && str_starts_with($base64, 'data:image/png;base64,')) {
                $png = base64_decode(substr($base64, strlen('data:image/png;base64,')), true);
                if ($png !== false && str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
                    fwrite(STDOUT, $mode === 'qr-json'
                        ? json_encode(['base64' => $base64, 'code' => $payload['code'] ?? null], JSON_THROW_ON_ERROR)
                        : $png);
                    exit(0);
                }
            }
            sleep(2);
        }
        throw new RuntimeException('O QR não ficou disponível. Verifique o estado e tente --qr-only novamente.');
    } else {
        throw new RuntimeException('Operação Evolution inválida.');
    }
} catch (Throwable $exception) {
    // Exceções HTTP podem incluir payloads e cabeçalhos com segredos.
    fwrite(STDERR, get_class($exception) === RuntimeException::class
        ? $exception->getMessage()."\n"
        : "Falha ao comunicar com Evolution; verifique a saúde dos serviços.\n");
    exit(1);
}
