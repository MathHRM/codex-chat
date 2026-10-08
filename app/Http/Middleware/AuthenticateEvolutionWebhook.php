<?php

namespace App\Http\Middleware;

use App\Support\BotConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateEvolutionWebhook
{
    public function __construct(private readonly BotConfig $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->header('X-Webhook-Secret');
        abort_unless(is_string($secret) && hash_equals($this->config->webhookSecret, $secret), 401);

        return $next($request);
    }
}
