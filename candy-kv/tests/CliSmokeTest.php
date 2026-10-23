<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke test that runs the real `bin/candy-kv` binary via proc_open.
 *
 * This is the regression net for the two "fatal on ship" bugs (W15): the CLI
 * shipped calling a non-existent `Store::sanitizeForTty()` (crashed every
 * `list`) and a private `Store::suggestSimilar()` (crashed every missing-key
 * `get`). Neither had any test that actually executed the binary, so both
 * fatals sailed through. These tests execute the binary and assert it never
 * fatals.
 *
 * The CLI has no --data-dir flag; it resolves its data directory from
 * XDG_CONFIG_HOME (falling back to HOME/.config), so each test isolates state
 * by pointing XDG_CONFIG_HOME at a throwaway temp directory.
 */
final class CliSmokeTest extends TestCase
{
    private string $configHome;

    protected function setUp(): void
    {
        if (!\extension_loaded('sqlite3')) {
            $this->markTestSkipped('ext-sqlite3 is required to run the candy-kv CLI.');
        }
        $this->configHome = \sys_get_temp_dir() . '/candy-kv-cli-smoke-' . \uniqid('', true);
        \mkdir($this->configHome, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->configHome);
    }

    public function testSetGetListRoundTrip(): void
    {
        $set = $this->runSkate(['set', 'foo', 'bar']);
        $this->assertSame(0, $set['exit'], 'set should exit 0');
        $this->assertNoFatal($set);

        $get = $this->runSkate(['get', 'foo']);
        $this->assertSame(0, $get['exit'], 'get of an existing key should exit 0');
        $this->assertSame('bar', $get['stdout']);
        $this->assertNoFatal($get);

        // `list` used to fatal on a non-existent Store::sanitizeForTty().
        $list = $this->runSkate(['list']);
        $this->assertSame(0, $list['exit'], 'list should exit 0');
        $this->assertNoFatal($list);
        $this->assertStringContainsString('foo', $list['stdout']);
        $this->assertStringContainsString('bar', $list['stdout']);
    }

    public function testGetMissingKeyExitsNonZeroWithoutFatal(): void
    {
        // `get` on a miss used to fatal on the private Store::suggestSimilar().
        $get = $this->runSkate(['get', 'no-such-key-xyz']);
        $this->assertSame(1, $get['exit'], 'missing key should exit 1');
        $this->assertSame('', $get['stdout'], 'missing key must print nothing to stdout');
        $this->assertNoFatal($get);
    }

    public function testGetMissingKeyEmitsSaneSuggestion(): void
    {
        $this->runSkate(['set', 'color', 'blue']);

        // "colar" is one edit from "color" — get() itself emits the suggestion.
        $get = $this->runSkate(['get', 'colar']);
        $this->assertSame(1, $get['exit']);
        $this->assertNoFatal($get);
        $this->assertStringContainsString("did you mean 'color'", $get['stderr']);
    }

    public function testListRendersEscValueSafely(): void
    {
        // A stored value carrying a terminal escape sequence must not reach the
        // TTY as raw ESC bytes when listed — this pins the sanitizeForTty fix.
        $poison = "\x1b[31mRED\x1b[0m";
        $this->runSkate(['set', 'danger', $poison]);

        $list = $this->runSkate(['list']);
        $this->assertSame(0, $list['exit']);
        $this->assertNoFatal($list);
        $this->assertStringContainsString('danger', $list['stdout']);
        $this->assertStringNotContainsString("\x1b", $list['stdout'], 'raw ESC must never appear in list output');
    }

    // ─── STDIN paths (child process only — never in-process) ────────────────
    //
    // `import ... -` reads php://stdin. An in-process test would call
    // file_get_contents('php://stdin') from the PHPUnit runner itself, which
    // BLOCKS FOREVER whenever the suite runs on a terminal (audit #2: the old
    // ImportCommandTest stdin cases hung >300s under a PTY and only survived
    // on pipes). Every stdin-touching case therefore runs the real binary as
    // a child with stdin under the test's control.

