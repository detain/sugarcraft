<?php

declare(strict_types=1);

namespace CandyCore\Skate\Tests;

use CandyCore\Skate\Store;
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
}
