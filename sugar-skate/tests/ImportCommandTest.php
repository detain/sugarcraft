<?php

declare(strict_types=1);

namespace SugarCraft\Skate\Tests;

use SugarCraft\Skate\Cli\ImportCommand;
use SugarCraft\Skate\Store;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ImportCommand CLI handler.
 *
 * Verifies: run() exit codes, file/stdin import paths, error handling.
 */
final class ImportCommandTest extends TestCase
{
    private string $tmpDir;
    private Store $store;
    private ImportCommand $cmd;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/skate-import-cmd-' . \uniqid();
        \mkdir($this->tmpDir, 0o700, true);
        $this->store = new Store($this->tmpDir, 'testdb');
        $this->cmd = new ImportCommand($this->store);
    }

    protected function tearDown(): void
    {
        unset($this->store, $this->cmd);
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

    // ─── JSON file import ───────────────────────────────────────────────────

    public function testRunJsonFromFileReturnsZeroOnSuccess(): void
    {
        $path = $this->tmpDir . '/data.json';
        \file_put_contents($path, '{"json-key":"json-value"}');

        $exit = $this->cmd->run('json', $path, false);

        $this->assertSame(0, $exit);
        $this->assertSame('json-value', $this->store->get('json-key'));
    }

    public function testRunYamlFromFileReturnsZeroOnSuccess(): void
    {
        $path = $this->tmpDir . '/data.yaml';
        \file_put_contents($path, "yaml-key: yaml-value\n");

        $exit = $this->cmd->run('yaml', $path, false);

        $this->assertSame(0, $exit);
        $this->assertSame('yaml-value', $this->store->get('yaml-key'));
    }

    public function testRunYmlAliasFromFileReturnsZeroOnSuccess(): void
    {
        $path = $this->tmpDir . '/data.yml';
        \file_put_contents($path, "yml-key: yml-value\n");

        $exit = $this->cmd->run('yml', $path, false);

        $this->assertSame(0, $exit);
        $this->assertSame('yml-value', $this->store->get('yml-key'));
    }

    public function testRunJsonFromFileWithAtomicTrue(): void
    {
        $path = $this->tmpDir . '/atomic.json';
        \file_put_contents($path, '{"a":"1","b":"2"}');

        $exit = $this->cmd->run('json', $path, true);

        $this->assertSame(0, $exit);
        $this->assertSame('1', $this->store->get('a'));
        $this->assertSame('2', $this->store->get('b'));
    }

    public function testRunYamlFromFileWithAtomicTrue(): void
    {
        $path = $this->tmpDir . '/atomic.yaml';
        \file_put_contents($path, "x: 1\ny: 2\n");

        $exit = $this->cmd->run('yaml', $path, true);

        $this->assertSame(0, $exit);
        $this->assertSame('1', $this->store->get('x'));
        $this->assertSame('2', $this->store->get('y'));
    }

    // ─── stdin import ────────────────────────────────────────────────────────
    // The '-' path calls file_get_contents('php://stdin'). We test the code
    // path does not throw when stdin is empty/missing (returns 1).

    public function testRunStdinPathReturnsOneWhenNoPipedData(): void
    {
        // When no data is piped, file_get_contents returns false → exit 1
        $exit = $this->cmd->run('json', '-', false);
        $this->assertSame(1, $exit);
    }

    public function testRunDevStdinPathReturnsOneWhenNoData(): void
    {
        // /dev/stdin path when there's no piped data should return 1
        $exit = $this->cmd->run('json', '/dev/stdin', false);
        $this->assertSame(1, $exit);
    }

    // ─── error cases ─────────────────────────────────────────────────────────

    public function testRunUnknownFormatReturnsOne(): void
    {
        $path = $this->tmpDir . '/data.csv';
        \file_put_contents($path, 'col1,col2');

        $exit = $this->cmd->run('csv', $path, false);

        $this->assertSame(1, $exit);
    }

    public function testRunNonexistentFileReturnsOne(): void
    {
        $exit = $this->cmd->run('json', '/nonexistent/path/data.json', false);

        $this->assertSame(1, $exit);
    }

    public function testRunUnknownFormatLeavesStoreUnchanged(): void
    {
        $this->store->set('existing', 'value');
        $this->cmd->run('csv', '/fake/path.csv', false);

        $this->assertSame('value', $this->store->get('existing'));
    }

    public function testRunNonexistentFileLeavesStoreUnchanged(): void
    {
        $this->store->set('existing', 'value');
        $this->cmd->run('json', '/nonexistent/data.json', false);

        $this->assertSame('value', $this->store->get('existing'));
    }

    public function testRunMalformedJsonReturnsOne(): void
    {
        $path = $this->tmpDir . '/bad.json';
        \file_put_contents($path, '{not valid json');

        $exit = $this->cmd->run('json', $path, false);

        $this->assertSame(1, $exit);
    }

    public function testRunMalformedYamlReturnsOne(): void
    {
        $path = $this->tmpDir . '/bad.yaml';
        \file_put_contents($path, "  this is not: [valid yaml\n    that has: bad indentation");

        $exit = $this->cmd->run('yaml', $path, false);

        $this->assertSame(1, $exit);
    }

    // ─── atomic multi-database rejection ───────────────────────────────────

    public function testRunAtomicMultiDatabaseJsonReturnsOne(): void
    {
        $path = $this->tmpDir . '/multi-db.json';
        \file_put_contents($path, '{"token@pw":"s","user@meta":"a"}');

        $exit = $this->cmd->run('json', $path, true);

        $this->assertSame(1, $exit);
    }

    public function testRunAtomicMultiDatabaseYamlReturnsOne(): void
    {
        $path = $this->tmpDir . '/multi-db.yaml';
        \file_put_contents($path, "token@pw: s\nuser@meta: a\n");

        $exit = $this->cmd->run('yaml', $path, true);

        $this->assertSame(1, $exit);
    }

    // ─── YamlImporter delegates to same-store constructor ──────────────────

    public function testRunYmlWithDbSuffix(): void
    {
        $path = $this->tmpDir . '/dbsuffix.yml';
        \file_put_contents($path, "token@pw: secret\nuser@meta: alice\n");

        $exit = $this->cmd->run('yml', $path, false);

        $this->assertSame(0, $exit);
        $this->assertSame('secret', $this->store->get('token@pw'));
        $this->assertSame('alice', $this->store->get('user@meta'));
    }

    public function testRunJsonWithDbSuffix(): void
    {
        $path = $this->tmpDir . '/dbsuffix.json';
        \file_put_contents($path, '{"token@pw":"secret","user@meta":"alice"}');

        $exit = $this->cmd->run('json', $path, false);

        $this->assertSame(0, $exit);
        $this->assertSame('secret', $this->store->get('token@pw'));
        $this->assertSame('alice', $this->store->get('user@meta'));
    }
}