    public function testImportStdinEmptyFailsThroughDecodeNotReadFailure(): void
    {
        // Empty stdin: file_get_contents('php://stdin') returns '' (a string,
        // never false), so exit 1 arrives via the JSON-decode exception in the
        // catch block — NOT the dead `$json === false` branch. This pins the
        // actual mechanism (audit #2 side-note).
        $res = $this->runSkateFeeding(['import', 'json', '-'], null);
        $this->assertSame(1, $res['exit']);
        $this->assertStringContainsString('Import failed', $res['stderr']);
        $this->assertStringNotContainsString(
            'Failed to read from STDIN',
            $res['stderr'],
            'the false-return branch is unreachable on an empty pipe — the failure must surface as a decode error'
        );
    }

    public function testImportDevStdinEmptyFails(): void
    {
        $res = $this->runSkateFeeding(['import', 'json', '/dev/stdin'], null);
        $this->assertSame(1, $res['exit']);
        $this->assertStringContainsString('Import failed', $res['stderr']);
    }

    public function testImportStdinPipedJsonSucceeds(): void
    {
        $res = $this->runSkateFeeding(['import', 'json', '-'], '{"i":"1","j":"2"}');
        $this->assertSame(0, $res['exit'], $res['stderr']);
        $this->assertStringContainsString('Imported 2 entries.', $res['stdout']);

        $get = $this->runSkate(['get', 'i']);
        $this->assertSame('1', $get['stdout']);
    }

    public function testSetFromStdinStoresRawBytesIncludingTrailingNewline(): void
    {
        // Declared product decision (audit #9): piped values are stored
        // VERBATIM — full read, no trim — matching upstream
        // `io.ReadAll(cmd.InOrStdin())` so `cat file | candy-kv set key` is
        // byte-faithful. `echo` therefore stores its trailing newline.
        $res = $this->runSkateFeeding(['set', 'pip'], "line\n");
        $this->assertSame(0, $res['exit'], $res['stderr']);

        $get = $this->runSkate(['get', 'pip']);
        $this->assertSame("line\n", $get['stdout'], 'trailing newline must survive the round trip');
    }

    // ─── export run path (stdout content, child-isolated) ───────────────────

    public function testExportRunWritesJsonEntriesToStdout(): void
    {
        // Covers ExportCommand::run()'s real STDOUT write without polluting
        // the suite's own stdout (fwrite(STDOUT) bypasses output buffering,
        // which is why the in-process ImportExportTest cases assert on
        // exportToString() instead).
        $this->runSkate(['set', 'x', '1']);
        $this->runSkate(['set', 'y', '2']);

        $res = $this->runSkate(['export', 'json']);
        $this->assertSame(0, $res['exit']);
        $this->assertNoFatal($res);

        $decoded = \json_decode($res['stdout'], true);
        $this->assertIsArray($decoded, 'export stdout must be valid JSON');
        $this->assertSame('1', $decoded['x'] ?? null);
        $this->assertSame('2', $decoded['y'] ?? null);
    }

