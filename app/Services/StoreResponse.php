<?php

namespace App\Services;

use App\Models\Execution;
use App\Models\OutboundPart;
use App\Support\BotConfig;

final class StoreResponse
{
    public function __construct(private readonly BotConfig $config) {}

    public function store(Execution $execution, string $text): void
    {
        $message = $execution->message;
        $length = mb_strlen($text, 'UTF-8');
        for ($offset = 0, $index = 0; $offset < $length; $offset += $this->config->responsePartChars, $index++) {
            OutboundPart::firstOrCreate(['source_key' => $execution->id.':'.$index], [
                'conversation_head_id' => $message->conversation_head_id, 'execution_id' => $execution->id,
                'local_order' => $message->local_order, 'part_index' => $index,
                'text' => mb_substr($text, $offset, $this->config->responsePartChars, 'UTF-8'),
            ]);
        }
    }

    public function notice(string $error): string
    {
        return match ($error) {
            'authentication' => 'O Codex precisa de login. A tarefa não foi concluída.',
            'usage_limit' => 'O limite de uso do Codex foi atingido. Tente novamente mais tarde.',
            'timeout' => 'A tarefa atingiu o limite de tempo e foi interrompida. Ela não será repetida automaticamente.',
            'invalid_session' => 'O contexto anterior não está disponível. Envie uma nova mensagem para começar uma sessão nova.',
            'runner_interrupted', 'runner_conflict', 'invalid_result' => 'A execução ficou incerta após uma interrupção. Ela não será repetida. A próxima mensagem iniciará uma sessão nova.',
            default => 'Não foi possível concluir a tarefa. Ela não será repetida automaticamente.',
        };
    }
}
