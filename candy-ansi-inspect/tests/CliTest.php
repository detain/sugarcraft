<?php

declare(strict_types=1);

namespace SugarCraft\AnsiInspect\Tests;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end guard tests for the bin/candy-ansi-inspect CLI entry point.
 *
 * The file-path argument must accept only real local files — never a PHP
 * stream wrapper (php://, data://, http://, …) — so an attacker-supplied
 * "path" cannot be coerced into fetching a URL or evaluating a wrapper.
 */
final class CliTest extends TestCase
{
    private static function bin(): string
    {
        return dirname(__DIR__) . '/bin/candy-ansi-inspect';
    }

    /**
     * @param array<string, string>|null $env Full replacement environment for the
     *                                        child (null = inherit the parent's)
     *
     * @return array{0: int, 1: string, 2: string} [exitCode, stdout, stderr]
     */
    private static function runCli(string $arg, ?array $env = null): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(self::bin()) . ' '
            . escapeshellarg($arg);

        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [$code, $stdout, $stderr];
    }

    public function testRejectsDataStreamWrapper(): void
    {
        // Without the is_file() guard, file_get_contents() honours data:// and
        // emits the payload as a report — the guard must refuse the wrapper.
        [$code, $stdout, $stderr] = self::runCli('data://text/plain,injected');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('not a readable file', $stderr);
        $this->assertStringNotContainsString('injected', $stdout);
    }

    public function testRejectsNonexistentPath(): void
    {
        [$code, , $stderr] = self::runCli('/no/such/candy-ansi-inspect/input/file');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('not a readable file', $stderr);
    }

    public function testRefusalTokenizesControlBytesInThePathArgument(): void
    {
        // M1-fresh: the refusal echoes the path argument. Without the
        // sanitizeLabelBytes() wrap an OSC plant (`ESC ] 0 ; … BEL`) rides
        // through the stderr line and re-arms the caller's terminal. The pin
        // asserts the TOKENISED arms are shown (a mere-absence pin would stay
        // green under a deleting sanitizer too), and the refusal still exits 1.
        [$code, $stdout, $stderr] = self::runCli("x\x1b]0;pwn\x07x");
        $this->assertSame(1, $code, $stderr);
        $this->assertStringNotContainsString("\x1b", $stderr);
        $this->assertStringNotContainsString("\x07", $stderr);
        $this->assertStringContainsString('ESC', $stderr);
        $this->assertStringContainsString('BEL', $stderr);
        $this->assertSame('', $stdout);
    }

    public function testRejectsExistingButUnreadableFile(): void
    {
        // M2 (option (i)): an existing file that cannot be read used to slip
        // past the is_file()-only gate — the bare read emitted a PHP warning
        // and the empty content took the empty-source branch, exiting 0 as if
        // the inspection succeeded. The widened gate refuses it with the same
        // sanitized refusal and exit 1. chmod 000 is the working shape on this
        // box (setpriv/su unavailable); under root is_readable() lies, so the
        // pin skips instead of false-greening.
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses file permissions: is_readable() would lie');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'inspect-unreadable');
        $this->assertIsString($tmp);
        file_put_contents($tmp, "\x1b[31mred");
        chmod($tmp, 0o000);
        try {
            [$code, $stdout, $stderr] = self::runCli($tmp);
            $this->assertSame(1, $code, 'unreadable file must exit 1, got: ' . $stderr);
            $this->assertStringContainsString('not a readable file', $stderr);
            $this->assertStringNotContainsString('PHP Warning', $stderr);
            $this->assertSame('', $stdout);
        } finally {
            chmod($tmp, 0o600);
            unlink($tmp);
        }
    }

    public function testRefusalLanguageFollowsTheDetectedLocale(): void
    {
        // m3: the bin entry calls T::setLocale(T::detect()), so the refusal
        // answers the caller's locale (LC_ALL → LC_MESSAGES → LANG). The German
        // string is read from lang/de.php rather than hardcoded, so the pin
        // tracks the catalogue instead of fossilising prose. The env array
        // fully replaces the child environment — a stray LC_ALL on the runner
        // cannot flip either leg.
        $catalogue = require dirname(__DIR__) . '/lang/de.php';
        $this->assertIsArray($catalogue);
        $german = str_replace(
            '{path}',
            '/no/such/de-locale-probe',
            (string) $catalogue['cli.not_a_file'],
        );

        [$code, $stdout, $stderr] = self::runCli('/no/such/de-locale-probe', ['LANG' => 'de']);
        $this->assertSame(1, $code, $stderr);
        $this->assertStringContainsString($german, $stderr);
        $this->assertStringNotContainsString('not a readable file', $stderr);
        $this->assertSame('', $stdout);

        // Polarity: with no locale env at all the same refusal stays English.
        [, , $english] = self::runCli('/no/such/de-locale-probe', ['PATH' => '/usr/bin:/bin']);
        $this->assertStringContainsString('not a readable file', $english);
        $this->assertStringNotContainsString($german, $english);
    }

    /**
     * Run the CLI with piped stdin (never a TTY) and optional file argument.
     *
     * @param string           $stdin bytes to feed on stdin before closing it
     * @param list<string>     $args  argv tail
     *
     * @return array{0: int, 1: string, 2: string} [exitCode, stdout, stderr]
     */
    private static function runCliWithStdin(string $stdin, array $args = []): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::bin());
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        // Drain-then-reap (proc_close) — a single-shot proc_get_status after
        // pipe EOF is racy on Linux 6.x.
        return [proc_close($proc), $stdout, $stderr];
    }

    public function testEmptyFileIsSuccessWithEmptyReport(): void
    {
        // A source that exists and is empty is legitimate input, not misuse:
        // the empty stream's report is empty and the exit code is 0 (audit C7 —
        // rc=1 here broke scripts piping possibly-empty command output).
        $tmp = tempnam(sys_get_temp_dir(), 'inspect-empty');
        $this->assertIsString($tmp);
        try {
            [$code, $stdout, $stderr] = self::runCli($tmp);
            $this->assertSame(0, $code, $stderr);
            $this->assertSame('', $stdout);
            $this->assertSame('', $stderr);
        } finally {
            @unlink($tmp);
        }
    }

    public function testEmptyStdinPipeIsSuccessWithEmptyReport(): void
    {
        [$code, $stdout, $stderr] = self::runCliWithStdin('');
        $this->assertSame(0, $code, $stderr);
        $this->assertSame('', $stdout);
        $this->assertSame('', $stderr);
    }

    public function testPipedInputStillReportsNormally(): void
    {
        // Polarity: the empty-pipe success path must not short-circuit real
        // stdin content.
        [$code, $stdout] = self::runCliWithStdin("\x1b[31mred");
        $this->assertSame(0, $code);
        $this->assertStringContainsString('SGR foreground red', $stdout);
        $this->assertStringContainsString('red', $stdout);
    }

    public function testReadsRealLocalFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'inspect');
        $this->assertIsString($tmp);
        file_put_contents($tmp, "\x1b[31mred\x1b[0m");
        try {
            [$code, $stdout, $stderr] = self::runCli($tmp);
            $this->assertSame(0, $code, $stderr);
            $this->assertStringContainsString('red', $stdout);
        } finally {
            @unlink($tmp);
        }
    }
}
