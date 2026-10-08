<?php

namespace App\Console\Commands;

use App\Models\Execution;
use App\Models\InboundMessage;
use App\Models\OutboundPart;
use App\Support\BotConfig;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class BotStatus extends Command
{
    protected $signature = 'bot:status {--pause : Pausa novos ticks} {--resume : Retoma ticks} {--external : Verifica runner e conexão WhatsApp}';

    protected $description = 'Diagnóstico sem prompts ou credenciais; pausa e retomada do processamento';

    public function handle(BotConfig $config): int
    {
        if ($this->option('pause') && $this->option('resume')) {
            $this->error('Escolha --pause ou --resume.');

            return self::FAILURE;
        }
        if ($this->option('pause')) {
            Cache::forever('bot:paused', true);
        }
        if ($this->option('resume')) {
            Cache::forget('bot:paused');
        }
        DB::connection()->getPdo();
        $this->line('banco=ok cache=ok processamento='.(Cache::get('bot:paused', false) ? 'pausado' : 'ativo'));
        $this->line('mensagens_pendentes='.InboundMessage::where('status', 'pending')->count().' execucoes_ativas='.Execution::where('status', 'running')->count().' entregas_incertas='.OutboundPart::where('status', 'uncertain')->count());
        if ($this->option('external')) {
            try {
                $runner = Http::withToken($config->runnerToken)->timeout(5)->get($config->runnerUrl.'/health');
                $this->line('runner='.($runner->successful() ? 'ok' : 'indisponivel'));
            } catch (ConnectionException) {
                $this->line('runner=indisponivel');
            }
            try {
                $response = Http::withHeaders(['apikey' => $config->evolutionKey])->timeout(5)->get($config->evolutionUrl.'/instance/connectionState/'.rawurlencode($config->instance));
                $state = $response->json('instance.state');
                $this->line('whatsapp='.($response->successful() && in_array($state, ['open', 'close', 'connecting'], true) ? $state : 'indisponivel'));
            } catch (ConnectionException) {
                $this->line('whatsapp=indisponivel');
            }
        }

        return self::SUCCESS;
    }
}
