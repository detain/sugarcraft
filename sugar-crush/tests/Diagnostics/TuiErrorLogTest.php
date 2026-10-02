<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Diagnostics;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Diagnostics\TuiErrorLog;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Audit C2a: the TUI's `error_log` redirect, driven against a scratch home.
 *
 * Every test starts from an UNSET destination (the stock ini, which is the
 * case the redirect exists for) and tearDown() puts the runner's own value
 * back — a leaked `error_log` ini would send every later test's diagnostics
 * into a deleted scratch file.
 */
final class TuiErrorLogTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home;

    private string|false $previousErrorLog;

    private int $previousUmask;

    protected function setUp(): void
    {
        $this->previousErrorLog = ini_get('error_log');
        $this->previousUmask = umask();
        ini_set('error_log', '');

        $this->home = sys_get_temp_dir() . '/sc_tui_error_log_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->home, 0o700, true);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        umask($this->previousUmask);
        $this->restoreHomeSandbox();
        $this->removeTree($this->home);
    }

    public function testInstallPointsErrorLogAtAPrivateFileUnderTheHome(): void
    {
        $path = TuiErrorLog::install($this->home);

        $expected = $this->home . '/.sugar-crush/logs/sugarcrush.log';
        self::assertSame($expected, $path);
        self::assertSame($expected, ini_get('error_log'));
        self::assertSame(0o700, fileperms(\dirname($expected)) & 0o777);
        self::assertSame(0o600, fileperms($expected) & 0o777);

        error_log('C2A-UNIT-PROBE');

        $contents = (string) file_get_contents($expected);
        self::assertStringContainsString('C2A-UNIT-PROBE', $contents);
        self::assertStringContainsString('sugarcrush TUI session start pid=' . getmypid(), $contents);
    }

    /**
     * The file must exist at 0600 BEFORE the ini names it, or the first
     * `error_log()` would create it with whatever the process umask allows.
     */
    public function testTheFileAndDirectoryArePrivateEvenUnderAPermissiveUmask(): void
    {
        umask(0);

        $path = TuiErrorLog::install($this->home);

        self::assertIsString($path);
        self::assertSame(0o600, fileperms($path) & 0o777);
        self::assertSame(0o700, fileperms(\dirname($path)) & 0o777);
        self::assertSame(0, umask(), 'install() must restore the caller\'s umask');
    }

    public function testAPreExistingLooseLogIsTightenedTo0600(): void
    {
        $file = $this->home . '/.sugar-crush/logs/sugarcrush.log';
        mkdir(\dirname($file), 0o700, true);
        file_put_contents($file, "earlier session\n");
        chmod($file, 0o644);

        self::assertSame($file, TuiErrorLog::install($this->home));
        clearstatcache();
        self::assertSame(0o600, fileperms($file) & 0o777);
        self::assertStringStartsWith("earlier session\n", (string) file_get_contents($file));
    }

    public function testAnOversizedLogIsRotatedToExactlyOneGeneration(): void
    {
        $file = $this->home . '/.sugar-crush/logs/sugarcrush.log';
        mkdir(\dirname($file), 0o700, true);
        file_put_contents($file . '.1', 'the generation before last');
        file_put_contents($file, str_repeat('x', TuiErrorLog::MAX_BYTES + 1));

        self::assertSame($file, TuiErrorLog::install($this->home));
        clearstatcache();

        self::assertSame(TuiErrorLog::MAX_BYTES + 1, filesize($file . '.1'), 'the oversized log did not become .1');
        self::assertFileDoesNotExist($file . '.2');
        self::assertLessThan(1024, filesize($file), 'the live log was not started fresh');
        self::assertSame(0o600, fileperms($file) & 0o777);
    }

    public function testALogAtTheCapIsAppendedToRatherThanRotated(): void
    {
        $file = $this->home . '/.sugar-crush/logs/sugarcrush.log';
        mkdir(\dirname($file), 0o700, true);
        file_put_contents($file, str_repeat('y', TuiErrorLog::MAX_BYTES));

        self::assertSame($file, TuiErrorLog::install($this->home));
        clearstatcache();

        self::assertFileDoesNotExist($file . '.1');
        self::assertGreaterThan(TuiErrorLog::MAX_BYTES, filesize($file));
    }

    /**
     * An operator who pointed `error_log` somewhere already kept it off the
     * tty; moving their log would only hide it from them.
     */
    public function testAnOperatorChosenDestinationIsLeftAlone(): void
    {
        $operatorLog = $this->home . '/operator.log';
        ini_set('error_log', $operatorLog);

        self::assertNull(TuiErrorLog::install($this->home));
        self::assertSame($operatorLog, ini_get('error_log'));
        self::assertDirectoryDoesNotExist($this->home . '/.sugar-crush');
    }

    #[DataProvider('stderrSpellings')]
    public function testEveryStderrSpellingIsReplaced(string $spelling): void
    {
        ini_set('error_log', $spelling);

        self::assertSame($this->home . '/.sugar-crush/logs/sugarcrush.log', TuiErrorLog::install($this->home));
    }

    /** @return array<string, array{string}> */
    public static function stderrSpellings(): array
    {
        return [
            'unset' => [''],
            '/dev/stderr' => ['/dev/stderr'],
            'php://stderr' => ['php://stderr'],
            '/dev/fd/2' => ['/dev/fd/2'],
            '/proc/self/fd/2' => ['/proc/self/fd/2'],
        ];
    }

    /**
     * Audit R16: no owned home is no longer "leave the ini on the tty" — the
     * chain moves on to the private temp-dir fallback.
     */
    public function testNoHomeFallsBackToAPrivateTempDirLog(): void
    {
        foreach ([null, ''] as $home) {
            ini_set('error_log', '');
            $path = TuiErrorLog::install($home, $this->tempDir());

            self::assertSame($this->fallbackFile(), $path);
            self::assertSame($path, ini_get('error_log'));
            self::assertSame(0o700, fileperms(\dirname($path)) & 0o777);
            self::assertSame(0o600, fileperms($path) & 0o777);
        }

        error_log('R16-FALLBACK-PROBE');
        self::assertStringContainsString('R16-FALLBACK-PROBE', (string) file_get_contents($this->fallbackFile()));
    }

    /**
     * A home whose log directory cannot be created must not fail the launch,
     * and (R16) must not leave `error_log` on the tty either. A regular FILE
     * where the home should be makes mkdir() fail even for root, which a
     * chmod would not.
     */
    public function testAnUncreatableLogDirectoryFallsBackToTheTempDir(): void
    {
        $notADirectory = $this->home . '/plain-file';
        file_put_contents($notADirectory, '');

        self::assertSame($this->fallbackFile(), TuiErrorLog::install($notADirectory, $this->tempDir()));
        self::assertSame($this->fallbackFile(), ini_get('error_log'));
    }

    public function testAWorldWritableLogDirectoryIsRefusedForTheFallback(): void
    {
        $dir = $this->home . '/.sugar-crush/logs';
        mkdir($dir, 0o700, true);
        chmod($dir, 0o777);

        self::assertSame($this->fallbackFile(), TuiErrorLog::install($this->home, $this->tempDir()));
        self::assertFileDoesNotExist($dir . '/sugarcrush.log');
    }

    public function testASymlinkedLogFileIsRefusedForTheFallback(): void
    {
        $dir = $this->home . '/.sugar-crush/logs';
        mkdir($dir, 0o700, true);
        $target = $this->home . '/elsewhere.log';
        file_put_contents($target, '');
        symlink($target, $dir . '/sugarcrush.log');

        self::assertSame($this->fallbackFile(), TuiErrorLog::install($this->home, $this->tempDir()));
        self::assertSame('', (string) file_get_contents($target), 'the symlink target was written through');
    }

    /**
     * The temp dir is shared, so a fallback directory left loose (here by
     * ourselves, the one case a test can stage without root) is tightened
     * before use rather than written into as found.
     */
    public function testALooseFallbackDirectoryOfOurOwnIsTightenedBeforeUse(): void
    {
        $dir = \dirname($this->fallbackFile());
        mkdir($dir, 0o755, true);
        chmod($dir, 0o755);

        self::assertSame($this->fallbackFile(), TuiErrorLog::install(null, $this->tempDir()));
        clearstatcache();
        self::assertSame(0o700, fileperms($dir) & 0o777);
    }

    /** A planted symlink in the shared temp dir is never followed. */
    public function testASymlinkedFallbackDirectoryIsRefused(): void
    {
        $elsewhere = $this->home . '/planted';
        mkdir($elsewhere, 0o700);
        mkdir($this->tempDir(), 0o700, true);
        symlink($elsewhere, \dirname($this->fallbackFile()));

        self::assertSame('/dev/null', TuiErrorLog::install(null, $this->tempDir()));
        self::assertFileDoesNotExist($elsewhere . '/' . TuiErrorLog::FALLBACK_FILE);
    }

    /**
     * R16's last resort: with neither the home nor the temp dir usable, the
     * destination is the null device — never the tty — and it is reported as
     * such, so no caller sends a reader to it.
     */
    public function testWithNoUsableFileTheDestinationIsTheNullDeviceNotTheTty(): void
    {
        $notADirectory = $this->home . '/plain-file';
        file_put_contents($notADirectory, '');

        $path = TuiErrorLog::install($notADirectory, $notADirectory);

        self::assertSame('/dev/null', $path);
        self::assertSame('/dev/null', ini_get('error_log'));
        self::assertTrue(TuiErrorLog::isNullDevice($path));
        self::assertFalse(TuiErrorLog::destinationIsStderr(ini_get('error_log')));
        self::assertFalse(TuiErrorLog::isNullDevice($this->fallbackFile()));
    }

    /**
     * The parsers' direct `error_log()` calls are the reason R16 exists:
     * measured on a real fd 2 in a child, a launch with no usable log file
     * writes nothing there after install().
     */
    public function testAfterTheLastResortADirectErrorLogCallLeavesFd2Empty(): void
    {
        $script = $this->home . '/child.php';
        file_put_contents($script, <<<'PHP'
            <?php
            declare(strict_types=1);
            require $argv[1];
            $path = \SugarCraft\Crush\Diagnostics\TuiErrorLog::install($argv[2], $argv[2]);
            error_log('R16-DIRECT-PROBE');
            fwrite(STDOUT, (string) $path);
            PHP);
        $notADirectory = $this->home . '/plain-file';
        file_put_contents($notADirectory, '');

        $command = [
            \PHP_BINARY, '-d', 'error_log=', '-d', 'log_errors=1', '-d', 'display_errors=0',
            $script, \dirname(__DIR__, 2) . '/vendor/autoload.php', $notADirectory,
        ];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);

        self::assertSame('/dev/null', $stdout);
        self::assertSame('', $stderr, 'a direct error_log() still reached fd 2');
    }

    #[DataProvider('described')]
    public function testDescribeDestinationNamesWhereTheFullTextWent(string|false $value, ?string $expected): void
    {
        $this->useHomeSandbox('/home/alice', create: false);

        self::assertSame($expected, TuiErrorLog::describeDestination($value));
    }

    /** @return array<string, array{string|false, string|null}> */
    public static function described(): array
    {
        return [
            'unset is stderr' => ['', 'stderr'],
            'ini missing is stderr' => [false, 'stderr'],
            '/dev/stderr' => ['/dev/stderr', 'stderr'],
            'the TUI log under the home is spelled with ~' => [
                '/home/alice/.sugar-crush/logs/sugarcrush.log',
                '~/.sugar-crush/logs/sugarcrush.log',
            ],
            'a sibling of the home is not abbreviated' => ['/home/alicebob/x.log', '/home/alicebob/x.log'],
            'the temp fallback' => ['/tmp/sugarcrush-1000/sugarcrush.log', '/tmp/sugarcrush-1000/sugarcrush.log'],
            'syslog' => ['syslog', 'the system log'],
            'the null device keeps nothing' => ['/dev/null', null],
            'an over-long path is named generically' => [
                '/var/log/' . str_repeat('d', TuiErrorLog::MAX_DESCRIBED_BYTES),
                'the error_log file',
            ],
            'a control character is named generically' => ["/tmp/a\x1b[2Jb.log", 'the error_log file'],
        ];
    }

    #[DataProvider('destinations')]
    public function testDestinationIsStderrRecognisesFd2AndNothingElse(string|false $value, bool $isStderr): void
    {
        self::assertSame($isStderr, TuiErrorLog::destinationIsStderr($value));
    }

    /** @return array<string, array{string|false, bool}> */
    public static function destinations(): array
    {
        return [
            'ini missing' => [false, true],
            'unset' => ['', true],
            'whitespace' => ['  ', true],
            '/dev/stderr' => ['/dev/stderr', true],
            'php://stderr' => ['php://stderr', true],
            '/dev/fd/2' => ['/dev/fd/2', true],
            'a file' => ['/var/log/php.log', false],
            'syslog' => ['syslog', false],
            'a stdout DEVICE is not stderr' => ['/dev/stdout', false],
            // The logger cannot open a stream-wrapper spelling and falls back
            // to the SAPI logger, i.e. fd 2 — measured, see destinationIsStderr().
            'php://stdout falls back to stderr' => ['php://stdout', true],
            'php:// is matched case-insensitively' => ['PHP://STDERR', true],
        ];
    }

    private function tempDir(): string
    {
        return $this->home . '/tmp';
    }

    private function fallbackFile(): string
    {
        return $this->tempDir() . '/' . TuiErrorLog::FALLBACK_DIR_PREFIX . posix_geteuid() . '/' . TuiErrorLog::FALLBACK_FILE;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        @chmod($path, 0o700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
