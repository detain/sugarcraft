<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\GitDriver;
use SugarCraft\Git\Tests\Support\NullGit;

/**
 * Pins for the CG-48 test-hygiene batch (candy-git-116, candy-git-123).
 *
 * The batch folded three copy-pasted inline GitDriver fixtures into one
 * inert NullGit base (each test overrides only what it asserts on), moved
 * the real-git fixture spawns off the shell onto argv vectors, and says so
 * in structural tests: a coverage pin that fails the moment the roster or
 * the shell-form regression appears again. The remaining ~190 shell sites
 * are known debt - the ratchet below holds the line so it can only shrink.
 */
final class TestHygieneTest extends TestCase
{
    /** Files CG-48 swept clean of shell-form git spawns and the nullSink shim. */
    private const CLEAN_FILES = [
        'tests/GitGuardTest.php',
        'tests/GpgSigningProbeIntegrationTest.php',
        'tests/Support/FilterNoticeChild.php',
        'tests/PharCompatTest.php',
        'tests/Concerns/GitSpawn.php',
    ];

    /**
     * Measured shell-form git fixture sites across tests/ after the CG-48
     * conversions (comment/docblock lines excluded). candy-git-123: dated
     * ceiling - conversion is a one-way door, the count may drop but never
     * climb; the remainder (19 files, POSIX-only setup shapes) rides the
     * follow-up wave.
     */
    private const SHELL_FORM_CEILING = 189;

    public function testNullGitCoversTheWholeDriverContract(): void
    {
        $interface = new \ReflectionClass(GitDriver::class);
        $null      = new \ReflectionClass(NullGit::class);
        $missing   = [];
        foreach ($interface->getMethods() as $method) {
            if (!$null->hasMethod($method->getName())) {
                $missing[] = $method->getName();
            }
        }

        self::assertSame([], $missing, 'NullGit must follow GitDriver: new interface methods need inert prototypes');
        self::assertInstanceOf(NullGit::class, new NullGit());
    }

    public function testConvertedFilesStayFreeOfShellFormGitSpawns(): void
    {
        $root = dirname(__DIR__);
        foreach (self::CLEAN_FILES as $relative) {
            $lines = file($root . '/' . $relative);
            self::assertIsArray($lines, "{$relative} vanished - the CG-48 sweep roster needs revisiting");
            foreach ($lines as $number => $line) {
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
                    continue; // prose about the old shape is allowed
                }
                if (preg_match('/(^|[^$\w>])(exec|shell_exec)\(\s*[\'"]git/', $line) === 1) {
                    self::fail("{$relative}:" . ($number + 1) . ' regressed to a shell-form git spawn (candy-git-123)');
                }
                self::assertStringNotContainsString('nullSink', $line, "{$relative} must not resurrect the nullSink shim");
            }
        }
    }

    public function testShellFormDebtOnlyEverShrinks(): void
    {
        $count = 0;
        $walk  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($walk as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            foreach (file((string) $file->getPathname()) ?: [] as $line) {
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
                    continue;
                }
                if (preg_match('/(^|[^$\w>])(exec|shell_exec)\(\s*[\'"]git/', $line) === 1) {
                    $count++;
                }
            }
        }

        self::assertLessThanOrEqual(
            self::SHELL_FORM_CEILING,
            $count,
            'shell-form git fixture spawns climbed past the CG-48 ratchet - new spawns must use the GitSpawn argv trait',
        );
        // Non-vacuity: the batch measurably reduced the debt from the 207
        // recorded when candy-git-123 was triaged; if this ever equals the
        // ceiling, that is the pre-CG-48 baseline and something crept back.
        self::assertLessThan(207, $count, 'CG-48 conversions were undone wholesale');
    }
}
