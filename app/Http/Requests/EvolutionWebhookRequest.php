<?php

namespace App\Http\Requests;

use App\Support\BotConfig;
use Illuminate\Foundation\Http\FormRequest;

class EvolutionWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(BotConfig $config): array
    {
        $rules = ['event' => ['required', 'string'], 'instance' => ['required', 'string']];
        if ($this->input('event') === 'messages.upsert' && $this->input('instance') === $config->instance) {
            $rules += [
                'data' => ['required', 'array'],
                'data.key' => ['required', 'array'],
                'data.key.id' => ['required', 'string', 'max:255'],
                'data.key.fromMe' => ['required', 'boolean'],
                'data.key.remoteJid' => ['required', 'string'],
                'data.key.remoteJidAlt' => ['sometimes', 'nullable', 'string'],
                'data.messageType' => ['required', 'string'],
                'data.message' => ['required', 'array'],
            ];
        }

        return $rules;
    }
}
