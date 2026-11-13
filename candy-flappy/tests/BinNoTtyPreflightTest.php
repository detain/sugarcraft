<?php

declare(strict_types=1);

namespace SugarCraft\Flappy\Tests;

use PHPUnit\Framework\TestCase;

/**
 * flappy-01 (finding -001): the CLI-level non-TTY preflight. A launch whose
 * stdout is a pipe (or /dev/null) must be refused with plain text on stderr
 * and exit 1 BEFORE the Program exists — zero escape bytes may ever reach
 * the pipe, and the one-time legacy migration must not run as a side effect
 * of a launch that can never draw. The diagnostic flags answer earlier
 * still, so --version/--help keep working piped.
 */
final class BinNoTtyPreflightTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/candy-flappy';

    /**
     * Run the bin headless: stdin /dev/null, stdout and stderr pipes, a
     * controlled environment (fresh HOME, no force rungs unless asked).
     *
     * @param array<string, string> $extraEnv
     *
     * @return array{0: int, 1: string, 2: string, 3: string} exit code, stdout, stderr, home dir
     */
    private static function drive(array $extraEnv = []): array
    {
        $home = sys_get_temp_dir() . '/flappy-preflight-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($home, 0o700, true), 'fixture HOME must be creatable');
        $env = array_merge([
            'PATH' => (string) (getenv('PATH') ?: ''),
            'HOME' => $home,
            'TERM' => 'xterm-256color',
        ], $extraEnv);

        $proc = proc_open(
            [PHP_BINARY, self::BIN],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            $env,
        );
        self::assertIsResource($proc, 'the bin must be launchable');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [$exit, $stdout, $stderr, $home];
    }

    public static function tearDownAfterClass(): void
    {
        // Fixture HOMEs are empty by law in the non-TTY run; sweep anyway so
        // a failure that leaves a mid-run file behind cannot litter /tmp.
        foreach (glob(sys_get_temp_dir() . '/flappy-preflight-*') ?: [] as $dir) {
            foreach (glob($dir . '/.candy-flappy/*') ?: [] as $deep) {
                @unlink($deep);
            }
            @rmdir($dir . '/.candy-flappy');
            @rmdir($dir);
        }
    }

    public function testPipedStdoutIsRefusedWithPlainTextAndExitOne(): void
    {
        [$exit, $stdout, $stderr] = self::drive();

        self::assertSame(1, $exit, 'a piped launch must exit 1, not draw and hang');
        self::assertSame('', $stdout, 'zero bytes may reach the piped stdout');
        self::assertStringNotContainsString(chr(27), $stderr, 'even stderr stays escape-free');
        self::assertStringContainsString('interactive terminal', $stderr);
    }

    public function testTheRefusalRunsBeforeTheLegacyMigration(): void
    {
        // The migration leg creates $HOME/.config/.candy-flappy on first
        // launch; a launch that was refused must not have touched the disk.
        [, , , $home] = self::drive();

        self::assertSame([], glob($home . '/*') ?: [], 'the refused launch must leave HOME untouched');
        @rmdir($home);
    }

    public function testTermDumbPipedLaunchRefusesThroughTheSameDoor(): void
    {
        // flappy-02 bin-seam arm: every unplayable launch ends rc 1 without
        // a signal death; TERM=dumb must not find a different door.
        [$exit] = self::drive(['TERM' => 'dumb']);
        self::assertSame(1, $exit);
    }

    public function testTermDumbInsideAPtyIsNeverSwitchedToTheAlternateScreen(): void
    {
        // flappy-13 (finding -011) bin seam: the CLI's once-probed profile
        // gates the alt-screen contract, so a TERM=dumb survivor inside a
        // real pty sees the in-band refusal WITHOUT the world being torn
        // off its normal screen first. Requires util-linux script(1) for
        // the pty; Linux CI has it.
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('script(1) pty harness is Linux-only here');
        }
        $script = trim((string) @shell_exec('command -v script 2>/dev/null'));
        if ($script === '') {
            self::markTestSkipped('util-linux script(1) not installed');
        }

        $home = sys_get_temp_dir() . '/flappy-preflight-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($home, 0o700, true));
        $proc = proc_open(
            [$script, '-qec', 'env -u CLICOLOR_FORCE -u FORCE_COLOR TERM=dumb HOME=' . $home . ' ' . PHP_BINARY . ' ' . self::BIN, '/dev/null'],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        self::assertIsResource($proc);
        stream_set_timeout($pipes[1], 15);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        // flappy-11: the in-band refusal folds at 38 cells, so the pin
        // tracks an intact fragment of the folded line1 (the bin's stderr
        // refusal above is not width-constrained and keeps its full
        // phrase); flappy-16 reworded the headline to name the render
        // requirement, not interactivity.
        self::assertStringContainsString('needs a terminal that can', $out, 'the refusal frame must render: ' . $out);
        self::assertStringNotContainsString("\x1b[?1049", $out, 'no alternate-screen switch for a refused game');
        self::assertStringNotContainsString("\x1b[?25l", $out, 'no cursor-hide for a refused game');
        self::assertSame(1, $exit, 'a dumb-terminal refusal exits 1, never a signal death');
    }

    public function testMissingExtensionsRefuseByNameWithExitTwo(): void
    {
        // flappy-35 (finding -084): `php -n` drops the ini-loaded shared
        // extensions — the exact field shape of a frozen-vendor phar run on
        // a bare interpreter. On statically built interpreters there is
        // nothing to drop, so the leg probes first.
        $probe = proc_open(
            [PHP_BINARY, '-n', '-r', 'echo extension_loaded("intl") ? "y" : "n";'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pp,
        );
        self::assertIsResource($probe);
        $state = (string) stream_get_contents($pp[1]);
        fclose($pp[1]);
        fclose($pp[2]);
        proc_close($probe);
        if ($state === 'y') {
            self::markTestSkipped('this interpreter builds intl in — nothing for -n to drop');
        }

        $proc = proc_open(
            [PHP_BINARY, '-n', self::BIN],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            ['PATH' => (string) (getenv('PATH') ?: ''), 'TERM' => 'xterm-256color'],
        );
        self::assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        self::assertSame(2, $exit, 'an extension refusal is an invocation error, never 255: ' . $stderr);
        self::assertSame('', $stdout);
        self::assertStringContainsString('extension', $stderr);
        self::assertStringContainsString('intl', $stderr, 'the refusal must name what is missing');
    }

    public function testUnresolvableConfigBaseRefusesCleanlyWithExitTwo(): void
    {
        // flappy-66 (finding -039): no $HOME, no $XDG_CONFIG_HOME — Game's
        // fail-closed throw must land as ONE stderr line and exit 2, not a
        // 255 trace. The force rung is the documented way to draw into a
        // pipe on purpose, which gets this past the -01 stdout preflight to
        // the construction site that used to explode.
        [$exit, $stdout, $stderr] = self::drive([
            'HOME' => '',
            'CLICOLOR_FORCE' => '1',
        ]);
        unset($stdout);

        self::assertSame(2, $exit, 'a config-fatal is an invocation error, never 255: ' . $stderr);
        self::assertStringContainsString('config directory', $stderr);
        self::assertStringNotContainsString('#0', $stderr, 'no trace frames may reach the terminal');
        self::assertStringNotContainsString('SugarCraft\\', $stderr, 'no internal class names');
        self::assertSame(1, substr_count(trim($stderr), "\n") + 1, 'exactly one refusal line');
    }

    public function testDiagnosticFlagsStillAnswerWhilePiped(): void    {
        // Order law: the preflight sits AFTER the flag legs — diagnostics
        // must stay usable from CI pipes and shell scripts.
        $proc = proc_open(
            [PHP_BINARY, self::BIN, '--version'],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        self::assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        self::assertSame(0, $exit, '--version piped must exit 0');
        self::assertStringContainsString('candy-flappy', $stdout);
    }
}
