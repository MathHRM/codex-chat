<?php

namespace App\Console\Commands;

use App\Support\BotConfig;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ValidateBotConfig extends Command
{
    protected $signature = 'bot:validate-config';

    protected $description = 'Valida configuração do bot sem imprimir segredos';

    public function handle(): int
    {
        try {
            new BotConfig(config('bot'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Configuração do bot válida.');

        return self::SUCCESS;
    }
}
