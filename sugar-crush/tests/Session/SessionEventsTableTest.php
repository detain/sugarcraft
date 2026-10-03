<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\EnhancedSessionStore;

/**
 * Roadmap O-2b: the `session_events` table {@see EnhancedSessionStore} adds in
 * its schema bootstrap (Appendix O §4.8). Pinned here: its shape, that an
 * older database gains it on open, that it belongs to its session (cascade on
 * delete and prune, recreated row on append), and that a fork starts its own
 * log rather than inheriting its parent's.
 */
final class SessionEventsTableTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-session-events-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $path) {
            if (is_dir($path)) {
                foreach (glob($path . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($path);
                continue;
            }
            @unlink($path);
        }
        @rmdir($this->dir);
    }

    private function path(): string
    {
        return $this->dir . '/session.db';
    }

    public function testTheTableHasTheAppendixOShapeKeyedBySessionAndSeq(): void
    {
        new EnhancedSessionStore($this->path());
        $pdo = new \PDO('sqlite:' . $this->path());

        $columns = $pdo->query('PRAGMA table_info(session_events)')->fetchAll(\PDO::FETCH_ASSOC);

        self::assertSame(
            ['session_id', 'seq', 'ts', 'type', 'payload'],
            array_column($columns, 'name'),
        );
        $pk = array_column(array_filter($columns, static fn (array $c): bool => (int) $c['pk'] > 0), 'pk', 'name');
        self::assertSame(['session_id' => 1, 'seq' => 2], array_map('intval', $pk));
    }

    public function testAnOlderDatabaseGainsTheTableOnOpen(): void
    {
        new EnhancedSessionStore($this->path());
        $pdo = new \PDO('sqlite:' . $this->path());
        $pdo->exec('DROP TABLE session_events');

        $store = new EnhancedSessionStore($this->path());

        self::assertSame(1, $store->appendSessionEvent('s', 'notice'));
    }

    public function testAppendRecreatesAMissingSessionRow(): void
    {
        $store = new EnhancedSessionStore($this->path());

        $store->appendSessionEvent('ghost', 'notice', ['text' => 'x']);

        self::assertNotNull($store->getSession('ghost'), 'the foreign key needs the row');
    }

    public function testDeletingASessionTakesItsEventsWithIt(): void
    {
        $store = new EnhancedSessionStore($this->path());
        $store->createSession('s', 'p', 'm');
        $store->appendSessionEvent('s', 'notice');
        $store->appendSessionEvent('s', 'notice');

        $store->deleteSession('s');

        self::assertSame([null, 0], $store->sessionEventBounds('s'));
        self::assertSame([], $store->sessionEvents('s'));
    }

    public function testAForkStartsItsOwnLog(): void
    {
        $store = new EnhancedSessionStore($this->path());
        $store->createSession('parent', 'p', 'm');
        $store->saveTranscript('parent', [Message::user('hi')]);
        $store->appendSessionEvent('parent', 'message.created');
        $store->appendSessionEvent('parent', 'turn.started');

        $fork = $store->forkSession('parent');

        self::assertSame([null, 0], $store->sessionEventBounds($fork));
        self::assertSame(1, $store->appendSessionEvent($fork, 'session.created'));
        self::assertSame([1, 2], $store->sessionEventBounds('parent'));
    }

    public function testRetentionInTheStoreTrimsFromTheOldEnd(): void
    {
        $store = new EnhancedSessionStore($this->path());
        foreach (range(1, 6) as $n) {
            $store->appendSessionEvent('s', 'e', [], null, 4);
        }

        self::assertSame([3, 6], $store->sessionEventBounds('s'));
        self::assertSame([3, 4, 5, 6], array_column($store->sessionEvents('s'), 'seq'));
    }

    public function testTimestampsAreMillisecondsByDefault(): void
    {
        $store = new EnhancedSessionStore($this->path());
        $before = (int) floor(microtime(true) * 1000);

        $store->appendSessionEvent('s', 'notice');

        $ts = $store->sessionEvents('s')[0]['ts'];
        self::assertGreaterThanOrEqual($before, $ts);
        self::assertLessThanOrEqual((int) floor(microtime(true) * 1000), $ts);
    }
}
