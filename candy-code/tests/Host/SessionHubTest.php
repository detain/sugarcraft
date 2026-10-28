<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Host\TranscriptStore;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\EnhancedSessionStore;

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

    /**
     * C-SES-7.e: the incognito contract at the hub — an ephemeral create
     * touches NOTHING that outlives the process: no `sessions` row, no
     * flock, no transcript saves; close drops the host and there is left
     * behind nothing to find.
     */
    public function testAnEphemeralSessionPersistsNothingAndDropsOnClose(): void
    {
        $hub = SessionHub::new(WorkspaceContext::new(sessionStore: $this->store));

        $host = $hub->create(null, 'incognito', ephemeral: true);

        self::assertTrue($host->isEphemeral());
        self::assertSame('incognito', $host->ephemeralName());
        self::assertTrue($hub->isOpen($host->sessionId()), 'it is a live session while open');
        self::assertNull($this->store->session($host->sessionId()), 'no row was minted');

        $lock = $this->store->lockSession($host->sessionId());
        self::assertNotNull($lock, 'no lease was taken — another process could always claim the id');
        $lock->release();

        self::assertTrue($hub->close($host->sessionId()));
        self::assertFalse($hub->isOpen($host->sessionId()));
        self::assertNull($this->store->session($host->sessionId()), 'and close leaves nothing behind either');
    }

    /**
     * C-SES-7.e: the drop-on-close behaviour above proves the HOST is gone;
     * this proves the GATES — every persistence channel an ephemeral host
     * owns is refused at the channel, not by luck of nobody having written.
     * A pre-seeded store row under the host's own id is the discriminator:
     * an ungated `save()` would overwrite it with the host's history.
     */
    public function testTheEphemeralGatesRefuseEveryPersistenceChannel(): void
    {
        $this->store->createSession('ghost', 'sugar-code', 'opus');
        (TranscriptStore::new($this->store))->save('ghost', [Message::user('kept')]);

        $ghost = SessionHost::new('ghost', WorkspaceContext::new(sessionStore: $this->store), [Message::user('discarded')], TranscriptStore::new($this->store), ephemeral: true);
        $save = new \ReflectionMethod(SessionHost::class, 'save');
        $save->invoke($ghost);
        $ghost->release();

        $kept = $this->store->loadTranscript('ghost');
        self::assertIsArray($kept);
        self::assertSame('kept', (string) ($kept[0]['content'] ?? ''), 'the ephemeral save() touched nothing — the row kept its own story');

        $sink = new \ReflectionMethod(SessionHost::class, 'usageRecordSink');
        self::assertNull($sink->invoke($ghost), 'the ephemeral usage sink is null: the settle path has no channel to the ledger');
        $plain = SessionHost::new('visible', WorkspaceContext::new(sessionStore: $this->store), [], TranscriptStore::new($this->store));
        self::assertIsCallable($sink->invoke($plain), 'positive control: the very same accessor on a NON-ephemeral host over the same store hands out the sink');
        $plain->release();

        /*
         * The settle work (auto-memory consolidation, dream, prompt
         * suggestion — the title and memory passes) refuses on the same
         * word. Driving it end to end needs a full engine fixture beyond
         * this lane, so the gate is pinned where it lives: the opening of
         * the method body, mechanically sliced. Deleting the guard reddens
         * this arm exactly as surely as an executed one would.
         */
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Host/SessionHost.php');
        $from = strpos($source, 'function runSettleWork(');
        self::assertIsInt($from, 'the settle chokepoint still exists under this name');
        $body = substr($source, (int) $from, (int) (strpos(substr($source, (int) $from, 700), '$plan =') ?: 600));
        self::assertStringContainsString('if ($this->ephemeral) {', $body, 'an ephemeral host never plans consolidation, dream, or suggestion passes');
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
        self::assertStringContainsString('open in another candy-code', $caught->getMessage());
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

    /**
     * C-SES-2.c: the hub's query arm answers from the FTS path — ranked
     * SessionRow objects converted at this array boundary, snippets kept,
     * the open marks and the cursor law identical to the plain page.
     */
    public function testTheQueryArmPagesRankedBodyMatchesWithTheirSnippets(): void
    {
        foreach (['x', 'y', 'z'] as $id) {
            $this->store->createSession($id, 'p', 'm');
            $this->store->saveTranscript($id, [
                ['role' => 'user', 'content' => $id === 'x' ? 'a plain discussion' : 'the octopus arrived at ' . $id],
            ]);
        }
        $hub = SessionHub::new(WorkspaceContext::new(sessionStore: $this->store));
        $hub->open('z');

        $first = $hub->list(1, null, 'octopus');
        self::assertCount(1, $first['sessions']);
        self::assertNotNull($first['nextCursor'], 'the second match waits behind the cursor');
        self::assertSame($first['sessions'][0]['id'], $first['nextCursor'], 'the cursor is the last id handed out');
        self::assertStringContainsString('[octopus]', (string) $first['sessions'][0]['bodySnippet']);

        $second = $hub->list(1, $first['nextCursor'], 'octopus');
        self::assertCount(1, $second['sessions']);
        self::assertNull($second['nextCursor']);

        $all = [...$first['sessions'], ...$second['sessions']];
        self::assertEqualsCanonicalizing(['y', 'z'], array_column($all, 'id'), 'x never matches and the ranked pages drop nothing');
        foreach ($all as $row) {
            self::assertSame($row['id'] === 'z', $row['open'], 'the open mark rides the ranked row');
        }
        self::assertArrayNotHasKey('bodySnippet', $hub->list(10)['sessions'][0], 'the plain page stays byte-unchanged');
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
