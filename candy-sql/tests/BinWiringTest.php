<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The launcher is a script, so it cannot be included by tests (it runs the
 * program at include time). These pins read its source and assert the exact
 * wiring shapes that docs-review-1023 demanded: query history default-on
 * with an opt-out flag, the CANDY_SQL_PASS fallback, and a version string
 * that tracks the installed package instead of a hardcoded literal.
 */
final class BinWiringTest extends TestCase
{
    private const BIN_PATH = __DIR__ . '/../bin/candy-sql';

    private static function binSource(): string
    {
        $src = \file_get_contents(self::BIN_PATH);
        \assert(\is_string($src), 'the launcher must be readable');

        return $src;
    }

    public function testHistoryIsWiredDefaultOnWithOptOutFlags(): void
    {
        $src = self::binSource();

        $this->assertStringContainsString('App::start($database, $flavor, $historyRecorder)', $src);
        $this->assertStringContainsString('new HistoryRecorder(new SqliteHistoryStore($historyPath))', $src);
        $this->assertStringContainsString("'--no-history'", $src);
        $this->assertStringContainsString("'--history='", $src);
        $this->assertStringContainsString(".candy-sql/history.db", $src);
    }

    public function testPasswordFallsBackToEnvironment(): void
    {
        $src = self::binSource();

        $this->assertStringContainsString("getenv('CANDY_SQL_PASS')", $src);
        $this->assertStringContainsString("str_starts_with(\$arg, '--pass=')", $src);
    }

    public function testVersionComesFromComposerMetadata(): void
    {
        // 14a7e605a's law, kept through the phar fusion (DST-1): the answer
        // tracks the installed package through Composer, never a hardcoded
        // literal. The resolution now lives behind Version (the phar
        // precedence demanded a single fused seam), so the token pin follows
        // it — plus the bin must delegate, and no literal may resurface in
        // either file.
        $src = self::binSource();
        $versionSrc = \file_get_contents(__DIR__ . '/../src/Version.php');
        \assert(\is_string($versionSrc), 'the version resolver must be readable');

        $this->assertStringContainsString('Version::current()', $src);
        $this->assertStringContainsString('InstalledVersions::getPrettyVersion', $versionSrc);
        $this->assertStringNotContainsString('"candy-sql 1.0.0"', $src);
        $this->assertStringNotContainsString('1.0.0', $versionSrc);
    }

    public function testLauncherIsNotNamespaced(): void
    {
        $this->assertStringNotContainsString(
            'namespace SugarCraft\Sql\bin;',
            self::binSource(),
            'a script-only namespace was never loadable and misled the docs'
        );
    }

