<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Execution;
use App\Models\InboundMessage;
use App\Models\OutboundPart;
use Illuminate\Support\Facades\Cache;
use PDO;
use Tests\TestCase;

class DockerRestoreTest extends TestCase
{
    public function test_isolated_restore_preserves_database_effects_sessions_and_permissions(): void
    {
        if (getenv('DOCKER_RESTORE') !== '1') {
            $this->markTestSkipped('Requires the isolated restored Compose project.');
        }
        $this->assertSame(8, InboundMessage::count());
        $this->assertSame(8, Execution::count());
        $this->assertSame(1, Execution::where('status', 'uncertain')->count());
        $this->assertSame(7, Execution::where('status', 'succeeded')->count());
        $this->assertSame(8, OutboundPart::where('status', 'sent')->count());
        $this->assertTrue(Cache::get('bot:paused'));
        $this->assertFileExists('/acceptance-workspace/.git/HEAD');
        $this->assertSame('Arquivo alterado pelo agente simulado.', file_get_contents('/acceptance-workspace/acceptance.txt'));
        $this->assertCount(8, file('/acceptance-workspace/effects.jsonl', FILE_IGNORE_NEW_LINES));
        foreach (Execution::all() as $execution) {
            $path = '/acceptance-state/runs/'.$execution->runner_id.'.json';
            $this->assertFileExists($path);
            $this->assertSame(1000, fileowner($path));
            $this->assertSame(0600, fileperms($path) & 0777);
            $state = json_decode(file_get_contents($path), true);
            $this->assertSame($execution->status, $state['status']);
        }
        $session = Conversation::whereNotNull('session_id')->latest('created_at')->firstOrFail()->session_id;
        $path = '/acceptance-codex/'.$session.'.fixture';
        $this->assertFileExists($path);
        $this->assertSame(1000, fileowner($path));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertCount(8, file('/acceptance-evolution/sent.jsonl', FILE_IGNORE_NEW_LINES));
        $this->assertSame('backup-storage-marker', file_get_contents(storage_path('app/backup-marker')));
        $this->assertSame(33, fileowner(storage_path('app/backup-marker')));
        $database = new PDO('pgsql:host=postgres;port=5432;dbname=evolution', 'evolution', str_repeat('c', 32));
        $this->assertSame('evolution-backup-marker', $database->query('SELECT value FROM backup_fixture')->fetchColumn());
    }
}
