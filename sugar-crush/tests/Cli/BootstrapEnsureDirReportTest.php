<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Audit CLI-1, the emitter half: `Bootstrap::ensureDir()` reports a directory
 * it cannot create through its own exception, never through a PHP warning.
 *
 * The unsilenced `mkdir()` it used to make printed `Warning: mkdir():
 * Permission denied` wherever `display_errors` pointed, which on a php-cli with
 * no php.ini is STDOUT, ahead of the `--output-format json` document. The
 * exception was already the report, and `memoryStoreOrNull()` already
 * degrades on it, so the warning only ever duplicated it on the wrong channel.
 * The reason it carried now travels in the exception message.
 *
 * Reached through {@see Bootstrap::memoryStore()}, the public route to
 * `ensureDir()` whose target (`~/.sugar-crush/memory`) a test can make
 * uncreatable without touching anything outside a sandbox HOME.
 */
final class BootstrapEnsureDirReportTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir;

    /** @var list<string> every PHP diagnostic raised while a test ran, @-suppressed ones excluded */
    private array $diagnostics = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/bootstrap_ensure_dir_' . uniqid('', true);
        mkdir($this->tempDir . '/home/.sugar-crush', 0700, true);
        $this->useHomeSandbox($this->tempDir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();

        @chmod($this->tempDir . '/home/.sugar-crush', 0o700);
        @unlink($this->tempDir . '/home/.sugar-crush/memory');
        @rmdir($this->tempDir . '/home/.sugar-crush/memory');
        @rmdir($this->tempDir . '/home/.sugar-crush');
        @rmdir($this->tempDir . '/home');
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    public function testAnUnwritableParentIsReportedByTheExceptionAlone(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root creates a directory under a 0500 parent regardless of its mode.');
        }

        chmod($this->tempDir . '/home/.sugar-crush', 0o500);

        $message = $this->messageOfTheFailedStore();

        self::assertStringContainsString('Failed to create directory: ' . $this->tempDir . '/home/.sugar-crush/memory', $message);
        self::assertStringContainsString('Permission denied', $message, 'the reason the warning carried belongs in the message');
        self::assertSame([], $this->diagnostics, 'ensureDir() raised a PHP diagnostic as well as throwing');
    }

    /** A file squatting on the directory's name: the case root hits too. */
    public function testAFileWhereTheDirectoryBelongsIsReportedByTheExceptionAlone(): void
    {
        file_put_contents($this->tempDir . '/home/.sugar-crush/memory', 'not a directory');

        $message = $this->messageOfTheFailedStore();

        self::assertStringContainsString('Failed to create directory: ' . $this->tempDir . '/home/.sugar-crush/memory', $message);
        self::assertStringContainsString('File exists', $message);
        self::assertSame([], $this->diagnostics, 'ensureDir() raised a PHP diagnostic as well as throwing');
    }

    /** The silencing changes the channel, not which paths throw. */
    public function testACreatableDirectoryIsStillCreatedPrivatelyAndSilently(): void
    {
        $this->recordingDiagnostics(static fn () => Bootstrap::memoryStore());

        $dir = $this->tempDir . '/home/.sugar-crush/memory';
        self::assertDirectoryExists($dir);
        self::assertSame(0o700 & ~umask(), fileperms($dir) & 0o777);
        self::assertSame([], $this->diagnostics);
    }

    private function messageOfTheFailedStore(): string
    {
        try {
            $this->recordingDiagnostics(static fn () => Bootstrap::memoryStore());
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        self::fail('memoryStore() opened a store whose directory could not be created');
    }

    /**
     * Recorded rather than left to PHPUnit's failOnWarning, so the assertion
     * names what this test is about. error_reporting() is consulted the way
     * PHP's own display is: an `@` masks it out.
     *
     * Restored in a `finally` around the one call under test, not in
     * tearDown(): SwallowingCatchCensusTest pins every install whose restore
     * is not in its own function's `finally`, because a handler that outlives
     * its test suppresses warning-to-failure for every later test.
     */
    private function recordingDiagnostics(\Closure $call): mixed
    {
        set_error_handler(function (int $errno, string $message): bool {
            if ((error_reporting() & $errno) !== 0) {
                $this->diagnostics[] = $message;
            }

            return true;
        });
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
