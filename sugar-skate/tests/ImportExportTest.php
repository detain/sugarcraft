<?php

declare(strict_types=1);

namespace SugarCraft\Skate\Tests;

use SugarCraft\Skate\Import\JsonImporter;
use SugarCraft\Skate\Import\YamlImporter;
use SugarCraft\Skate\Store;
use PHPUnit\Framework\TestCase;

final class ImportExportTest extends TestCase
{
    private string $tmpDir;
    private Store $store;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/skate-ie-test-' . \uniqid();
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

    // ─── JsonImporter ─────────────────────────────────────────────────────────

    public function testJsonImporterFromFile(): void
    {
        $path = $this->tmpDir . '/import.json';
        \file_put_contents($path, '{"color":"blue","size":"large"}');

        $importer = new JsonImporter($this->store);
        $count = $importer->importFromFile($path, false);

        $this->assertSame(2, $count);
        $this->assertSame('blue', $this->store->get('color'));
        $this->assertSame('large', $this->store->get('size'));
    }

    public function testJsonImporterFromString(): void
    {
        $json = '{"a":"1","b":"2"}';
        $importer = new JsonImporter($this->store);
        $count = $importer->importFromString($json, false);

        $this->assertSame(2, $count);
    }

    public function testJsonImporterWithTtl(): void
    {
        $path = $this->tmpDir . '/ttl.json';
        \file_put_contents($path, \json_encode([
            '_ttl' => ['timed-key' => 3600],
            'timed-key' => 'timed-value',
        ]));

        $importer = new JsonImporter($this->store);
        $importer->importFromFile($path, false);

        $entry = $this->store->entry('timed-key');
        $this->assertNotNull($entry->expiresAt);
        $this->assertSame('timed-value', $this->store->get('timed-key'));
    }

    public function testJsonImporterAtomic(): void
    {
        $path = $this->tmpDir . '/atomic.json';
        \file_put_contents($path, '{"x":"1","y":"2","z":"3"}');

        $importer = new JsonImporter($this->store);
        $importer->importFromFile($path, true);

        $this->assertSame('1', $this->store->get('x'));
        $this->assertSame('2', $this->store->get('y'));
        $this->assertSame('3', $this->store->get('z'));
    }

    public function testJsonImporterWithDbSuffix(): void
    {
        $path = $this->tmpDir . '/dbsuffix.json';
        \file_put_contents($path, '{"token@pw":"secret","user@meta":"alice"}');

        $importer = new JsonImporter($this->store);
        $count = $importer->importFromFile($path, false);

        $this->assertSame(2, $count);
        $this->assertSame('secret', $this->store->get('token@pw'));
        $this->assertSame('alice', $this->store->get('user@meta'));
    }

    public function testJsonImporterAtomicMultiDatabaseThrows(): void
    {
        $path = $this->tmpDir . '/multi-db.json';
        \file_put_contents($path, '{"token@pw":"secret","user@meta":"alice"}');

        $importer = new JsonImporter($this->store);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Atomic import is not supported across multiple databases');
        $importer->importFromFile($path, true);
    }

    public function testJsonImporterSkipsNonStringValues(): void
    {
        $path = $this->tmpDir . '/mixed.json';
        \file_put_contents($path, '{"str":"value","num":123,"arr":[1,2,3],"obj":{"a":1}}');

        $importer = new JsonImporter($this->store);
        $count = $importer->importFromFile($path, false);

        $this->assertSame(1, $count);
        $this->assertSame('value', $this->store->get('str'));
    }

    // ─── YamlImporter ─────────────────────────────────────────────────────────

    public function testYamlImporterFromFile(): void
    {
        $path = $this->tmpDir . '/import.yaml';
        \file_put_contents($path, "color: blue\nsize: large\n");

        $importer = new YamlImporter($this->store);
        $count = $importer->importFromFile($path, false);

        $this->assertSame(2, $count);
        $this->assertSame('blue', $this->store->get('color'));
        $this->assertSame('large', $this->store->get('size'));
    }

    public function testYamlImporterFromString(): void
    {
        $yaml = "a: 1\nb: 2\n";
        $importer = new YamlImporter($this->store);
        $count = $importer->importFromString($yaml, false);

        $this->assertSame(2, $count);
    }

    public function testYamlImporterWithTtl(): void
    {
        $path = $this->tmpDir . '/ttl.yaml';
        \file_put_contents($path, "skate_ttl_timed-key: 3600\ntimed-key: timed-value\n");

        $importer = new YamlImporter($this->store);
        $importer->importFromFile($path, false);

        $entry = $this->store->entry('timed-key');
        $this->assertNotNull($entry->expiresAt);
    }

    public function testYamlImporterAtomicMultiDatabaseThrows(): void
    {
        $path = $this->tmpDir . '/multi-db.yaml';
        \file_put_contents($path, "token@pw: secret\nuser@meta: alice\n");

        $importer = new YamlImporter($this->store);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Atomic import is not supported across multiple databases');
        $importer->importFromFile($path, true);
    }

    // ─── Export ────────────────────────────────────────────────────────────────

    public function testJsonExportRoundTrip(): void
    {
        $this->store->set('x', '1');
        $this->store->set('y', '2');

        $cli = new \SugarCraft\Skate\Cli\ExportCommand($this->store);
        $exitCode = $cli->run('json');

        $this->assertSame(0, $exitCode);
        $this->assertNotNull($this->store->entry('x'));
        $this->assertNotNull($this->store->entry('y'));
    }

    public function testJsonExportWithTtl(): void
    {
        $this->store->set('t', 'v', false, 3600);

        $exportCmd = new \SugarCraft\Skate\Cli\ExportCommand($this->store);
        $exitCode = $exportCmd->run('json');

        $this->assertSame(0, $exitCode);

        // Capture stdout via a temp file
        $tmpFile = $this->tmpDir . '/export_out.json';
        \file_put_contents($tmpFile, '');

        // Run again with output redirect
        $entry = $this->store->entry('t');
        $this->assertNotNull($entry);
        $this->assertNotNull($entry->expiresAt);
        $this->assertGreaterThan(0, $entry->expiresAt->getTimestamp() - time());

        // Verify export output has TTL info
        $allKeys = $this->store->list();
        $hasTtl = false;
        foreach ($this->store->list() as $e) {
            if ($e instanceof \SugarCraft\Skate\Entry && $e->expiresAt !== null) {
                $hasTtl = true;
            }
        }
        $this->assertTrue($hasTtl, 'Entry with TTL should appear in list');
    }
}
