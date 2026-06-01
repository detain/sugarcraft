<?php

declare(strict_types=1);

namespace SugarCraft\Skate\Tests;

use SugarCraft\Skate\Store;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    private string $tmpDir;
    private Store $store;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/skate-store-test-' . \uniqid();
        \mkdir($this->tmpDir, 0o700, true);
        $this->store = new Store($this->tmpDir, 'testdb');
    }

    protected function tearDown(): void
    {
        unset($this->store);
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        $files = \glob("{$dir}/*") ?: [];
        foreach ($files as $f) {
            \is_dir($f) ? $this->removeDir($f) : \unlink($f);
        }
        \rmdir($dir);
    }

    public function testSetAndGet(): void
    {
        $this->store->set('color', 'blue');
        $this->assertSame('blue', $this->store->get('color'));
    }

    public function testGetNonExistentReturnsEmptyString(): void
    {
        $this->assertSame('', $this->store->get('nope'));
    }

    public function testGetNonExistentWithFallback(): void
    {
        $this->assertSame('default', $this->store->get('nope', 'default'));
    }

    public function testEntryReturnsMetadata(): void
    {
        $this->store->set('x', 'y');
        $entry = $this->store->entry('x');
        $this->assertNotNull($entry);
        $this->assertSame('y', $entry->value);
        $this->assertSame('x', $entry->key);
    }

    public function testEntryNullForMissing(): void
    {
        $this->assertNull($this->store->entry('missing'));
    }

    public function testSetAndGetWithDbSuffix(): void
    {
        $this->store->set('token@passwords', 'hunter2');
        $this->assertSame('hunter2', $this->store->get('token@passwords'));
    }

    public function testCrossDatabaseIsolation(): void
    {
        $this->store->set('key@alpha', 'in-alpha');
        $this->store->set('key@beta', 'in-beta');
        $this->assertSame('in-alpha', $this->store->get('key@alpha'));
        $this->assertSame('in-beta', $this->store->get('key@beta'));
    }

    public function testDeleteSingle(): void
    {
        $this->store->set('delme', 'gone');
        $n = $this->store->delete('delme');
        $this->assertSame(1, $n);
        $this->assertSame('', $this->store->get('delme'));
    }

    public function testDeleteGlob(): void
    {
        $this->store->set('temp-1', 'v');
        $this->store->set('temp-2', 'v');
        $this->store->set('keep', 'v');

        $n = $this->store->delete('temp-*');
        $this->assertSame(2, $n);
        $this->assertSame('v', $this->store->get('keep'));
        $this->assertSame('', $this->store->get('temp-1'));
    }

    public function testListDefaultDb(): void
    {
        $this->store->set('a', '1');
        $this->store->set('b', '2');

        $keys = [];
        foreach ($this->store->list() as $e) {
            $keys[] = $e->key;
        }
        $this->assertContains('a', $keys);
        $this->assertContains('b', $keys);
    }

    public function testListFromSpecificDb(): void
    {
        $this->store->set('x@region1', '1');
        $this->store->set('y@region1', '2');

        $keys = [];
        foreach ($this->store->list(null, 'region1') as $e) {
            $keys[] = $e->key;
        }
        $this->assertCount(2, $keys);
    }

    public function testListReverse(): void
    {
        $this->store->set('z', '1');
        $this->store->set('a', '2');

        $keys = [];
        foreach ($this->store->list(null, null, reverse: true) as $e) {
            $keys[] = $e->key;
        }
        $this->assertSame(['z', 'a'], $keys);
    }

    public function testListKeysOnly(): void
    {
        $this->store->set('k1', 'v1');
        $this->store->set('k2', 'v2');

        $keys = [...$this->store->list(null, null, mode: 'keys')];
        $this->assertContains('k1', $keys);
        $this->assertContains('k2', $keys);
    }

    public function testListValuesOnly(): void
    {
        $this->store->set('k1', 'alpha');
        $this->store->set('k2', 'beta');

        $vals = [...$this->store->list(null, null, mode: 'values')];
        $this->assertContains('alpha', $vals);
        $this->assertContains('beta', $vals);
    }

    public function testSetFileAndGetFile(): void
    {
        $srcPath = $this->tmpDir . '/source.txt';
        $dstPath = $this->tmpDir . '/dest.txt';
        \file_put_contents($srcPath, "binary-like\x00\xff\xfe");

        $this->store->setFile('myfile', $srcPath);
        $ok = $this->store->getFile('myfile', $dstPath);

        $this->assertTrue($ok);
        $this->assertSame(\file_get_contents($srcPath), \file_get_contents($dstPath));
    }

    public function testListDatabases(): void
    {
        $this->store->set('x@alpha', '1');
        $this->store->set('x@beta', '2');

        $dbs = $this->store->listDatabases();
        $this->assertContains('alpha', $dbs);
        $this->assertContains('beta', $dbs);
        $this->assertContains('testdb', $dbs);
    }

    public function testDeleteDatabase(): void
    {
        $this->store->set('x@wipeout', 'v');
        $this->assertTrue($this->store->deleteDatabase('wipeout'));
        $this->assertNotContains('wipeout', $this->store->listDatabases());
    }

    public function testDataDir(): void
    {
        $this->assertSame($this->tmpDir, $this->store->dataDir());
    }

    public function testEntryIncludesMetadata(): void
    {
        $this->store->set('ts-test', 'val');
        $entry = $this->store->entry('ts-test');
        $this->assertNotNull($entry->createdAt);
        $this->assertNotNull($entry->modifiedAt);
    }

    public function testDeleteByKey(): void
    {
        $this->store->set('tmp-key', 'temp-value');
        $this->assertSame(1, $this->store->delete('tmp-key'));
        $this->assertSame('', $this->store->get('tmp-key'));
    }

    public function testDeleteNonExistentReturnsZero(): void
    {
        $this->assertSame(0, $this->store->delete('does-not-exist'));
    }

    public function testDeleteWithGlobPattern(): void
    {
        $this->store->set('user-alice', 'alice-data');
        $this->store->set('user-bob', 'bob-data');
        $this->store->set('user-carol', 'carol-data');
        $this->store->set('config-x', 'x');
        $deleted = $this->store->delete('user-*');
        $this->assertSame(3, $deleted);
        $this->assertSame('', $this->store->get('user-alice'));
        $this->assertSame('', $this->store->get('user-bob'));
        $this->assertSame('x', $this->store->get('config-x'));
    }

    public function testDeleteDatabaseNonExistentReturnsFalse(): void
    {
        $this->assertFalse($this->store->deleteDatabase('nonexistent-db'));
    }

    public function testSetBinaryAndRetrieve(): void
    {
        $this->store->set('bin', "\x00\xff\xfe\xfd", true);
        $entry = $this->store->entry('bin');
        $this->assertTrue($entry->binary);
        $this->assertSame("\x00\xff\xfe\xfd", $entry->rawValue());
    }

    public function testSetBinaryNonBase64Decoded(): void
    {
        $this->store->set('txt', 'plain-text', false);
        $entry = $this->store->entry('txt');
        $this->assertFalse($entry->binary);
        $this->assertSame('plain-text', $entry->value);
    }

    public function testSetWithTtl(): void
    {
        $this->store->setWithTtl('ttl-key', 'ttl-value', 3600);
        $entry = $this->store->entry('ttl-key');
        $this->assertNotNull($entry);
        $this->assertNotNull($entry->expiresAt);
        $this->assertFalse($entry->isExpired());
    }

    public function testSetWithTtlZeroIsNoOp(): void
    {
        $this->store->setWithTtl('nope', 'val', 0);
        $this->assertSame('', $this->store->get('nope'));
    }

    public function testSetWithTtlNegativeIsNoOp(): void
    {
        $this->store->setWithTtl('neg', 'val', -10);
        $this->assertSame('', $this->store->get('neg'));
    }

    public function testSetPassesTtlToDatabase(): void
    {
        $e = $this->store->set('t', 'v', false, 7200);
        $this->assertNotNull($e->expiresAt);
    }

    public function testSetWithTtlOnExistingKeyOverwrites(): void
    {
        $this->store->set('overwrite-ttl', 'v1');
        $this->store->setWithTtl('overwrite-ttl', 'v2', 60);
        $this->assertSame('v2', $this->store->get('overwrite-ttl'));
        $entry = $this->store->entry('overwrite-ttl');
        $this->assertNotNull($entry->expiresAt);
    }

    public function testEntryReturnsNullForExpiredKey(): void
    {
        $tmpDir = $this->tmpDir;
        $dbPath = $tmpDir . '/testdb.db';
        $db = new \SugarCraft\Skate\Database($dbPath, 'testdb');

        $reflection = new \ReflectionClass($db);
        $prop = $reflection->getProperty('db');
        $prop->setAccessible(true);
        /** @var \SQLite3 $sqlite */
        $sqlite = $prop->getValue($db);

        $past = (new \DateTimeImmutable('-1 hour'))->format(\DATE_ATOM);
        $stmt = $sqlite->prepare(
            'INSERT INTO entries (key, value, binary, created, modified, expires_at)
             VALUES (:key, :value, 0, :now, :now, :expires_at)'
        );
        $stmt->bindValue(':key', 'old-entry', \SQLITE3_TEXT);
        $stmt->bindValue(':value', 'old-value', \SQLITE3_TEXT);
        $stmt->bindValue(':now', (new \DateTimeImmutable())->format(\DATE_ATOM), \SQLITE3_TEXT);
        $stmt->bindValue(':expires_at', $past, \SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        $this->assertNull($this->store->entry('old-entry'));
    }

    public function testFuzzyFilterReturnsMatchingEntries(): void
    {
        $this->store->set('production-api', 'v1');
        $this->store->set('staging-api', 'v2');
        $this->store->set('dev-api', 'v3');
        $this->store->set('database-main', 'v4');

        $results = $this->store->fuzzyFilter('prod');
        $this->assertNotEmpty($results);
        $this->assertSame('production-api', $results[0]->key);
    }

    public function testFuzzyFilterEmptyQueryReturnsEmptyArray(): void
    {
        $this->store->set('key1', 'v1');
        $this->assertSame([], $this->store->fuzzyFilter(''));
    }

    public function testFuzzyFilterRanksByScore(): void
    {
        $this->store->set('production-api', 'v1');
        $this->store->set('provisioning', 'v2');
        $this->store->set('dev-api', 'v3');

        $results = $this->store->fuzzyFilter('pro');
        $keys = array_map(static fn($e) => $e->key, $results);
        // "production-api" starts with "pro" and should rank higher
        $this->assertContains('production-api', $keys);
    }

    public function testFuzzyFilterNoMatchReturnsEmptyArray(): void
    {
        $this->store->set('alpha', 'v1');
        $this->store->set('beta', 'v2');
        $this->assertSame([], $this->store->fuzzyFilter('xyz'));
    }
}
