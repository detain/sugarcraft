<?php

declare(strict_types=1);

namespace CandyCore\Skate\Tests;

use CandyCore\Skate\Database;
use CandyCore\Skate\Entry;
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
}
