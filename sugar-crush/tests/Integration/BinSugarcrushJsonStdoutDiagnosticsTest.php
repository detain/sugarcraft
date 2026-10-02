<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tests\Support\RunsWallClockBoundedChildTrait;

/**
 * Audit CLI-1, end to end: under `--output-format json` the document is the
 * only thing on stdout, even when PHP has a diagnostic to print.
 *
 * The repro, verbatim: `display_errors=1` (php-cli's compiled-in default when
 * no php.ini is loaded, so the normal case in the official php:*-cli images)
 * and a `~/.sugar-crush` the run cannot write into. Before the fix the
 * `mkdir()` in `Bootstrap::ensureDir()` printed `Warning: mkdir(): Permission
 * denied` on STDOUT ahead of `{"result":…}`, while the exit status still said
 * success. Two fixes close it, and this test needs both to be green:
 * `bin/sugarcrush` sends display_errors to stderr, and ensureDir() reports
 * through its exception alone (pinned on its own by
 * {@see \SugarCraft\Crush\Tests\Cli\BootstrapEnsureDirReportTest}; the
 * top-of-file ini_set() is pinned by source in
 * {@see BinSugarcrushTuiErrorLogTest}).
 *
 * Hermetic by construction: `env -i` keeps every provider variable of the
 * runner out of the child, so the offline echo provider answers, with no
 * network and no credentials.
 */
final class BinSugarcrushJsonStdoutDiagnosticsTest extends TestCase
{
    use RunsWallClockBoundedChildTrait;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/bin_json_stdout_diag_' . uniqid('', true);
        mkdir($this->tempDir . '/home/.sugar-crush', 0700, true);
        mkdir($this->tempDir . '/project', 0700, true);
    }

    protected function tearDown(): void
    {
        @chmod($this->tempDir . '/home/.sugar-crush', 0o700);

        if (is_dir($this->tempDir)) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                /** @var \SplFileInfo $entry */
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    public function testAReadOnlyConfigDirLeavesTheJsonDocumentAloneOnStdout(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes into a 0500 directory regardless of its mode.');
        }

        chmod($this->tempDir . '/home/.sugar-crush', 0o500);

        $outFile = $this->tempDir . '/stdout.txt';
        $errFile = $this->tempDir . '/stderr.txt';

        [$status] = $this->runWallClockBoundedChild(
            sprintf(
                'cd %s && env -i PATH=%s HOME=%s TMPDIR=%s',
                escapeshellarg($this->tempDir . '/project'),
                escapeshellarg((string) (getenv('PATH') ?: '/usr/bin:/bin')),
                escapeshellarg($this->tempDir . '/home'),
                escapeshellarg((string) (getenv('TMPDIR') ?: sys_get_temp_dir())),
            ),
            sprintf(
                '%s -d display_errors=1 %s -p %s --output-format json >%s 2>%s',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(\dirname(__DIR__, 2) . '/bin/sugarcrush'),
                escapeshellarg('say hi'),
                escapeshellarg($outFile),
                escapeshellarg($errFile),
            ),
        );

        $stdout = is_file($outFile) ? (string) file_get_contents($outFile) : '';
        $stderr = is_file($errFile) ? (string) file_get_contents($errFile) : '';

        self::assertNotSame(self::KILLED_BY_THE_BUDGET, $status, 'the one-shot run was killed by the wall-clock budget');

        // The run itself may succeed or fail; either way stdout is ONE line
        // holding ONE JSON document, and nothing else.
        self::assertStringEndsWith("\n", $stdout, 'stdout: ' . $stdout);
        self::assertSame(1, substr_count($stdout, "\n"), 'stdout carries more than the document: ' . $stdout);
        self::assertIsArray(json_decode($stdout, true), 'stdout is not a JSON document: ' . $stdout);
        self::assertStringNotContainsString('Warning', $stdout);
        self::assertStringNotContainsString('mkdir()', $stderr, 'ensureDir() still raises a PHP warning; its exception is the report');
    }
}
