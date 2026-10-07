<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class EvolutionContract
{
    public const IMAGE = 'evoapicloud/evolution-api:v2.3.7@sha256:1bd8afc4a6cf48822e6cf02469aeae7bd35a12a6b616eacd1291926307f4d339';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $instance,
        private readonly string $ownerNumber,
    ) {}

    /** @return array{external_id: string, instance: string, number: string, text: string}|null */
    public function extract(array $payload): ?array
    {
        if (($payload['event'] ?? null) !== 'messages.upsert' || ($payload['instance'] ?? null) !== $this->instance) {
            return null;
        }

        $data = $payload['data'] ?? null;
        if (! is_array($data) || ! is_array($key = $data['key'] ?? null) || ($key['fromMe'] ?? null) !== false) {
            return null;
        }
        $id = $key['id'] ?? null;
        $jid = $key['remoteJid'] ?? null;
        if (! is_string($id) || $id === '' || strlen($id) > 255 || ! is_string($jid)) {
            return null;
        }
        if (preg_match('/^\d+@lid$/D', $jid) === 1) {
            $jid = $key['remoteJidAlt'] ?? null;
        }
        if ($jid !== $this->ownerNumber.'@s.whatsapp.net') {
            return null;
        }
        $message = $data['message'] ?? null;
        if (! is_array($message)) {
            return null;
        }
        $type = $data['messageType'] ?? null;
        $text = match ($type) {
            'conversation' => $message['conversation'] ?? null,
            'extendedTextMessage' => $message['extendedTextMessage']['text'] ?? null,
            default => null,
        };
        if (! is_string($text) || trim($text) === '' || ! mb_check_encoding($text, 'UTF-8')) {
            return null;
        }

        return ['external_id' => $id, 'instance' => $this->instance, 'number' => $this->ownerNumber, 'text' => $text];
    }

    public function sendText(string $text): Response
    {
        return Http::withHeaders(['apikey' => $this->apiKey])
            ->acceptJson()->timeout(15)->connectTimeout(5)
            ->post(rtrim($this->baseUrl, '/').'/message/sendText/'.rawurlencode($this->instance), [
                'number' => $this->ownerNumber,
                'text' => $text,
                'linkPreview' => false,
            ]);
    }
}
