<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\Live\AgentInbox;
use SugarCraft\Crush\Agents\Live\SubAgentTranscriptLog;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Session\EnhancedSessionStore;

/**
 * Roadmap P-D1's retention remainder (Appendix P §5.6): a session's
 * sub-agent transcript logs and mailboxes follow it out — swept at launch
 * once the session is gone — instead of outliving it forever.
 */
final class SessionArtifactRetentionTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/sc_artifact_retention_' . bin2hex(random_bytes(6));
        mkdir($this->home, 0o700, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->home);
    }

    public function testAGoneSessionsLogsAndMailboxesAreSweptAndNothingElse(): void
    {
        $old = time() - 7200;
        $this->file('subagents/gone/run-1.jsonl', $old);
        $this->file('mailboxes/gone/run-1/inbox.jsonl', $old);
        $this->file('subagents/live/run-2.jsonl', $old);
        $this->file('mailboxes/live/run-2/inbox.jsonl', $old);
        // Gone from the store, but written to within the grace window: a
        // session row can trail its first sub-agent's files.
        $this->file('subagents/fresh/run-3.jsonl', time());
        // A link is never followed or removed.
        mkdir($this->home . '/elsewhere', 0o700);
        $this->file('elsewhere/keep.txt', $old);
        symlink($this->home . '/elsewhere', $this->home . '/subagents/linked');

        $removed = AgentManager::pruneSessionArtifacts(static fn (string $id): bool => $id === 'live', $this->home);

        $this->assertSame(2, $removed);
        $this->assertDirectoryDoesNotExist($this->home . '/subagents/gone');
        $this->assertDirectoryDoesNotExist($this->home . '/mailboxes/gone');
        $this->assertFileExists($this->home . '/subagents/live/run-2.jsonl');
        $this->assertFileExists($this->home . '/mailboxes/live/run-2/inbox.jsonl');
        $this->assertFileExists($this->home . '/subagents/fresh/run-3.jsonl');
        $this->assertTrue(is_link($this->home . '/subagents/linked'));
        $this->assertFileExists($this->home . '/elsewhere/keep.txt');
    }

    public function testNoDirectoriesNoWork(): void
    {
        $this->assertSame(0, AgentManager::pruneSessionArtifacts(static fn (): bool => false, $this->home . '/nothing-here'));
    }

    public function testTheLaunchSweepsWhereTheWritersWrite(): void
    {
        $logs = SubAgentTranscriptLog::defaultRoot();
        $mail = AgentInbox::defaultRoot();
        $this->assertNotNull($logs, 'fixture: the test bootstrap pins the log root');
        $this->assertNotNull($mail);
        $gone = 'gone' . bin2hex(random_bytes(8));
        foreach ([$logs . '/' . $gone . '/run.jsonl', $mail . '/' . $gone . '/run/inbox.jsonl'] as $path) {
            @mkdir(\dirname($path), 0o700, true);
            file_put_contents($path, "{}\n");
            touch($path, time() - 7200);
            touch(\dirname($path), time() - 7200);
        }
        touch($mail . '/' . $gone, time() - 7200);

        $store = new EnhancedSessionStore($this->home . '/s.db');
        $app = App::new($this->createMock(ProviderInterface::class), 'm')->withChat(new Chat(sessionStore: $store));
        $batch = ($app->init())();
        $this->assertInstanceOf(BatchMsg::class, $batch);
        foreach ($batch->cmds as $cmd) {
            if ((new \ReflectionFunction($cmd))->getClosureScopeClass()?->getName() === App::class) {
                $cmd();
            }
        }

        $this->assertDirectoryDoesNotExist($logs . '/' . $gone);
        $this->assertDirectoryDoesNotExist($mail . '/' . $gone);
    }

    private function file(string $relative, int $mtime): void
    {
        $path = $this->home . '/' . $relative;
        @mkdir(\dirname($path), 0o700, true);
        file_put_contents($path, "{}\n");
        touch($path, $mtime);
        for ($dir = \dirname($path); $dir !== $this->home && str_starts_with($dir, $this->home); $dir = \dirname($dir)) {
            touch($dir, $mtime);
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
