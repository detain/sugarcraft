<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use ReflectionClass;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Protocol\SessionFeed;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Session\EnhancedSessionStore;

/**
 * CWPD-2 (with CWPD-13 leg (b)): the live sub-agent tree used to be a
 * write-only ledger — nothing pruned it, so every run that never reported a
 * terminal beat rode every snapshot until `Dispatcher::answer()` called the
 * snapshot too large and the session could no longer be opened. Terminal
 * beats now retire a row and the cap trims the rest in first-seen order,
 * naming what it dropped.
 */
final class SessionFeedSubagentsTest extends TestCase
{
    private string $sandbox;

    private string $project;

    protected function setUp(): void
    {
        $this->sandbox = \sys_get_temp_dir() . '/g4c-subagents-' . \bin2hex(\random_bytes(6));
        $this->project = $this->sandbox . '/project';
        \mkdir($this->project, 0o700, true);
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->sandbox)) {
            \exec('rm -rf ' . \escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    public function testATerminalBeatRetiresTheLiveRow(): void
    {
        [$feed, $host] = $this->feedOnServedSession();

        self::assertSame(256, (new ReflectionClass(SessionFeed::class))->getConstant('MAX_SUBAGENT_ROWS'),
            'the cap is a deliberate budget, not an accident of arithmetic');

        $this->beat($host, SessionEvent::SUBAGENT_STARTED, ['id' => 'r1', 'task' => 'first']);
        $this->beat($host, SessionEvent::SUBAGENT_STARTED, ['id' => 'r2', 'task' => 'second']);
        $this->beat($host, SessionEvent::SUBAGENT_PROGRESS, ['id' => 'r2', 'status' => 'working']);
        $this->beat($host, SessionEvent::SUBAGENT_FINISHED, ['id' => 'r1']);

        $rows = $feed->subagents();
        self::assertCount(1, $rows, 'a finished run leaves the live tree');
        self::assertSame('r2', $rows[0]['id']);
        self::assertSame('second', $rows[0]['task'], 'fixture: the sticky identity merge still fills later beats');
    }

    public function testTheGrowthToCapTrimsFirstSeenAndMarksTheLoss(): void
    {
        [$feed, $host] = $this->feedOnServedSession();
        $cap = (new ReflectionClass(SessionFeed::class))->getConstant('MAX_SUBAGENT_ROWS');

        for ($i = 0; $i < $cap + 20; ++$i) {
            $this->beat($host, SessionEvent::SUBAGENT_STARTED, ['id' => 'r' . $i, 'task' => 'run ' . $i]);
        }

        $rows = $feed->subagents();
        self::assertCount($cap, $rows, 'the tree is bounded no matter how many runs go unsaid-goodbye');
        self::assertSame(20, $feed->subagentsDropped());

        $ids = \array_column($rows, 'id');
        self::assertNotContains('r0', $ids, 'the oldest first-seen row is the one that leaves');
        self::assertContains('r20', $ids);
        self::assertContains('r275', $ids);

        $snapshot = $feed->snapshot();
        self::assertSame(20, $snapshot['subagentsDropped'] ?? null, 'the snapshot names what the cap dropped');
        self::assertCount($cap, $snapshot['subagents']);
    }

    public function testAnUntrimmedTreeCarriesNoMarker(): void
    {
        [$feed, $host] = $this->feedOnServedSession();

        $this->beat($host, SessionEvent::SUBAGENT_STARTED, ['id' => 'r1', 'task' => 'first']);

        $snapshot = $feed->snapshot();
        self::assertArrayNotHasKey('subagentsDropped', $snapshot, 'a snapshot that never trimmed stays byte-identical');
        self::assertSame(0, $feed->subagentsDropped());
    }

    /**
     * A served session with its driver feed attached: announcements fan out
     * synchronously into {@see SessionFeed::hear()}.
     *
     * @return array{0: SessionFeed, 1: SessionHost}
     */
    private function feedOnServedSession(): array
    {
        $store = new EnhancedSessionStore($this->sandbox . '/session.db');
        $store->createSession('s', 'p', 'm');

        $workspace = WorkspaceContext::new(root: $this->project, sessionStore: $store, backend: new EchoBackend());
        $hub = SessionHub::new($workspace);
        $host = $hub->open('s');
        $context = ServerContext::new($hub, ServerConfig::new($this->sandbox)->withRoot($this->project), 'test', Loop::get());

        return [$context->feedFor($host), $host];
    }

    /** @param array<string, mixed> $data */
    private function beat(SessionHost $host, string $type, array $data): void
    {
        $host->announce(SessionEvent::new($type, $data, 's', null, 1_700_000_000_000));
    }
}
