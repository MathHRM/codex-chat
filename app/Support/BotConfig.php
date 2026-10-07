<?php

namespace App\Support;

use InvalidArgumentException;

final readonly class BotConfig
{
    public string $ownerNumber;

    public string $instance;

    public string $webhookSecret;

    public string $runnerToken;

    public string $runnerUrl;

    public string $evolutionUrl;

    public string $evolutionKey;

    public int $timeoutSeconds;

    public int $promptMaxBytes;

    public int $responsePartChars;

    public int $leaseSeconds;

    public function __construct(array $values)
    {
        $this->ownerNumber = $this->string($values, 'owner_number');
        if (preg_match('/^[1-9][0-9]{6,14}$/D', $this->ownerNumber) !== 1) {
            throw new InvalidArgumentException('BOT_OWNER_NUMBER deve conter 7 a 15 dígitos internacionais, sem + ou espaços.');
        }
        $this->instance = $this->string($values, 'instance');
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $this->instance) !== 1) {
            throw new InvalidArgumentException('BOT_INSTANCE deve conter somente letras, dígitos, _ ou -.');
        }
        foreach (['webhook_secret' => 'webhookSecret', 'runner_token' => 'runnerToken', 'evolution_key' => 'evolutionKey'] as $key => $property) {
            $value = $this->string($values, $key);
            if (strlen($value) < 32 || preg_match('/\s/', $value) === 1) {
                throw new InvalidArgumentException($key.' deve conter pelo menos 32 caracteres sem espaços.');
            }
            $this->{$property} = $value;
        }
        $this->runnerUrl = $this->url($values, 'runner_url');
        $this->evolutionUrl = $this->url($values, 'evolution_url');
        $this->timeoutSeconds = $this->integer($values, 'timeout_seconds', 1, 3600);
        $this->promptMaxBytes = $this->integer($values, 'prompt_max_bytes', 1, 16384);
        $this->responsePartChars = $this->integer($values, 'response_part_chars', 1, 3000);
        $this->leaseSeconds = $this->timeoutSeconds + 120;
    }

    private function string(array $values, string $key): string
    {
        if (! isset($values[$key]) || ! is_string($values[$key]) || $values[$key] === '') {
            throw new InvalidArgumentException('Configuração obrigatória ausente: '.$key);
        }

        return $values[$key];
    }

    private function url(array $values, string $key): string
    {
        $value = $this->string($values, $key);
        $parts = parse_url($value);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new InvalidArgumentException('URL inválida: '.$key);
        }

        return rtrim($value, '/');
    }

    private function integer(array $values, string $key, int $min, int $max): int
    {
        $value = filter_var($values[$key] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            throw new InvalidArgumentException($key.' fora do intervalo permitido.');
        }

        return $value;
    }
}
