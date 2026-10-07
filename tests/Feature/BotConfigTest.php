<?php

namespace Tests\Feature;

use App\Support\BotConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BotConfigTest extends TestCase
{
    private function values(): array
    {
        return [
            'owner_number' => '5511999990000', 'instance' => 'owner-bot',
            'webhook_secret' => str_repeat('w', 32), 'runner_token' => str_repeat('r', 32),
            'evolution_key' => str_repeat('e', 32), 'runner_url' => 'http://codex-runner:8081',
            'evolution_url' => 'http://evolution:8080', 'timeout_seconds' => '300',
            'prompt_max_bytes' => '16384', 'response_part_chars' => '3000',
        ];
    }

    public function test_valid_configuration_has_typed_limits_and_lease_longer_than_execution(): void
    {
        $config = new BotConfig($this->values());
        $this->assertSame(300, $config->timeoutSeconds);
        $this->assertSame(420, $config->leaseSeconds);
        $this->assertSame(16384, $config->promptMaxBytes);
        config(['bot' => $this->values()]);
        $this->artisan('bot:validate-config')->expectsOutput('Configuração do bot válida.')->assertSuccessful();
    }

    public static function invalidValues(): array
    {
        return [
            'missing number' => ['owner_number', ''],
            'local number' => ['owner_number', '099999999'],
            'formatted number' => ['owner_number', '+55 11 99999-0000'],
            'long number' => ['owner_number', '1234567890123456'],
            'instance injection' => ['instance', '../other'],
            'missing token' => ['runner_token', ''],
            'weak webhook' => ['webhook_secret', 'short'],
            'weak api key' => ['evolution_key', 'short'],
            'url credentials' => ['runner_url', 'http://user:password@runner'],
            'url query' => ['evolution_url', 'http://evolution/?key=secret'],
            'url protocol' => ['runner_url', 'file:///tmp/run'],
            'negative timeout' => ['timeout_seconds', -1],
            'unbounded timeout' => ['timeout_seconds', 3601],
            'fractional timeout' => ['timeout_seconds', 1.5],
            'oversized prompt' => ['prompt_max_bytes', 16385],
            'zero part' => ['response_part_chars', 0],
            'oversized part' => ['response_part_chars', 3001],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_configuration_fails_without_echoing_supplied_value(string $key, mixed $value): void
    {
        $values = $this->values();
        $values[$key] = $value;
        $this->expectException(InvalidArgumentException::class);
        new BotConfig($values);
    }

    public function test_operator_command_reports_missing_configuration(): void
    {
        config(['bot' => []]);
        $this->artisan('bot:validate-config')->expectsOutput('Configuração obrigatória ausente: owner_number')->assertFailed();
    }
}
