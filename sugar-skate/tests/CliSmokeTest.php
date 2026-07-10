<?php

declare(strict_types=1);

namespace SugarCraft\Skate\Tests;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke test that runs the real `bin/skate` binary via proc_open.
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
            $this->markTestSkipped('ext-sqlite3 is required to run the skate CLI.');
        }
        $this->configHome = \sys_get_temp_dir() . '/skate-cli-smoke-' . \uniqid('', true);
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

    /**
     * Run `php bin/skate <args...>` with an isolated XDG_CONFIG_HOME.
     *
     * @param list<string> $args
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function runSkate(array $args): array
    {
        $bin = \dirname(__DIR__) . '/bin/skate';
        $cmd = \array_merge([\PHP_BINARY, $bin], $args);

        $env = \getenv();
        $env['XDG_CONFIG_HOME'] = $this->configHome;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = \proc_open($cmd, $descriptors, $pipes, null, $env);
        $this->assertIsResource($proc, 'failed to launch the skate CLI');

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
