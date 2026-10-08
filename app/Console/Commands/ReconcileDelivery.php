<?php

namespace App\Console\Commands;

use App\Models\OutboundPart;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReconcileDelivery extends Command
{
    protected $signature = 'bot:delivery {part? : UUID da parte} {--sent= : ID externo confirmado} {--retry : Autoriza reenvio com risco de duplicata}';

    protected $description = 'Lista e reconcilia entregas incertas ou rejeitadas';

    public function handle(): int
    {
        $id = $this->argument('part');
        if ($id === null) {
            $this->table(['Parte', 'Estado', 'Tentativas', 'Causa'], OutboundPart::whereIn('status', ['uncertain', 'failed', 'sending'])->orderBy('local_order')->orderBy('part_index')->get(['id', 'status', 'attempts', 'error_code'])->toArray());

            return self::SUCCESS;
        }
        if (($this->option('sent') === null) === (! $this->option('retry'))) {
            $this->error('Escolha exatamente --sent=ID ou --retry.');

            return self::FAILURE;
        }
        $part = OutboundPart::find($id);
        if ($part === null) {
            $this->error('Parte não encontrada.');

            return self::FAILURE;
        }
        $lock = Cache::lock('bot:delivery:'.$part->conversation_head_id, 60);
        if (! $lock->get()) {
            $this->error('Entrega ativa; aguarde e reconcilie novamente.');

            return self::FAILURE;
        }
        try {
            return DB::transaction(function () use ($id): int {
                $part = OutboundPart::whereKey($id)->lockForUpdate()->firstOrFail();
                if (! in_array($part->status, ['uncertain', 'failed', 'sending'], true)) {
                    $this->error('A parte não requer reconciliação.');

                    return self::FAILURE;
                }
                if ($this->option('retry')) {
                    $part->update(['status' => 'pending', 'attempts' => 0, 'retry_at' => null, 'error_code' => null]);
                    $this->warn('Reenvio autorizado; pode duplicar uma mensagem já aceita pela Evolution.');
                } else {
                    $externalId = (string) $this->option('sent');
                    if (trim($externalId) === '' || strlen($externalId) > 255) {
                        $this->error('ID externo inválido.');

                        return self::FAILURE;
                    }
                    $part->update(['status' => 'sent', 'evolution_id' => $externalId, 'sent_at' => now('UTC'), 'retry_at' => null, 'error_code' => null]);
                    $this->info('Aceitação externa registrada.');
                }

                return self::SUCCESS;
            }, attempts: 5);
        } finally {
            $lock->release();
        }
    }
}
