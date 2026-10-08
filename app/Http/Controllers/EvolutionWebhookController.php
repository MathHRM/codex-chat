<?php

namespace App\Http\Controllers;

use App\Http\Requests\EvolutionWebhookRequest;
use App\Services\AcceptMessage;
use App\Services\EvolutionContract;
use App\Services\RejectOversizedMessage;
use App\Support\BotConfig;
use Illuminate\Http\JsonResponse;

final class EvolutionWebhookController extends Controller
{
    public function __invoke(EvolutionWebhookRequest $request, EvolutionContract $evolution, BotConfig $config, AcceptMessage $accept, RejectOversizedMessage $reject): JsonResponse
    {
        $input = $evolution->extract($request->validated());
        if ($input === null) {
            return response()->json(['status' => 'ignored'], 202);
        }
        if (strlen($input['text']) > $config->promptMaxBytes) {
            $reject->reject($input);

            return response()->json(['status' => 'rejected', 'reason' => 'prompt_too_large'], 202);
        }
        $message = $accept->accept($input);

        return response()->json(['status' => 'accepted', 'message_id' => $message->id], 202);
    }
}