    /**
     * Run `php bin/candy-kv <args...>` with an isolated XDG_CONFIG_HOME,
     * feeding (or closing) the child's stdin.
     *
     * @param list<string> $args
     * @param string|null  $stdin  Bytes to pipe in; null closes stdin (EOF).
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function runSkateFeeding(array $args, ?string $stdin): array
    {
        $bin = \dirname(__DIR__) . '/bin/candy-kv';
        $cmd = \array_merge([\PHP_BINARY, $bin], $args);

        $env = \getenv();
        $env['XDG_CONFIG_HOME'] = $this->configHome;

        $proc = \proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $env);
        $this->assertIsResource($proc, 'failed to launch the candy-kv CLI');

        if ($stdin !== null) {
            \fwrite($pipes[0], $stdin);
        }
        \fclose($pipes[0]); // EOF for the child — it never waits on a terminal.
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exit = \proc_close($proc);

        return ['stdout' => (string) $stdout, 'stderr' => (string) $stderr, 'exit' => $exit];
    }

    /**
     * Run `php bin/candy-kv <args...>` with an isolated XDG_CONFIG_HOME.
     *
     * @param list<string> $args
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function runSkate(array $args): array
    {
        $bin = \dirname(__DIR__) . '/bin/candy-kv';
        $cmd = \array_merge([\PHP_BINARY, $bin], $args);

        $env = \getenv();
        $env['XDG_CONFIG_HOME'] = $this->configHome;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = \proc_open($cmd, $descriptors, $pipes, null, $env);
        $this->assertIsResource($proc, 'failed to launch the candy-kv CLI');

        // These commands take their value on argv; close stdin so `set` never
        // blocks waiting on a value from a terminal.
        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exit = \proc_close($proc);

        return [
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'exit' => $exit,
        ];
    }

    /**
     * @param array{stdout: string, stderr: string, exit: int} $result
     */
    public function testGetPatternIsRefusedWithPointerToList(): void
    {
        // M2: get is exact-key; a glob must be refused loudly, not silently
        // treated as a literal miss.
        $this->runSkate(['set', 'user-1', 'alice']);
        $get = $this->runSkate(['get', 'user-*']);
        $this->assertSame(2, $get['exit'], 'pattern in get must exit 2');
        $this->assertStringContainsString('list', $get['stderr'], 'refusal must point at list');
        $this->assertNoFatal($get);
    }

    public function testDeleteMissingKeyExitsOne(): void
    {
        // n-minor: delete of a non-matching key used to print "0 entries" and
        // exit 0 — indistinguishable from success for scripts.
        $hit = $this->runSkate(['delete', 'absent-key']);
        $this->assertSame(1, $hit['exit'], 'delete of a missing key must exit 1');
        $this->assertStringContainsString('absent-key', $hit['stderr']);

        $this->runSkate(['set', 'present-key', 'v']);
        $ok = $this->runSkate(['delete', 'present-key']);
        $this->assertSame(0, $ok['exit'], 'delete of a matching key still exits 0');
    }

    public function testNonNumericTtlExitsTwo(): void
    {
        $set = $this->runSkate(['set', 'soon-key', 'v', '--ttl=soon']);
        $this->assertSame(2, $set['exit'], '--ttl=soon must be a usage error, not exit 0 storing ttl 0');
        $this->assertNoFatal($set);
        // The value must not have been stored (fail-loud, before any write).
        $get = $this->runSkate(['get', 'soon-key']);
        $this->assertSame(1, $get['exit']);
    }

    public function testTtlSpaceFormWorksEndToEnd(): void
    {
        // M1: the documented space form must actually store a TTL.
        $set = $this->runSkate(['set', 'ttl-space', 'v', '--ttl', '3600']);
        $this->assertSame(0, $set['exit']);
        $list = $this->runSkate(['list', '-v']);
        $this->assertSame("v\n", $list['stdout']);
        $this->assertNoFatal($set);
        $this->assertNoFatal($list);
    }

    public function testListDelimiterSpaceFormWorksEndToEnd(): void
    {
        $this->runSkate(['set', 'd1', 'v1']);
        $list = $this->runSkate(['list', '-d', ':']);
        $this->assertSame(0, $list['exit']);
        $this->assertStringContainsString('d1:v1', $list['stdout']);
        $this->assertNoFatal($list);
    }

    public function testListAttachedDelimiterStillWorks(): void
    {
        $this->runSkate(['set', 'd2', 'v2']);
        $list = $this->runSkate(['list', '-d|']);
        $this->assertSame(0, $list['exit']);
        $this->assertStringContainsString('d2|v2', $list['stdout']);
    }

    private function assertNoFatal(array $result): void
    {
        $combined = $result['stdout'] . "\n" . $result['stderr'];
        foreach (['Fatal error', 'Uncaught', 'Stack trace', 'Call to '] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $combined,
                "CLI output contained a PHP fatal marker: {$needle}\n{$combined}"
            );
        }
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\glob("{$dir}/*") ?: [] as $f) {
            \is_dir($f) ? $this->removeDir($f) : @\unlink($f);
        }
        @\rmdir($dir);
    }
}
