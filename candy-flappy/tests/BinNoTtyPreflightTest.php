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
        unset($env['XDG_CONFIG_HOME'], $env['CLICOLOR_FORCE'], $env['FORCE_COLOR']);

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

    public function testDiagnosticFlagsStillAnswerWhilePiped(): void
    {
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
