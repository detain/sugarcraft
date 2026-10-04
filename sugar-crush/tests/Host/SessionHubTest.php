<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Host\SessionHub;
use SugarCraft\Crush\Host\TranscriptStore;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\EnhancedSessionStore;

/**
 * Roadmap O-2g / Appendix O §4.3–§4.5: {@see SessionHub} opens a session over
 * its saved transcript holding the same lock a TUI holds, refuses one another
 * process has open, never evicts or silently closes a busy host, and lists
 * the workspace's sessions a page at a time.
 */
final class SessionHubTest extends TestCase
{
    private string $dir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-session-hub-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            foreach (glob($sub . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($sub);
        }
        @rmdir($this->dir);
    }

    public function testOpenLoadsTheSavedTranscriptAndHoldsTheSessionsLock(): void
    {
        $this->store->createSession('a', 'p', 'm');
        TranscriptStore::new($this->store)->save('a', [Message::user('earlier'), Message::assistant('reply')]);
        $hub = SessionHub::new(WorkspaceContext::new(sessionStore: $this->store));

        $host = $hub->open('a');

        self::assertSame(['earlier', 'reply'], array_map(static fn (Message $m): string => $m->content, $host->history()));
        self::assertSame($host, $hub->open('a'), 'an open session is the same host');
        self::assertNull($this->store->lockSession('a'), 'the hub holds the flock a second writer would need');

        self::assertTrue($hub->close('a'));
        self::assertFalse($hub->isOpen('a'));
        $retaken = $this->store->lockSession('a');
        self::assertNotNull($retaken, 'closing released the lock');
        $retaken->release();
    }

    public function testASessionAnotherProcessHoldsIsRefused(): void
    {
        $this->store->createSession('b', 'p', 'm');
        $held = $this->store->lockSession('b');
        self::assertNotNull($held);

        $caught = null;
        try {
            SessionHub::new(WorkspaceContext::new(sessionStore: $this->store))->open('b');
        } catch (\RuntimeException $e) {
            $caught = $e;
        } finally {
            $held->release();
        }

        self::assertNotNull($caught, 'a held session was opened for writing');
        self::assertStringContainsString('open in another sugarcrush', $caught->getMessage());
    }

    public function testABusyHostIsNeitherEvictedNorClosedUnlessForced(): void
    {
        $backend = self::pending();
        $hub = SessionHub::new(WorkspaceContext::new(sessionStore: $this->store, backend: $backend), maxOpen: 1);
        $busy = $hub->create('one');
        $busy->submit('working');
        self::assertTrue($busy->isBusy());

        $hub->create('two');
        self::assertSame(['one', 'two'], $hub->openSessionIds(), 'over the cap, but the only other host is busy');

        $hub->create('three');
        self::assertSame(['one', 'three'], $hub->openSessionIds(), 'the idle least-recently-used host went');

        self::assertFalse($hub->close('one'), 'a running turn is not closed by default');
        self::assertTrue($hub->close('one', force: true));
        self::assertFalse($busy->isBusy(), 'forcing cancelled the turn');
        $hub->closeAll();
        self::assertSame([], $hub->openSessionIds());
    }

    public function testListPagesTheWorkspacesSessionsAndMarksTheOpenOnes(): void
    {
        foreach (['x', 'y', 'z'] as $id) {
            $this->store->createSession($id, 'p', 'm');
        }
        $hub = SessionHub::new(WorkspaceContext::new(sessionStore: $this->store));
        $hub->open('y');

        $first = $hub->list(2);
        self::assertCount(2, $first['sessions']);
        self::assertNotNull($first['nextCursor']);
        $second = $hub->list(2, $first['nextCursor']);
        self::assertCount(1, $second['sessions']);
        self::assertNull($second['nextCursor']);

        $all = [...$first['sessions'], ...$second['sessions']];
        self::assertEqualsCanonicalizing(['x', 'y', 'z'], array_column($all, 'id'));
        foreach ($all as $row) {
            self::assertSame($row['id'] === 'y', $row['open']);
        }
        $hub->closeAll();
    }

    public function testACapBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SessionHub::new(WorkspaceContext::new(), maxOpen: 0);
    }

    private static function pending(): Backend
    {
        return new class () implements Backend {
            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('unused');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return (new Deferred())->promise();
            }
        };
    }
}
