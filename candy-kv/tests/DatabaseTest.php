<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use SugarCraft\Kv\Database;
use SugarCraft\Kv\Entry;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    private string $tmpDir;
    private Database $db;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/candy-kv-test-' . \uniqid();
        \mkdir($this->tmpDir, 0o700, true);
        $this->db = new Database($this->tmpDir . '/test.db', 'test');
    }

    protected function tearDown(): void
    {
        unset($this->db);
        $files = \glob($this->tmpDir . '/*.db') ?: [];
        foreach ($files as $f) { \unlink($f); }
        \rmdir($this->tmpDir);
    }

    public function testSetAndGet(): void
    {
        $e = $this->db->set('hello', 'world');
        $this->assertSame('hello', $e->key);
        $this->assertSame('world', $e->value);
        $this->assertFalse($e->binary);

        $fetched = $this->db->get('hello');
        $this->assertSame('world', $fetched->value);
    }

    public function testGetNonExistentReturnsNull(): void
    {
        $this->assertNull($this->db->get('does-not-exist'));
    }

    public function testSetOverwritesExisting(): void
    {
        $this->db->set('k', 'v1');
        $e = $this->db->set('k', 'v2');
        $this->assertSame('v2', $e->value);
    }

    public function testSetBinary(): void
    {
        $raw = "\x00\xff\xfe\xfd";
        $e = $this->db->set('binary-key', \base64_encode($raw), true);
        $this->assertTrue($e->binary);
        $this->assertSame($raw, $e->rawValue());
    }

    public function testDeleteExisting(): void
    {
        $this->db->set('del', 'me');
        $deleted = $this->db->delete('del');
        $this->assertTrue($deleted);
        $this->assertNull($this->db->get('del'));
    }

    public function testDeleteNonExistent(): void
    {
        $this->assertFalse($this->db->delete('nope'));
    }

    public function testListAll(): void
    {
        $this->db->set('a', '1');
        $this->db->set('b', '2');
        $this->db->set('c', '3');

        $keys = [];
        foreach ($this->db->list() as $entry) {
            $keys[] = $entry->key;
        }

        $this->assertSame(['a', 'b', 'c'], $keys);
    }

    public function testListReverseOrder(): void
    {
        $this->db->set('a', '1');
        $this->db->set('b', '2');
        $this->db->set('c', '3');

        $keys = [];
        foreach ($this->db->list(null, reverse: true) as $entry) {
            $keys[] = $entry->key;
        }

        $this->assertSame(['c', 'b', 'a'], $keys);
    }

    public function testListKeysOnly(): void
    {
        $this->db->set('a', '1');
        $this->db->set('b', '2');

        $keys = [...$this->db->list(mode: 'keys')];
        $this->assertSame(['a', 'b'], $keys);
    }

    public function testListValuesOnly(): void
    {
        $this->db->set('a', 'alpha');
        $this->db->set('b', 'beta');

        $values = [...$this->db->list(mode: 'values')];
        $this->assertSame(['alpha', 'beta'], $values);
    }

    public function testListRefusesUnknownMode(): void
    {
        // kv-015: the signature declares 'all'|'keys'|'values'; a typo used
        // to fall through the yield match and yield Entry objects where the
        // caller asked for something else. It must fail loud instead.
        $this->db->set('a', '1');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown list mode "BOGUS"');

        [...$this->db->list(mode: 'BOGUS')];
    }

    public function testListGlobPatternStar(): void
    {
        $this->db->set('user-alice', '1');
        $this->db->set('user-bob', '2');
        $this->db->set('admin-carol', '3');
        $this->db->set('config', '4');

        $keys = [...$this->db->list('user-*')];
        $keys = \array_map(fn($e) => $e->key, $keys);
        $this->assertSame(['user-alice', 'user-bob'], $keys);
    }

    public function testListGlobPatternQuestionMark(): void
    {
        $this->db->set('a1', 'x');
        $this->db->set('a2', 'y');
        $this->db->set('a12', 'z');

        $keys = [...$this->db->list('a?')];
        $keys = \array_map(fn($e) => $e->key, $keys);
        $this->assertSame(['a1', 'a2'], $keys);
    }

    public function testCount(): void
    {
        $this->assertSame(0, $this->db->count());
        $this->db->set('a', '1');
        $this->db->set('b', '2');
        $this->assertSame(2, $this->db->count());
        $this->assertSame(1, $this->db->count('a*'));
    }

    public function testCountExcludesExpiredRows(): void
    {
        // Regression pin (audit #3): count(null) used to run a bare
        // SELECT COUNT(*) while get()/list()/allKeys() all drop expired
        // rows — TTL-dead entries inflated the total.
        $this->db->set('live', '1');
        $this->db->set('doomed', '2', false, 3600);
        $this->db->set('also-live', '3');
        $this->assertSame(3, $this->db->count());

        // Force 'doomed' into the past: no public API can stamp a past
        // expiry (negative TTLs clamp to never-expire), so rewrite the row
        // through the live SQLite handle. The cell is bound as an epoch
        // INTEGER — the storage contract the affinity rebuild (kv-128)
        // enforces; TEXT expiry cells no longer compare as expiries.
        $expired = \time() - 3600;
        $prop = new \ReflectionProperty(\SugarCraft\Kv\Database::class, 'db');
        $prop->setAccessible(true);
        /** @var \SQLite3 $sqlite */
        $sqlite = $prop->getValue($this->db);
        $stmt = $sqlite->prepare('UPDATE entries SET expires_at = :t WHERE key = :k');
        $stmt->bindValue(':t', $expired, \SQLITE3_INTEGER);
        $stmt->bindValue(':k', 'doomed', \SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        $this->assertNull($this->db->get('doomed'), 'expired row must be invisible to get()');
        $this->assertSame(2, $this->db->count(), 'count(null) must exclude expired rows');
        $this->assertSame(2, $this->db->count('*'), 'count(pattern) already excluded them — totals must agree');
    }

    public function testGlobBackslashIsEscapedNotInterpreted(): void
    {
        // Regression pin (audit #13): globToLike escaped % and _ but not a
        // literal backslash, so the pattern "a\b" produced LIKE 'a\b' with
        // ESCAPE '\' — the dangling introducer consumed the 'b' and made the
        // pattern match plain "ab" while MISSING the real "a\b" key. Counting
        // alone cannot see this (both sides yield 1), so pin the identity.
        $this->db->set('ab', '1');
        $this->db->set('a\\b', '2');

        $matched = \iterator_to_array($this->db->list('a\\b', mode: 'keys'));
        $this->assertSame(['a\\b'], $matched, 'literal backslash must match exactly the backslash key');

        // Second polarity: with only the escape-shadowed key present, the
        // buggy translation matches 'ab' (absent) and not 'a\b' → count 0.
        $other = new Database($this->tmpDir . '/other.db', 'other');
        $other->set('ab', '1');
        $this->assertSame(0, $other->count('a\\b'), 'backslash pattern must not match the unescaped twin');
        $other->close();

        $this->assertSame(2, $this->db->count('a*'), 'wildcards keep working alongside the escape');
    }

    public function testDeleteManyGlob(): void
    {
        $this->db->set('temp-1', 'v');
        $this->db->set('temp-2', 'v');
        $this->db->set('keep', 'v');

        $n = $this->db->deleteMany('temp-*');
        $this->assertSame(2, $n);
        $this->assertSame(1, $this->db->count());
    }

    public function testListDatabasesFromDir(): void
    {
        $dbs = Database::listDatabases($this->tmpDir);
        $this->assertContains('test', $dbs);
    }

    public function testSetWithTtl(): void
    {
        $e = $this->db->set('ttl-key', 'value', false, 3600);
        $this->assertNotNull($e->expiresAt);
        $this->assertFalse($e->isExpired());
        $this->assertGreaterThan(new \DateTimeImmutable(), $e->expiresAt);
    }

    public function testSetWithTtlZeroClearsExpiry(): void
    {
        $e = $this->db->set('a', 'v', false, 3600);
        $e2 = $this->db->set('a', 'v', false, null);
        $this->assertNull($e2->expiresAt);
    }

    public function testGetSkipsExpiredEntry(): void
    {
        // Manually insert an already-expired entry using getRaw + raw SQLite3
        $reflection = new \ReflectionClass($this->db);
        $prop = $reflection->getProperty('db');
        $prop->setAccessible(true);
        /** @var \SQLite3 $sqlite */
        $sqlite = $prop->getValue($this->db);

        $past = \time() - 3600;
        $stmt = $sqlite->prepare(
            'INSERT INTO entries (key, value, binary, created, modified, expires_at)
             VALUES (:key, :value, 0, :now, :now, :expires_at)'
        );
        $stmt->bindValue(':key', 'expired-key', \SQLITE3_TEXT);
        $stmt->bindValue(':value', 'should-not-see', \SQLITE3_TEXT);
        $stmt->bindValue(':now', (new \DateTimeImmutable())->format(\DATE_ATOM), \SQLITE3_TEXT);
        $stmt->bindValue(':expires_at', $past, \SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->close();

        $this->assertNull($this->db->get('expired-key'));
        // But getRaw should still find it
        $raw = $this->db->getRaw('expired-key');
        $this->assertNotNull($raw);
    }

    public function testTransactionCommitsOnSuccess(): void
    {
        $result = $this->db->transaction(function (): string {
            $this->db->set('tx-a', '1');
            $this->db->set('tx-b', '2');
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame('1', $this->db->get('tx-a')->value);
        $this->assertSame('2', $this->db->get('tx-b')->value);
    }

    public function testTransactionRollsBackOnException(): void
    {
        try {
            $this->db->transaction(function (): void {
                $this->db->set('tx-rollback', 'should-not-exist');
                throw new \RuntimeException('intentional failure');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('intentional failure', $e->getMessage());
        }

        $this->assertNull($this->db->get('tx-rollback'));
    }

    private function rawHandleOf(Database $db): \SQLite3
    {
        $prop = new \ReflectionProperty(Database::class, 'db');
        $prop->setAccessible(true);
        /** @var \SQLite3 $sqlite */
        return $prop->getValue($db);
    }

    public function testReadOnlyTransactionDoesNotBlockAConcurrentWriter(): void
    {
        // kv-080: transaction() used to BEGIN IMMEDIATE unconditionally, so
        // even a read-only callback claimed the sole WAL writer slot and
        // every other process's writes stalled (then died at the 5 s busy
        // timeout). A DEFERRED read snapshot must let a writer commit
        // mid-callback.
        $path = $this->tmpDir . '/snapshot.db';
        $reader = new Database($path, 'snapshot');
        $writer = new Database($path, 'snapshot');
        $reader->set('seed', 'v');
        // Zero the writer's busy timeout: if the read branch wrongly held
        // the write lock, this connection errors immediately instead of
        // stalling the suite five seconds.
        $this->rawHandleOf($writer)->busyTimeout(0);

        $reader->transaction(function () use ($reader, $writer): void {
            $this->assertContains('seed', $reader->allKeys(), 'the snapshot sees pre-transaction data');
            $writer->set('from-writer', 'w');
            $this->assertNull(
                $reader->get('from-writer'),
                'the snapshot keeps ONE stable instant even after the concurrent commit (WAL read semantics)'
            );
        }, write: false);

        $this->assertNotNull($reader->get('from-writer'), 'after COMMIT the fresh write is visible');
        $reader->close();
        $writer->close();
    }

    public function testWriteTransactionHoldsTheWriterSlot(): void
    {
        // Opposite polarity (kv-080 belt): the default write branch must
        // still claim BEGIN IMMEDIATE — an embedded consumer transacting
        // writes needs the sole-writer promise, and a swapped parameter
        // spelling would silently lose it.
        $path = $this->tmpDir . '-write-slot.db';
        $tx = new Database($path, 'writeslot');
        $other = new Database($path, 'writeslot');
        $this->rawHandleOf($other)->busyTimeout(0);

        $this->expectException(\SQLite3Exception::class);
        $tx->transaction(function () use ($other): void {
            $other->set('intruder', 'v');
        });
    }

    public function testAllKeys(): void
    {
        $this->db->set('apple', 'a');
        $this->db->set('banana', 'b');
        $this->db->set('cherry', 'c');

        $keys = $this->db->allKeys();
        $this->assertSame(['apple', 'banana', 'cherry'], $keys);
    }

    public function testAllKeysExcludesExpired(): void
    {
        $this->db->set('good', 'v');

        $reflection = new \ReflectionClass($this->db);
        $prop = $reflection->getProperty('db');
        $prop->setAccessible(true);
        /** @var \SQLite3 $sqlite */
        $sqlite = $prop->getValue($this->db);

        $past = \time() - 3600;
        $stmt = $sqlite->prepare(
            'INSERT INTO entries (key, value, binary, created, modified, expires_at)
             VALUES (:key, :value, 0, :now, :now, :expires_at)'
        );
        $stmt->bindValue(':key', 'expired', \SQLITE3_TEXT);
        $stmt->bindValue(':value', 'v', \SQLITE3_TEXT);
        $stmt->bindValue(':now', (new \DateTimeImmutable())->format(\DATE_ATOM), \SQLITE3_TEXT);
        $stmt->bindValue(':expires_at', $past, \SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->close();

        $keys = $this->db->allKeys();
        $this->assertNotContains('expired', $keys);
        $this->assertContains('good', $keys);
    }

    // ─── Step 15 (e): nested transaction throws ──────────────────────────────

    public function testNestedTransactionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nested transactions are not supported');
        // The PHP-level guard prevents re-entrant transaction() calls.
        $this->db->transaction(function (): void {
            $this->db->transaction(function (): void {
                $this->db->set('nested', 'value');
            });
        });
    }

    // ─── close: idempotent database shutdown ─────────────────────────────────

    public function testCloseIsIdempotent(): void
    {
        // close() should not throw, even when called multiple times.
        $this->db->set('x', 'y');
        $this->db->close();
        $this->db->close(); // Second call should be no-op.

        // Observable proof the double-close stayed benign: the file on disk
        // is intact and re-openable, and the row written before close()
        // survived both calls.
        $reopen = new Database($this->tmpDir . '/test.db', 'test');
        $entry = $reopen->get('x');
        $this->assertNotNull($entry, 'row must survive a double close()');
        $this->assertSame('y', $entry->value);
        $reopen->close();
    }

    public function testCloseThenSetThrowsError(): void
    {
        $this->db->set('before-close', 'value');
        $this->db->close();

        // After close, set() throws an Error because the connection is closed.
        $this->expectException(\Error::class);
        $this->db->set('after-close', 'value');
    }

    // ─── file permissions (secrets store is 0600) ───────────────────────────

    public function testDatabaseFileIsTightenedToOwnerOnly(): void
    {
        $path = $this->tmpDir . '/perms.db';
        // Simulate the in-the-wild shape: umask 022 creation, world-readable.
        \touch($path);
        \chmod($path, 0o644);

        $db = new Database($path, 'perms');
        $db->set('x', 'y');

        \clearstatcache(true, $path);
        $perms = \fileperms($path);
        $this->assertSame(0, $perms & 0o077, 'group/other bits must be cleared — the file holds tokens in plaintext');
        $db->close();
    }

    public function testFreshDatabaseFileIsCreatedOwnerOnly(): void
    {
        $path = $this->tmpDir . '/fresh-perms.db';
        $db = new Database($path, 'fresh-perms');
        $db->set('x', 'y');
        \clearstatcache(true, $path);
        $perms = \fileperms($path);
        $this->assertSame(0, $perms & 0o077);
        $db->close();
    }

    // ─── Write-path TTL law (candy-kv-001) ──────────────────────────────────

    public function testTheDirectDatabaseDoorAppliesTheSameTtlLaw(): void
    {
        // Store::set guards, but Database is public too: a consumer wiring
        // it directly must hit the same horizon, not the epoch arithmetic
        // underneath (the mirror guard in Database::set).
        foreach ([0, -1, \PHP_INT_MAX] as $bad) {
            try {
                $this->db->set('poison', 'v', false, $bad);
                self::fail("Expected InvalidArgumentException for TTL {$bad}.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('between 1 and 999999999 seconds', $e->getMessage());
            }
            self::assertNull($this->db->get('poison'), 'A refused write must leave no row at all.');
        }
        $this->db->close();
    }

    // ─── Secrets-path entanglement and stray privilege bits (candy-kv-070) ──

    public function testSymlinkedDatabasePathIsRefusedBeforeAnyOpen(): void
    {
        $victim = $this->tmpDir . '/victim.db';
        (new Database($victim, 'victim'))->set('a', 'b');
        $link = $this->tmpDir . '/linked.db';
        \symlink($victim, $link);

        try {
            new Database($link, 'linked');
            $this->fail('A symlinked secret path was opened.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(
                'Refusing to use symlinked key-value store file',
                $e->getMessage()
            );
        }
        // The victim still holds only its own content: the refused open
        // wrote nothing through the link.
        $probe = new Database($victim, 'victim', false);
        $this->assertSame('b', $probe->get('a')?->value);
    }

    public function testStickyModeBitOnDatabaseFileIsTightened(): void
    {
        $path = $this->tmpDir . '/sticky.db';
        \touch($path);
        // Sticky, not setuid: the kernel strips setuid on the first write,
        // so a setuid probe would "pass" even with the loose 0o077-only
        // gate. Sticky bits survive writes — only an exact-mode gate can
        // clear them (kv-070).
        \chmod($path, 0o1600);

        $db = new Database($path, 'sticky');
        $db->set('k', 'v');

        $this->assertSame(0o600, \fileperms($path) & 0o7777);
        $db->close();
    }

    public function testSymlinkedWalSidecarIsRefused(): void
    {
        $path = $this->tmpDir . '/sidecarlink.db';
        $db = new Database($path, 'sidecarlink');
        $db->set('k', 'v');
        $db->close();
        \symlink($this->tmpDir . '/elsewhere', $path . '-wal');

        try {
            new Database($path, 'sidecarlink');
            $this->fail('A symlinked WAL sidecar was tolerated.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('-wal', $e->getMessage());
        } finally {
            \unlink($path . '-wal');
        }
    }
}
