<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The ONE launch warning in this project that provably cannot be migrated onto
 * the transcript seam, and — until round 42 (E78) — the one with nothing
 * standing behind it either way.
 *
 * `bin/sugarcrush` opens with an IIFE that hunts for `vendor/autoload.php`
 * across three candidates and, failing all three, writes
 * `sugarcrush: cannot find composer autoload.php` to stderr and exits 2. Every
 * other stderr write in this codebase was re-examined against
 * {@see \SugarCraft\Crush\Cli\Bootstrap::warnPermissionConfigInTranscript()};
 * this one is structurally exempt, because the seam is a static method on a
 * class the missing autoloader was supposed to supply and no Chat exists on
 * this path to hold a transcript row.
 *
 * "Structurally exempt" is a claim about behaviour, so it is asserted rather
 * than asserted-in-prose. What makes that cheap is that a checkout with no
 * `vendor/` is one `copy()` away: the script resolves every candidate against
 * its own `__DIR__`, so a copy at `<tmp>/bin/sugarcrush` looks for
 * `<tmp>/vendor/autoload.php` (twice, via the relative and absolute spellings)
 * and `/autoload.php`, and finds none of them.
 *
 * NOT A DUPLICATE OF {@see BinSugarcrushDispatchTest}: every case there runs
 * the real script out of the real checkout, where the autoloader is present by
 * construction and this branch is unreachable.
 */
final class BinSugarcrushAutoloadGuardTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        // The script's third candidate resolves to `/autoload.php` from a
        // two-deep temporary root. A box that really has one would send this
        // launch down the success path and the guard would never fire, so the
        // test says so instead of failing with a confusing diff.
        if (is_file('/autoload.php')) {
            self::markTestSkipped('this box has a root-level /autoload.php, which the third candidate finds');
        }

        $this->tmpDir = sys_get_temp_dir() . '/crush_autoload_guard_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/bin', 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (['/bin/sugarcrush', '/bin', ''] as $suffix) {
            $path = $this->tmpDir . $suffix;
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }

        parent::tearDown();
    }

    public function testACheckoutWithNoAutoloaderFailsOnStderrWithExitTwo(): void
    {
        [$status, $stdout, $stderr] = $this->runCopiedBinary();

        self::assertSame(2, $status, "expected exit 2; stderr was:\n" . $stderr);
        self::assertSame("sugarcrush: cannot find composer autoload.php\n", $stderr);
    }

    /**
     * STDOUT STAYS EMPTY, and that is the documented exception rather than an
     * oversight: this is the one exit that cannot honour the
     * `--output-format json` contract, because the class owning that document
     * shape ({@see \SugarCraft\Crush\Cli\NonInteractive}) is behind the
     * autoloader that is missing. Pinned so the exception stays a decision.
     */
    public function testTheGuardEmitsNothingOnStdoutEvenUnderOutputFormatJson(): void
    {
        [$status, $stdout] = $this->runCopiedBinary('--output-format json -p hi');

        self::assertSame(2, $status);
        self::assertSame('', $stdout);
    }

    /**
     * The guard must not be reachable from a checkout that DOES have a
     * `vendor/autoload.php` — otherwise the two tests above would pass against
     * a script whose candidate list had silently stopped working, and every
     * real install would print this line.
     */
    public function testTheGuardIsNotReachedWhenAnAutoloaderIsInPlace(): void
    {
        mkdir($this->tmpDir . '/vendor', 0o755, true);
        // Enough of an autoloader to satisfy `is_file()` and `require`; the
        // script's next statement is `use`, then ArgvParser, so it will fail
        // afterwards — the assertion is only that it failed SOMEWHERE ELSE.
        file_put_contents($this->tmpDir . '/vendor/autoload.php', "<?php\n");

        [, , $stderr] = $this->runCopiedBinary('--version');

        self::assertStringNotContainsString('cannot find composer autoload.php', $stderr);

        unlink($this->tmpDir . '/vendor/autoload.php');
        rmdir($this->tmpDir . '/vendor');
    }

    /**
     * @return array{0: int, 1: string, 2: string} exit status, stdout, stderr
     */
    private function runCopiedBinary(string $arguments = ''): array
    {
        $binary = $this->tmpDir . '/bin/sugarcrush';
        copy(dirname(__DIR__, 2) . '/bin/sugarcrush', $binary);

        $outFile = $this->tmpDir . '/out.txt';
        $errFile = $this->tmpDir . '/err.txt';

        exec(sprintf(
            'timeout -s KILL 60 %s %s %s >%s 2>%s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($binary),
            $arguments,
            escapeshellarg($outFile),
            escapeshellarg($errFile),
        ), $ignored, $status);

        $stdout = is_file($outFile) ? (string) file_get_contents($outFile) : '';
        $stderr = is_file($errFile) ? (string) file_get_contents($errFile) : '';

        foreach ([$outFile, $errFile, $binary] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        return [$status, $stdout, $stderr];
    }
}
