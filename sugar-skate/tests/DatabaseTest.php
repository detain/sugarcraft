<?php

declare(strict_types=1);

namespace SugarCraft\Skate\Tests;

use SugarCraft\Skate\Database;
use SugarCraft\Skate\Entry;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    private string $tmpDir;
    private Database $db;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/skate-test-' . \uniqid();
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

        $past = (new \DateTimeImmutable('-1 hour'))->format(\DATE_ATOM);
        $stmt = $sqlite->prepare(
            'INSERT INTO entries (key, value, binary, created, modified, expires_at)
             VALUES (:key, :value, 0, :now, :now, :expires_at)'
        );
        $stmt->bindValue(':key', 'expired-key', \SQLITE3_TEXT);
        $stmt->bindValue(':value', 'should-not-see', \SQLITE3_TEXT);
        $stmt->bindValue(':now', (new \DateTimeImmutable())->format(\DATE_ATOM), \SQLITE3_TEXT);
        $stmt->bindValue(':expires_at', $past, \SQLITE3_TEXT);
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

        $past = (new \DateTimeImmutable('-1 hour'))->format(\DATE_ATOM);
        $stmt = $sqlite->prepare(
            'INSERT INTO entries (key, value, binary, created, modified, expires_at)
             VALUES (:key, :value, 0, :now, :now, :expires_at)'
        );
        $stmt->bindValue(':key', 'expired', \SQLITE3_TEXT);
        $stmt->bindValue(':value', 'v', \SQLITE3_TEXT);
        $stmt->bindValue(':now', (new \DateTimeImmutable())->format(\DATE_ATOM), \SQLITE3_TEXT);
        $stmt->bindValue(':expires_at', $past, \SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        $keys = $this->db->allKeys();
        $this->assertNotContains('expired', $keys);
        $this->assertContains('good', $keys);
    }
}
