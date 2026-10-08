<?php

namespace Tests\Unit;

use BotRunner\CodexProcess;
use BotRunner\RunStore;
use BotRunner\Supervisor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../runner/RunStore.php';
require_once __DIR__.'/../../runner/Supervisor.php';
require_once __DIR__.'/../../runner/CodexProcess.php';

class RunnerExecutionTest extends TestCase
{
    private string $directory;

    private RunStore $store;

    private string $id = '12345678-1234-1234-1234-123456789012';

    private string $session = '12345678-1234-1234-1234-123456789099';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/runner-exec-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory.'/AGENTS.md', 'Responda em português.');
        $this->store = new RunStore($this->directory.'/runs');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/runs/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory.'/runs');
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function test_supervisor_is_unique_executes_in_order_and_never_replays_interrupted_work(): void
    {
        $second = '12345678-1234-1234-1234-123456789013';
        $this->store->accept($this->id, 'first', null);
        $this->store->accept($second, 'second', null);
        $supervisor = new Supervisor($this->store, $this->directory.'/supervisor.lock');
        try {
            new Supervisor($this->store, $this->directory.'/supervisor.lock');
            $this->fail('Second supervisor accepted');
        } catch (RuntimeException $exception) {
            $this->assertSame('supervisor_already_running', $exception->getMessage());
        }
        $calls = [];
        $supervisor->tick(function (array $run) use (&$calls): array {
            $calls[] = $run['id'];

            return ['status' => 'succeeded'];
        });
        $this->assertSame([$this->id], $calls);
        unset($supervisor);
        $this->store->update($second, ['status' => 'running']);
        $restarted = new Supervisor($this->store, $this->directory.'/supervisor.lock');
        $this->assertSame('uncertain', $this->store->find($second)['status']);
        $this->assertFalse($restarted->tick(function (): array {
            $this->fail('Interrupted request replayed');
        }));
        $third = '12345678-1234-1234-1234-123456789014';
        $this->store->accept($third, 'pending across restart', null);
        unset($restarted);
        $restarted = new Supervisor($this->store, $this->directory.'/supervisor.lock');
        $this->assertTrue($restarted->tick(function (array $run) use ($third): array {
            $this->assertSame($third, $run['id']);

            return ['status' => 'succeeded'];
        }));
    }

    public function test_prompt_is_stdin_flags_are_fixed_and_only_final_answer_is_published(): void
    {
        $this->fake(<<<'CODE'
file_put_contents('capture.json', json_encode(['argv' => $argv, 'stdin' => stream_get_contents(STDIN), 'token' => getenv('RUNNER_TOKEN')]));
$position = array_search('--output-last-message', $argv, true);
file_put_contents($argv[$position + 1], "Resposta final 👋\n");
echo json_encode(['type' => 'thread.started', 'thread_id' => '12345678-1234-1234-1234-123456789099'])."\n";
echo json_encode(['type' => 'item.completed', 'item' => ['type' => 'command_execution', 'output' => 'private log']])."\n";
echo json_encode(['type' => 'turn.completed'])."\n";
CODE);
        $prompt = "quotes ' \" \n $(touch should-not-exist) --last";
        putenv('RUNNER_TOKEN=must-not-inherit');
        try {
            $result = $this->execute($prompt);
            $this->assertSame('succeeded', $result['status']);
            $this->assertSame("Resposta final 👋\n", $result['answer']);
            $this->assertSame($this->session, $result['result_session_id']);
            $capture = json_decode(file_get_contents($this->directory.'/capture.json'), true);
            $this->assertSame($prompt, $capture['stdin']);
            $this->assertFalse($capture['token']);
            $this->assertSame('-', end($capture['argv']));
            $this->assertContains('approval_policy="never"', $capture['argv']);
            $this->assertContains('sandbox_mode="workspace-write"', $capture['argv']);
            $this->assertNotContains('--last', $capture['argv']);
            $this->assertNotContains('--ephemeral', $capture['argv']);
            $this->assertFileDoesNotExist($this->directory.'/should-not-exist');
            $this->store->accept('12345678-1234-1234-1234-123456789013', 'followup', $this->session);
            $run = $this->store->find('12345678-1234-1234-1234-123456789013');
            $this->executor()->execute($run);
            $capture = json_decode(file_get_contents($this->directory.'/capture.json'), true);
            $this->assertContains('resume', $capture['argv']);
            $this->assertContains($this->session, $capture['argv']);
        } finally {
            putenv('RUNNER_TOKEN');
        }
    }

    public function test_missing_instructions_and_nonzero_exit_never_publish_partial_answers(): void
    {
        $this->fake('fwrite(STDERR, "authentication 401"); exit(1);');
        $result = $this->execute('request');
        $this->assertSame('authentication', $result['error']);
        $this->assertArrayNotHasKey('answer', $result);
        file_put_contents($this->directory.'/AGENTS.md', ' ');
        $this->assertSame('instructions_missing', $this->executor()->execute($this->store->find($this->id))['error']);
    }

    public function test_json_error_classifies_authentication_without_publishing_private_diagnostics(): void
    {
        $this->fake('echo json_encode(["type" => "turn.failed", "error" => ["message" => "authentication 401 private credential"]])."\n"; exit(1);');
        $result = $this->execute('request');
        $this->assertSame(['status' => 'failed', 'error' => 'authentication'], $result);
    }

    public function test_timeout_kills_descendant_and_does_not_publish_partial_output(): void
    {
        $this->fake(<<<'CODE'
$pid = pcntl_fork();
if ($pid === 0) { while (true) { usleep(10000); } }
file_put_contents('child.pid', (string) $pid);
while (true) { usleep(10000); }
CODE);
        $result = $this->execute('request');
        $this->assertSame('timeout', $result['error']);
        $this->assertArrayNotHasKey('answer', $result);
        $child = (int) file_get_contents($this->directory.'/child.pid');
        $processStat = '/proc/'.$child.'/stat';
        $stat = is_file($processStat) ? file_get_contents($processStat) : false;
        $this->assertTrue($stat === false || preg_match('/\) Z /', $stat) === 1, 'Descendant is still running');
    }

    private function fake(string $code): void
    {
        file_put_contents($this->directory.'/fake-codex', '#!'.PHP_BINARY."\n<?php\n".$code);
        chmod($this->directory.'/fake-codex', 0700);
    }

    private function executor(): CodexProcess
    {
        return new CodexProcess($this->store, $this->directory, $this->directory, 1, $this->directory.'/fake-codex');
    }

    /** @return array<string, mixed> */
    private function execute(string $prompt): array
    {
        $this->store->accept($this->id, $prompt, null);

        return $this->executor()->execute($this->store->find($this->id));
    }
}