    /**
     * D-C11: launch-time refusals (bad driver, unparseable DSN, unwritable
     * history) used to die as raw PHP fatal traces — seven lines of stack
     * frames and exit 255. They now print one sanitized sentence to stderr
     * and exit 2. The pin drives the real launcher as a child process; an
     * unsupported driver fails at parse time, before any socket is opened,
     * so this stays hermetic (no live connection anywhere).
     */
    /** Run the real bin with argv (and optional env overrides) and capture (exit, stdout, stderr). */
    private function runBin(array $argv, ?array $env = null): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = \proc_open(
            [\PHP_BINARY, self::BIN_PATH, ...$argv],
            $descriptors,
            $pipes,
            \dirname(self::BIN_PATH),
            $env,
        );
        $this->assertIsResource($process);
        \fclose($pipes[0]);
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [\proc_close($process), $stdout, $stderr];
    }

    public function testHelpExitsZeroWithTheUsageScreen(): void
    {
        [$exit, $stdout] = $this->runBin(['--help']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('candy-sql', $stdout);
        $this->assertStringContainsString('USAGE', $stdout);
    }

    public function testBareInvocationExitsTwoNotOne(): void
    {
        // mn-18 (2026-11-03): a bare launch printed usage but exited 0-era
        // 1; the help screen now advertises status 2 for bad invocations.
        [$exit, $stdout] = $this->runBin([]);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('USAGE', $stdout);
    }

    public function testLaunchFailureIsASingleLineAndExitCodeTwo(): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = \proc_open(
            [\PHP_BINARY, self::BIN_PATH, 'oracle://nobody@localhost/none'],
            $descriptors,
            $pipes,
            \dirname(self::BIN_PATH),
        );
        $this->assertIsResource($process);
        \fclose($pipes[0]);
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exit = \proc_close($process);

        $this->assertSame(2, $exit, 'launch refusals exit 2, never a 255 fatal');
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('Unsupported driver', $stderr);
        $this->assertStringNotContainsString('Stack trace', $stderr);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        // Exactly one line: the sanitized sentence, nothing else.
        $this->assertSame(1, \substr_count($stderr, "\n"));
    }

    // ── SQL-O02: saved connections ───────────────────────────────────────────

    /** @return array{0: string, 1: array<string,string>} tmp home + child env */
    private function isolatedHome(string $json = ''): array
    {
        $home = \sys_get_temp_dir() . '/cs-bin-' . \uniqid('', true);
        $configDir = $home . '/.config/candy-sql';
        \mkdir($configDir, 0700, true);
        if ($json !== '') {
            \file_put_contents($configDir . '/connections.json', $json);
            \chmod($configDir . '/connections.json', 0600);
        }
        $env = [
            'HOME' => $home,
            'XDG_CONFIG_HOME' => $home . '/.config',
            'PATH' => \getenv('PATH') ?: '/usr/bin:/bin',
        ];

        return [$home, $env];
    }

    private static function cleanIsolatedHome(string $home): void
    {
        @\unlink($home . '/.config/candy-sql/connections.json');
        @\rmdir($home . '/.config/candy-sql');
        @\rmdir($home . '/.config');
        @\rmdir($home);
    }

    public function testSavedConnectionWiringIsPresentInTheLauncher(): void
    {
        $src = self::binSource();

        $this->assertStringContainsString("'--connect='", $src);
        $this->assertStringContainsString('ConnectionPicker', $src);
        $this->assertStringContainsString('stream_isatty(STDOUT)', $src);
        $this->assertStringContainsString('SAVED CONNECTIONS', $src);
    }

    public function testConnectRefusesAnUnknownNameAndListsSavedOnes(): void
    {
        [$home, $env] = $this->isolatedHome(
            '{"connections": {"dev": {"driver": "mysql", "host": "h", "db": "d"}}}'
        );
        try {
            [$exit, $stdout, $stderr] = $this->runBin(['--connect=ghost'], $env);
            $this->assertSame(2, $exit);
            $this->assertSame('', $stdout);
            $this->assertStringContainsString("Unknown connection 'ghost'", $stderr);
            $this->assertStringContainsString('saved connections: dev', $stderr);
        } finally {
            $this->cleanIsolatedHome($home);
        }
    }

    public function testConnectCannotBeCombinedWithExplicitArgs(): void
    {
        [$home, $env] = $this->isolatedHome(
            '{"connections": {"dev": {"driver": "mysql", "host": "h", "db": "d"}}}'
        );
        try {
            [$exit, , $stderr] = $this->runBin(['--connect=dev', '--driver=pgsql'], $env);
            $this->assertSame(2, $exit);
            $this->assertStringContainsString('cannot be combined', $stderr);
        } finally {
            $this->cleanIsolatedHome($home);
        }
    }

    public function testConnectWithAMissingPasswordEnvVarRefusesByVariableName(): void
    {
        [$home, $env] = $this->isolatedHome(
            '{"connections": {"p": {"driver": "mysql", "host": "h", "db": "d", "pass-env": "CS_ABSENT_PW"}}}'
        );
        try {
            [$exit, , $stderr] = $this->runBin(['--connect=p'], $env);
            $this->assertSame(2, $exit);
            $this->assertStringContainsString('$CS_ABSENT_PW', $stderr);
            $this->assertStringContainsString('export it before dialing', $stderr);
        } finally {
            $this->cleanIsolatedHome($home);
        }
    }

    public function testPlaintextPassInFileRefusesTheLaunch(): void
    {
        [$home, $env] = $this->isolatedHome(
            '{"connections": {"p": {"driver": "mysql", "host": "h", "db": "d", "pass": "hunter2"}}}'
        );
        try {
            [$exit, , $stderr] = $this->runBin(['--connect=p'], $env);
            $this->assertSame(2, $exit);
            $this->assertStringContainsString("plaintext 'pass'", $stderr);
        } finally {
            $this->cleanIsolatedHome($home);
        }
    }

    public function testCorruptConnectionsFileRefusesNotFatals(): void
    {
        [$home, $env] = $this->isolatedHome('{"connections": {oops');
        try {
            [$exit, , $stderr] = $this->runBin(['--connect=anything'], $env);
            $this->assertSame(2, $exit);
            $this->assertStringContainsString('not valid JSON', $stderr);
            $this->assertStringNotContainsString('Fatal error', $stderr);
        } finally {
            $this->cleanIsolatedHome($home);
        }
    }

    public function testBareInvocationWithNoSavedConnectionsKeepsUsageExitTwo(): void
    {
        // Piped stdout is never a tty, so the picker gate must not open here —
        // the named exit-2 refusal stays the pipe contract (O02 carve-out).
        [$home, $env] = $this->isolatedHome();
        try {
            [$exit, $stdout,] = $this->runBin([], $env);
            $this->assertSame(2, $exit);
            $this->assertStringContainsString('USAGE', $stdout);
            $this->assertStringContainsString('connections.json', $stdout);
        } finally {
            $this->cleanIsolatedHome($home);
        }
    }
}
