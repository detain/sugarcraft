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
     *                                        child (null = a minimal PATH-only
     *                                        environment, so the runner's
     *                                        LANG/LC_ALL can never flip pins)
     * @param string|null                $cwd Working directory for the child
     *                                        (null = inherit the parent's)
     *
     * @return array{0: int, 1: string, 2: string} [exitCode, stdout, stderr]
     */
    private static function runCli(string $arg, ?array $env = null, ?string $cwd = null): array
    {
        $env ??= ['PATH' => '/usr/bin:/bin'];
        $cmd = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(self::bin()) . ' '
            . escapeshellarg($arg);

        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
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

    public function testFileLiterallyNamedZeroIsInspectedNotDemotedToStdin(): void
    {
        // Finding 012: empty("0") is true, so the old truthiness gate skipped
        // the argument entirely and read the (closed, empty) stdin pipe — the
        // file named `0` went uninspected. Presence is the gate now.
        $dir = sys_get_temp_dir() . '/aip05-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/0', "\x1b[31mred\x1b[0m");
        try {
            [$code, $stdout, $stderr] = self::runCli('0', null, $dir);
            $this->assertSame(0, $code, $stderr);
            $this->assertStringContainsString('SGR foreground red', $stdout);
            $this->assertStringContainsString('SGR reset', $stdout);
        } finally {
            unlink($dir . '/0');
            rmdir($dir);
        }
    }

    public function testEmptyStringArgumentIsRefusedNotDemotedToStdin(): void
    {
        // The other half of finding 012: `""` is a PRESENT argument, so it may
        // not quietly fall through to the pipe — refusing beats reading data
        // the caller never named.
        [$code, $stdout, $stderr] = self::runCliWithStdin("piped \x1b[31m text", ['']);
        $this->assertSame(1, $code, $stderr);
        $this->assertStringContainsString('not a readable file', $stderr);
        $this->assertSame('', $stdout);
    }

    public function testJsonFlagOnStdinEmitsAParseableArray(): void
    {
        // Finding 005: `--json` — the report machine-readable, with raw bytes
        // in `content` and the human label in `description`.
        [$code, $stdout, $stderr] = self::runCliWithStdin("hi\x1b[31mred\x1b[0m", ['--json']);
        $this->assertSame(0, $code, $stderr);
        $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [
                ['type' => 'text', 'content' => 'hi', 'description' => 'hi'],
                ['type' => 'sequence', 'content' => "\x1b[31m", 'description' => 'ESC[31m  SGR foreground red'],
                ['type' => 'text', 'content' => 'red', 'description' => 'red'],
                ['type' => 'sequence', 'content' => "\x1b[0m", 'description' => 'ESC[0m  SGR reset'],
            ],
            $decoded,
        );
    }

    public function testJsonFlagWorksBeforeAndAfterTheFileOperand(): void
    {
        $dir = sys_get_temp_dir() . '/aip06-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $path = $dir . '/capture.bin';
        file_put_contents($path, "\x1b[31mred\x1b[0m");
        try {
            [$codeA, $stdoutA, $stderrA] = self::runCliWithStdin('', ['--json', $path]);
            [$codeB, $stdoutB, $stderrB] = self::runCliWithStdin('', [$path, '--json']);
            $this->assertSame(0, $codeA, $stderrA);
            $this->assertSame(0, $codeB, $stderrB);
            $this->assertSame($stdoutA, $stdoutB, 'flag position must not change the report');
            $decoded = json_decode($stdoutA, true, 512, JSON_THROW_ON_ERROR);
            $this->assertCount(3, $decoded);
            $this->assertSame("\x1b[31m", $decoded[0]['content'], 'raw bytes ride in content');
        } finally {
            unlink($path);
            rmdir($dir);
        }
    }

    public function testJsonOfAnEmptyStreamIsTheEmptyArray(): void
    {
        [$code, $stdout, $stderr] = self::runCliWithStdin('', ['--json']);
        $this->assertSame(0, $code, $stderr);
        $this->assertSame([], json_decode($stdout, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testJsonFlagNeverCrossesTheExecBoundary(): void
    {
        // Everything after `--` belongs to the child (AIP-03 contract); the
        // exec report stays line-mode, so a stray --json before the boundary
        // must not turn the live report into a collected array.
        [$code, $stdout, $stderr] = self::runCliWithStdin('', ['--json', '--', 'printf', 'plain']);
        if ($code === 1 && str_contains($stderr, 'pseudo-terminal')) {
            $this->markTestSkipped('no pseudo-terminal on this runner: ' . trim($stderr));
        }
        $this->assertSame(0, $code, $stderr);
        $this->assertStringNotContainsString('{', $stdout);
        $this->assertStringNotContainsString('[', $stdout);
        $this->assertStringContainsString('plain', $stdout);
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

    public function testLocalePrecedenceIsLcAllThenLcMessagesThenLang(): void
    {
        // README + bin advertise LC_ALL → LC_MESSAGES → LANG (the chain
        // T::detect() walks). Pin both decisive edges: LC_ALL must beat a
        // contradicting LANG, and LC_MESSAGES must beat LANG when LC_ALL
        // is absent. Catalogues are read from disk so the pins track the
        // translations, not fossilised prose.
        $french = str_replace('{path}', '/no/such/prec-probe', (string) (require dirname(__DIR__) . '/lang/fr.php')['cli.not_a_file']);
        $german = str_replace('{path}', '/no/such/prec-probe', (string) (require dirname(__DIR__) . '/lang/de.php')['cli.not_a_file']);

        [, , $stderr] = self::runCli('/no/such/prec-probe', ['LC_ALL' => 'fr', 'LANG' => 'de']);
        $this->assertStringContainsString($french, $stderr);
        $this->assertStringNotContainsString($german, $stderr);

        [, , $stderr] = self::runCli('/no/such/prec-probe', ['LC_MESSAGES' => 'de', 'LANG' => 'fr']);
        $this->assertStringContainsString($german, $stderr);
        $this->assertStringNotContainsString($french, $stderr);
    }

    public function testHelpFlagAnswersInTheDetectedLocale(): void
    {
        // The --help leg goes through Lang::t('cli.usage') like the other
        // tool prints — a non-English invocation must print the non-English
        // usage line (and never the English one).
        $usage = rtrim((string) (require dirname(__DIR__) . '/lang/de.php')['cli.usage'], "\n");

        [$code, $stdout] = self::runCli('--help', ['LC_ALL' => 'de']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString($usage, $stdout);
        $this->assertStringNotContainsString('Usage: candy-ansi-inspect [file]', $stdout);
    }

    public function testHelpFlagPrintsUsageOnStdoutAndExitsZero(): void
    {
        // m2-fresh ruling (a): `--help`/`-h` is a request for usage, not a
        // path — before the arm it was swallowed by the file gate and refused
        // 'not a readable file: --help' with exit 1. Now the usage lands on
        // stdout, exit 0, stderr silent (a scripted caller may capture it).
        // The expected text is read from lang/en.php, not hardcoded.
        $usage = (string) (require dirname(__DIR__) . '/lang/en.php')['cli.usage'];
        foreach (['--help', '-h'] as $flag) {
            [$code, $stdout, $stderr] = self::runCli($flag);
            $this->assertSame(0, $code, $flag . ': ' . $stderr);
            $this->assertStringContainsString($usage, $stdout, $flag . ' stdout');
            $this->assertSame('', $stderr, $flag . ' must leave stderr empty');
        }
    }

    /**
     * Run the CLI with piped stdin (never a TTY) and optional file argument.
     *
     * @param string           $stdin bytes to feed on stdin before closing it
     * @param list<string>     $args  argv tail
     *
     * @return array{0: int, 1: string, 2: string} [exitCode, stdout, stderr]
     */
    private static function runCliWithStdin(string $stdin, array $args = [], ?array $env = null): array
    {
        // Same isolation as runCli(): a dev shell exporting LANG must not
        // be able to flip the English-string pins through this driver.
        $env ??= ['PATH' => '/usr/bin:/bin'];
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::bin());
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
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

    public function testNoSourceWithATtyStdinIsUsageMisuseNotAStdinWait(): void
    {
        // The "no source named" arm (no operand, STDIN is a terminal) is the
        // only gate branch an interactive caller hits, and pipes can never
        // reach it: stream_isatty() is false for the harness pipes. script(1)
        // allocates a real pty so the child sees a terminal STDIN. House
        // env-gate precedent: skip when the tool is absent.
        $script = trim((string) shell_exec('command -v script 2>/dev/null'));
        if ($script === '' || !is_executable($script)) {
            $this->markTestSkipped('script(1) is not available on this host');
        }

        $proc = proc_open(
            [$script, '-qec', escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::bin()), '/dev/null'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => '/usr/bin:/bin'],
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = str_replace("\r\n", "\n", (string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $this->assertSame(1, $code, $stdout);
        $this->assertStringContainsString('Usage: candy-ansi-inspect', $stdout);
    }

    public function testStreamedStdinBytesEqualTheFileArgumentReport(): void
    {
        // The streaming read must not change the report — only when lines
        // arrive. Same corpus through both source arms has to agree byte for
        // byte (the final terminator is the one tolerated difference: the file
        // path ends with PHP_EOL, the stream with the "\n" that joins lines).
        $corpus = "a\x1b[31mred\x1b[0m b\x1b]0;title\x07tail\x1b[1;2\x1bZ";
        $tmp = tempnam(sys_get_temp_dir(), 'inspect-parity');
        $this->assertIsString($tmp);
        try {
            file_put_contents($tmp, $corpus);
            [$fileCode, $fileOut, $fileErr] = self::runCli($tmp);
            [$pipeCode, $pipeOut, $pipeErr] = self::runCliWithStdin($corpus);
            $this->assertSame(0, $fileCode, $fileErr);
            $this->assertSame(0, $pipeCode, $pipeErr);
            $this->assertSame(rtrim($fileOut, "\r\n"), rtrim($pipeOut, "\r\n"), $corpus);
            $this->assertMatchesRegularExpression('/\r?\n\z/', $pipeOut, 'stream output is newline-terminated');
        } finally {
            @unlink($tmp);
        }
    }

    public function testPipedSequencesAreReportedBeforeTheWriterCloses(): void
    {
        // The whole point of streaming (finding 004): a slow producer's first
        // completed sequence must reach stdout while the pipe is still open.
        $proc = proc_open(
            [PHP_BINARY, self::bin()],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($proc);
        stream_set_blocking($pipes[1], false);
        try {
            fwrite($pipes[0], "\x1b[31m");
            fflush($pipes[0]); // one complete SGR, pipe stays open
            $seen = '';
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                $read = [$pipes[1]];
                $write = $except = [];
                if (stream_select($read, $write, $except, 0, 100_000) > 0) {
                    $seen .= (string) fread($pipes[1], 4096);
                    if (str_contains($seen, "\n")) {
                        break;
                    }
                }
                if (feof($pipes[1])) {
                    break;
                }
            }
            // Decisive assertion while the child's stdin is still open: if the
            // bin still slurped, nothing could have arrived yet.
            $this->assertStringContainsString('SGR foreground red', $seen, 'sequence reported before EOF');

            fwrite($pipes[0], "later\x1b[0m");
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], true); // drain to EOF, not non-blocking peek
            $rest = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $code = proc_close($proc);
            $this->assertSame(0, $code);
            $this->assertStringContainsString('SGR reset', $rest);
            $this->assertStringContainsString('later', $rest);
        } finally {
            if (is_resource($proc)) {
                proc_terminate($proc);
                proc_close($proc);
            }
        }
    }

    public function testExecArmRunsARealCommandWhenThePlatformHasAPty(): void
    {
        // The thin real-PTY leg of AIP-03's acceptance: `-- printf …` must
        // report the styled stream the child prints on its pseudo-terminal
        // and exit with the child's status. Runners without a PTY get a
        // visible skip instead of a fake pass.
        [$code, $stdout, $stderr] = self::runCliWithStdin('', ['--', 'printf', "\x1b[31mhi\x1b[0m"]);
        if ($code === 1 && str_contains($stderr, 'pseudo-terminal')) {
            $this->markTestSkipped('no pseudo-terminal on this runner: ' . trim($stderr));
        }
        $this->assertSame(0, $code, $stderr);
        $this->assertStringContainsString('SGR foreground red', $stdout);
        $this->assertStringContainsString('hi', $stdout);
        $this->assertStringContainsString('SGR reset', $stdout);
    }

    public function testExecArmWithoutACommandIsUsageMisuse(): void
    {
        [$code, $stdout, $stderr] = self::runCliWithStdin('', ['--']);
        $this->assertSame(1, $code);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('Usage:', $stderr);
    }

    public function testExecArmCarriesAChildFailureThrough(): void
    {
        [$code, $stdout, $stderr] = self::runCliWithStdin('', ['--', 'sh', '-c', 'exit 3']);
        if ($code === 1 && str_contains($stderr, 'pseudo-terminal')) {
            $this->markTestSkipped('no pseudo-terminal on this runner: ' . trim($stderr));
        }
        $this->assertSame(3, $code, $stderr);
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
