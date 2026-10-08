<?php

namespace Tests\Unit;

use BotRunner\Api;
use BotRunner\RunStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../runner/RunStore.php';
require_once __DIR__.'/../../runner/Api.php';

class RunnerApiTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    private string $directory;

    private Api $api;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/runner-test-'.bin2hex(random_bytes(8));
        $this->api = new Api(new RunStore($this->directory), 'test-token', 64);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function test_authentication_precedes_validation_and_creates_no_run(): void
    {
        foreach (['', 'Bearer wrong'] as $authorization) {
            $this->assertSame(401, $this->api->handle('POST', '/runs', $authorization, '{')['status']);
        }
        $this->assertSame([], glob($this->directory.'/*.json'));
        $this->assertSame(401, (new Api(new RunStore($this->directory), '', 64))->handle('GET', '/runs/test', 'Bearer ', '')['status']);
    }

    public function test_repeated_post_and_restarted_store_return_one_durable_request(): void
    {
        $request = ['id' => '12345678-1234-1234-1234-123456789012', 'prompt' => 'literal $(touch /tmp/unsafe); 👋'];
        $first = $this->post($request);
        $this->assertSame(202, $first['status']);
        $this->assertSame('pending', $first['body']['status']);
        $this->assertArrayNotHasKey('prompt', $first['body']);
        $restarted = new Api(new RunStore($this->directory), 'test-token', 64);
        $this->assertSame($first, $restarted->handle('POST', '/runs', 'Bearer test-token', json_encode($request, JSON_THROW_ON_ERROR)));
        $this->assertCount(1, glob($this->directory.'/*.json'));
        $read = $restarted->handle('GET', '/runs/'.$request['id'], 'Bearer test-token', '');
        $this->assertSame(200, $read['status']);
        $this->assertSame($first['body'], $read['body']);
        $this->assertSame(0600, fileperms(glob($this->directory.'/*.json')[0]) & 0777);
    }

    public function test_changed_prompt_or_session_conflicts_without_replacing_original(): void
    {
        $request = ['id' => '12345678-1234-1234-1234-123456789012', 'prompt' => 'original'];
        $this->post($request);
        $this->assertSame(409, $this->post([...$request, 'prompt' => 'changed'])['status']);
        $this->assertSame(409, $this->post([...$request, 'session_id' => '12345678-1234-1234-1234-123456789013'])['status']);
        $this->assertSame('original', (new RunStore($this->directory))->find($request['id'])['prompt']);
    }

    public function test_invalid_paths_extra_options_malformed_json_and_limits_are_rejected(): void
    {
        $request = ['id' => '12345678-1234-1234-1234-123456789012', 'prompt' => 'valid'];
        foreach ([['id' => '../escape'], ['prompt' => ' '], ['session_id' => 'last'], ['cwd' => '/tmp'], ['prompt' => 123]] as $invalid) {
            $this->assertSame(422, $this->post([...$request, ...$invalid])['status']);
        }
        $this->assertSame(422, $this->api->handle('POST', '/runs', 'Bearer test-token', '{')['status']);
        $this->assertSame(413, $this->post([...$request, 'prompt' => str_repeat('👋', 17)])['status']);
        $this->assertSame(404, $this->api->handle('GET', '/runs/'.$request['id'], 'Bearer test-token', '')['status']);
        $this->assertSame([], glob($this->directory.'/*.json'));
    }

    /** @param array<string, mixed> $request
     * @return array{status: int, body: array<string, mixed>}
     */
    private function post(array $request): array
    {
        return $this->api->handle('POST', '/runs', 'Bearer test-token', json_encode($request, JSON_THROW_ON_ERROR));
    }
}
