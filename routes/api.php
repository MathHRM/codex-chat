<?php

use App\Http\Controllers\EvolutionWebhookController;
use App\Http\Middleware\AuthenticateEvolutionWebhook;
use Illuminate\Support\Facades\Route;

Route::post('v1/webhooks/evolution', EvolutionWebhookController::class)
    ->middleware(AuthenticateEvolutionWebhook::class)->name('webhooks.evolution');
