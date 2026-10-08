<?php

namespace Tests\Feature;

use App\Jobs\ProcessBot;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BotOperationsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bot.owner_number' => '5511999990000', 'bot.instance' => 'owner-bot',
            'bot.webhook_secret' => str_repeat('w', 32), 'bot.runner_token' => str_repeat('r', 32),
            'bot.evolution_key' => str_repeat('e', 32)]);
        Http::preventStrayRequests();
    }

    public function test_health_requires_recent_component_heartbeat_and_tick_reaches_paused_worker(): void
    {
        $this->artisan('bot:health worker')->assertFailed();
        $this->artisan('bot:health scheduler')->assertFailed();
        $this->artisan('bot:health invalid')->assertFailed();
        Cache::put('bot:heartbeat:worker', time() - 121);
        $this->artisan('bot:health worker')->assertFailed();
        Cache::forget('bot:heartbeat:worker');
        Cache::put('bot:heartbeat:worker', (string) time());
        $this->artisan('bot:health worker')->assertSuccessful();
        Cache::put('bot:heartbeat:worker', 'invalid');
        $this->artisan('bot:health worker')->assertFailed();
        Cache::forget('bot:heartbeat:worker');
        Cache::forever('bot:paused', true);
        Queue::fake();
        $this->artisan('bot:tick')->assertSuccessful();
        Queue::assertPushed(ProcessBot::class);
        $this->artisan('bot:health scheduler')->assertSuccessful();
        app()->call([new ProcessBot, 'handle']);
        $this->artisan('bot:health worker')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_external_diagnosis_reports_unavailable_services_without_private_body(): void
    {
        Http::fake(['*' => Http::response(['message' => 'private secret'], 503)]);
        $this->artisan('bot:status --external')
            ->expectsOutput('runner=indisponivel')->expectsOutput('whatsapp=indisponivel')->assertSuccessful();
        $this->artisan('bot:status --pause --resume')->assertFailed();
    }
}
