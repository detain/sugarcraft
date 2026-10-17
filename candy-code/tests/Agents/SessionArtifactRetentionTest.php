<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\Live\AgentInbox;
use SugarCraft\Code\Agents\Live\SubAgentTranscriptLog;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Session\EnhancedSessionStore;

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

    /**
     * rv-devops-4: with retention opted in, aged artifacts go even when their
     * session still exists — sub-agent dirs by the same newest-mtime basis,
     * media session dirs by their directory mtime (the last publish bumps it),
     * share exports file by file. Fresh work, stray files and links survive.
     */
    public function testRetentionDaysSweepsAgedArtifactsOfLiveSessionsToo(): void
    {
        $old = time() - 8 * 86400 - 3600;
        $this->file('subagents/live2/old-run.jsonl', $old);
        $this->file('media/oldsess/art.png', $old);
        $this->file('media/freshsess/art.png', time());
        $this->file('exports/old.md', $old);
        $this->file('exports/fresh.md', time());
        // Neither sweeper follows a link, in either root.
        symlink($this->home . '/media/freshsess', $this->home . '/media/linked');
        $this->file('elsewhere/keep.txt', $old);
        symlink($this->home . '/elsewhere', $this->home . '/subagents/linked');

        putenv('CANDY_CODE_SESSION_RETENTION_DAYS=7');

        try {
            // Every session exists: only the age leg can fire.
            $removed = AgentManager::pruneSessionArtifacts(static fn (string $id): bool => true, $this->home);
        } finally {
            putenv('CANDY_CODE_SESSION_RETENTION_DAYS');
        }

        $this->assertSame(3, $removed, 'aged subagent dir + aged media dir + aged export file');
        $this->assertDirectoryDoesNotExist($this->home . '/subagents/live2');
        $this->assertDirectoryDoesNotExist($this->home . '/media/oldsess');
        $this->assertFileDoesNotExist($this->home . '/exports/old.md');
        $this->assertFileExists($this->home . '/media/freshsess/art.png');
        $this->assertFileExists($this->home . '/exports/fresh.md');
        $this->assertFileExists($this->home . '/elsewhere/keep.txt');
        $this->assertTrue(is_link($this->home . '/media/linked'));
        $this->assertTrue(is_link($this->home . '/subagents/linked'));
    }

    /**
     * Retention off (the default) keeps every byte the sweep could reach: the
     * gone-session legs stay, the aged-live legs never fire.
     */
    public function testRetentionOffLeavesAgedArtifactsOfLiveSessionsAlone(): void
    {
        $old = time() - 8 * 86400 - 3600;
        $this->file('subagents/live2/old-run.jsonl', $old);
        $this->file('media/oldsess/art.png', $old);
        $this->file('exports/old.md', $old);

        putenv('CANDY_CODE_SESSION_RETENTION_DAYS=0');

        try {
            $removed = AgentManager::pruneSessionArtifacts(static fn (string $id): bool => true, $this->home);
        } finally {
            putenv('CANDY_CODE_SESSION_RETENTION_DAYS');
        }

        $this->assertSame(0, $removed);
        $this->assertFileExists($this->home . '/subagents/live2/old-run.jsonl');
        $this->assertFileExists($this->home . '/media/oldsess/art.png');
        $this->assertFileExists($this->home . '/exports/old.md');
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
