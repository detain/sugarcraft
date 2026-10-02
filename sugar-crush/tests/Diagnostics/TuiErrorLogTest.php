<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Diagnostics;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Diagnostics\TuiErrorLog;

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

    public function testNoHomeLeavesTheIniUntouched(): void
    {
        self::assertNull(TuiErrorLog::install(null));
        self::assertNull(TuiErrorLog::install(''));
        self::assertSame('', ini_get('error_log'));
    }

    /**
     * A home whose log directory cannot be created must not fail the launch:
     * null, and the ini exactly as it was. A regular FILE where the home
     * should be makes mkdir() fail even for root, which a chmod would not.
     */
    public function testAnUncreatableLogDirectoryReturnsNullAndLeavesTheIniUntouched(): void
    {
        $notADirectory = $this->home . '/plain-file';
        file_put_contents($notADirectory, '');

        self::assertNull(TuiErrorLog::install($notADirectory));
        self::assertSame('', ini_get('error_log'));
    }

    public function testAWorldWritableLogDirectoryIsRefused(): void
    {
        $dir = $this->home . '/.sugar-crush/logs';
        mkdir($dir, 0o700, true);
        chmod($dir, 0o777);

        self::assertNull(TuiErrorLog::install($this->home));
        self::assertSame('', ini_get('error_log'));
        self::assertFileDoesNotExist($dir . '/sugarcrush.log');
    }

    public function testASymlinkedLogFileIsRefused(): void
    {
        $dir = $this->home . '/.sugar-crush/logs';
        mkdir($dir, 0o700, true);
        $target = $this->home . '/elsewhere.log';
        file_put_contents($target, '');
        symlink($target, $dir . '/sugarcrush.log');

        self::assertNull(TuiErrorLog::install($this->home));
        self::assertSame('', ini_get('error_log'));
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
