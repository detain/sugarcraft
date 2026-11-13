<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use SugarCraft\Kv\Cli\ImportCommand;
use SugarCraft\Kv\Store;
use PHPUnit\Framework\TestCase;
use SugarCraft\Kv\Tests\Support\SpawnBounded;

/**
 * Tests for ImportCommand CLI handler.
 *
 * Verifies: run() exit codes, file/stdin import paths, error handling.
 */
final class ImportCommandTest extends TestCase
{
    use SpawnBounded;

    private string $tmpDir;
    private string $childConfigHome;
    private Store $store;
    private ImportCommand $cmd;
    /** @var resource */
    private $outStream;
    /** @var resource */
    private $errStream;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/candy-kv-import-cmd-' . \uniqid();
        \mkdir($this->tmpDir, 0o700, true);
        $this->childConfigHome = $this->tmpDir . '/xdg';
        \mkdir($this->childConfigHome, 0o700, true);
        $this->store = new Store($this->tmpDir, 'testdb');
        // candy-kv-081: the in-process legs previously wrote through the bare
        // STDOUT/STDERR constants and leaked lines onto the runner's real
        // output — inject memory sinks instead, and assert into them so the
        // success/failure wording is pinned, not just the exit codes.
        $this->outStream = \fopen('php://memory', 'r+');
        $this->errStream = \fopen('php://memory', 'r+');
        $this->cmd = new ImportCommand($this->store, $this->outStream, $this->errStream);
    }

    protected function tearDown(): void
    {
        unset($this->store, $this->cmd);
        \fclose($this->outStream);
        \fclose($this->errStream);
        $this->removeDir($this->tmpDir);
    }

    /**
     * @param resource $stream
     */
    private function captured($stream): string
    {
        \rewind($stream);

        return (string) \stream_get_contents($stream);
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
        $this->assertSame(\SugarCraft\Kv\Lang::t('cli.imported_one') . "\n", $this->captured($this->outStream), 'the success line is the command\'s only stdout surface');
        $this->assertSame('', $this->captured($this->errStream), 'a clean import must write no diagnostic');
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

    // ─── stdin import — deliberately NOT covered here ───────────────────────
    // The '-' path calls file_get_contents('php://stdin') IN-PROCESS. Run
    // inside the PHPUnit runner that blocks forever whenever the suite's
    // stdin is a terminal (the two former cases here hung >300s under a PTY —
    // audit #2), and their comment claimed the wrong mechanism: an empty pipe
    // makes file_get_contents return '' (never false), so exit 1 actually
    // arrives via the JSON-decode exception, not the dead `$json === false`
    // branch. stdin coverage lives in CliSmokeTest, which drives the real
    // binary as a child process with stdin closed/piped.

    // ─── error cases ─────────────────────────────────────────────────────────

    public function testRunUnknownFormatReturnsTwo(): void
    {
        // Usage-class error: an unsupported format is caller intent, not an
        // import failure — exit 2, and the refusal lists every accepted spelling.
        $path = $this->tmpDir . '/data.csv';
        \file_put_contents($path, 'col1,col2');

        $exit = $this->cmd->run('csv', $path, false);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString("Unknown import format: csv. Use 'json', 'yaml' or 'yml'.", $this->captured($this->errStream));
    }

    public function testRunNonexistentFileReturnsOne(): void
    {
        $exit = $this->cmd->run('json', '/nonexistent/path/data.json', false);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('File not found: /nonexistent/path/data.json', $this->captured($this->errStream));
    }

    /**
     * kv-019: stream-wrapper URLs are refused by scheme name BEFORE anything
     * touches the filesystem — file_exists() on a phar:// URL already
     * deserializes the archive's metadata inside this process.
     */
    public function testStreamWrapperImportPathsAreRefusedNamingScheme(): void
    {
        foreach (['phar://x.phar/data.json', 'data://text/plain;base64,e30=', 'php://filter/read=convert.base64-encode/resource=/etc/passwd', 'glob://*'] as $url) {
            $scheme = \strstr($url, '://', true) . '://';
            $exit = $this->cmd->run('json', $url, false);

            $this->assertSame(1, $exit, $url . ' must be refused');
            $stderr = $this->captured($this->errStream);
            $this->assertStringContainsString('stream wrapper', $stderr, $url);
            $this->assertStringContainsString($scheme, $stderr, $url . ' refusal must name the scheme');
        }
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
        $this->assertStringContainsString('Import failed:', $this->captured($this->errStream), 'the malformed-document leg must answer on the stderr sink, not the runner');
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

    // ─── sanitize-on-egress (M5): diagnostics never carry raw control bytes ──

    public function testImportFailureDiagnosticStripsEscapesFromFileChosenKeys(): void
    {
        // The refusal message embeds the ENTRY NAME chosen inside the import
        // file (attacker bytes). Child process: stderr is the real sink and can
        // only be observed across a process boundary (STDERR is a constant).
        $path = $this->tmpDir . '/poison.json';
        \file_put_contents($path, \json_encode(["\x1b[31mRED" => ['nested' => 1]]));

        $res = $this->runChild(['import', 'json', $path]);

        $this->assertSame(1, $res['exit'], $res['stderr']);
        $this->assertStringContainsString('Import failed:', $res['stderr']);
        $this->assertStringContainsString('array value', $res['stderr'], 'the diagnostic must stay informative');
        // kv-005: the scrubber deletes whole ESC-introduced sequences, so even
        // the printable residue ([31m) no longer rides a stderr diagnostic.
        $this->assertStringContainsString('RED', $res['stderr'], 'the entry name must stay visible');
        $this->assertStringNotContainsString('[31m', $res['stderr'], 'sequence residue must not survive either');
        $this->assertStringNotContainsString("\x1b", $res['stderr'], 'raw ESC must never ride a stderr diagnostic');
    }

    public function testFileNotFoundPathStripsEscapes(): void
    {
        // The path is caller-chosen argv bytes; the miss line must not echo them raw.
        $res = $this->runChild(['import', 'json', $this->tmpDir . "/\x1b[7mghost.json"]);

        $this->assertSame(1, $res['exit'], $res['stderr']);
        $this->assertStringContainsString('File not found:', $res['stderr']);
        $this->assertStringNotContainsString("\x1b", $res['stderr'], 'raw ESC must never ride a stderr diagnostic');
    }

    public function testExportFailureDiagnosticStripsEscapesFromStoreKeys(): void
    {
        // A poisoned store key (ESC bytes + reserved YAML prefix) surfaces
        // through the 'Export failed:' wrapper, which names the key verbatim.
        $set = $this->runChild(['set', "skate_ttl_\x1b[31mX", 'v']);
        $this->assertSame(0, $set['exit'], $set['stderr']);

        $res = $this->runChild(['export', 'yml']);

        $this->assertSame(1, $res['exit'], $res['stderr']);
        $this->assertStringContainsString('Export failed:', $res['stderr']);
        // kv-005: whole ESC-introduced sequences are deleted, residue included.
        $this->assertStringContainsString('X', $res['stderr'], 'the key must stay visible');
        $this->assertStringNotContainsString('[31m', $res['stderr'], 'sequence residue must not survive either');
        $this->assertStringNotContainsString("\x1b", $res['stderr'], 'raw ESC must never ride a stderr diagnostic');
    }

    /**
     * Run `php bin/candy-kv <args...>` in a child process with the per-test
     * XDG_CONFIG_HOME, so the binary's own store isolation is under our control.
     *
     * @param list<string> $args
     * @return array{stdout: string, stderr: string, exit: int, timedOut: bool}
     */
    private function runChild(array $args): array
    {
        // argv-fed commands must never wait on a terminal: spawnKv closes stdin.
        return self::spawnKv($args, $this->childConfigHome);
    }

    // ─── candy-kv-051: the success line reports clobbering, not just counts ──

    public function testOverwritingImportSaysSoOnTheSuccessLine(): void
    {
        $path = $this->tmpDir . '/dump.json';
        \file_put_contents($path, '{"a":"1","b":"2"}');
        $this->assertSame(0, $this->cmd->run('json', $path, false));

        \file_put_contents($path, '{"a":"9","b":"8"}');
        $mark = \ftell($this->outStream); // slice the second answer only
        $this->assertSame(0, $this->cmd->run('json', $path, false));

        $expected = \SugarCraft\Kv\Lang::t('cli.imported_n', ['count' => 2])
            . ' ' . \SugarCraft\Kv\Lang::t('cli.overwrote_n', ['count' => 2]) . "\n";
        $this->assertSame($expected, $this->capturedFrom($mark));
    }

    public function testSingularClobberReadsAsOneEntry(): void
    {
        \file_put_contents($this->tmpDir . '/one.json', '{"k":"first"}');
        $this->cmd->run('json', $this->tmpDir . '/one.json', false);
        \file_put_contents($this->tmpDir . '/one.json', '{"k":"second"}');
        $mark = \ftell($this->outStream);
        $this->cmd->run('json', $this->tmpDir . '/one.json', false);

        $expected = \SugarCraft\Kv\Lang::t('cli.imported_one')
            . ' ' . \SugarCraft\Kv\Lang::t('cli.overwrote_one') . "\n";
        $this->assertSame($expected, $this->capturedFrom($mark));
    }

    public function testSkipPolicyKeepsThePlainSuccessLine(): void
    {
        // skipped == 1, overwritten == 0: the sentence would be a LIE if it
        // appeared — silence on the second half is the correct report.
        \file_put_contents($this->tmpDir . '/live.json', '{"k":"stored"}');
        $this->cmd->run('json', $this->tmpDir . '/live.json', false);
        \file_put_contents($this->tmpDir . '/live.json', '{"k":"ignored"}');
        $mark = \ftell($this->outStream);
        $this->assertSame(0, $this->cmd->run('json', $this->tmpDir . '/live.json', false, 'skip'));

        $this->assertSame(
            \SugarCraft\Kv\Lang::t('cli.imported_n', ['count' => 0]) . "\n",
            $this->capturedFrom($mark),
        );
        $this->assertSame('stored', $this->store->get('k'));
    }

    public function testFailPolicySurfacesAsTheImportFailedRefusal(): void
    {
        \file_put_contents($this->tmpDir . '/fail.json', '{"dup":"live"}');
        $this->cmd->run('json', $this->tmpDir . '/fail.json', false);
        \file_put_contents($this->tmpDir . '/fail.json', '{"dup":"clash"}');
        $mark = \ftell($this->errStream);

        $this->assertSame(1, $this->cmd->run('json', $this->tmpDir . '/fail.json', false, 'fail'));
        $err = $this->capturedFrom($mark, $this->errStream);
        $this->assertStringStartsWith('Import failed: ', $err);
        $this->assertStringContainsString("'dup'", $err);
        $this->assertSame('live', $this->store->get('dup'));
    }

    public function testUnknownPolicyIsRefusedAsFailureNotSilentReplace(): void
    {
        \file_put_contents($this->tmpDir . '/bad.json', '{"k":"v"}');
        // The argv grammar guards this first; the library door must too
        // (ImportCommit owns the check), and the command reports rc 1.
        $this->assertSame(1, $this->cmd->run('json', $this->tmpDir . '/bad.json', false, 'clobber'));
        $this->assertStringContainsString('Unknown import policy', $this->captured($this->errStream));
    }

    /**
     * Slice everything written to a memory sink since an ftell mark — the
     * project stream law (ftell/fseek/stream_get_contents, never rewind-
     * and-hope) so two-phase tests read only the second phase's bytes.
     *
     * @param resource $stream
     */
    private function capturedFrom(int $mark, $stream = null): string
    {
        $stream ??= $this->outStream;
        \fseek($stream, $mark);
        $text = \stream_get_contents($stream);
        \fseek($stream, 0, \SEEK_END); // next write appends, never overwrites
        return $text === false ? '' : $text;
    }
}
