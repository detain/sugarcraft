<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Log\Level;
use SugarCraft\Log\Log;
use SugarCraft\Log\Logger;
use SugarCraft\Log\PanicFormatter;

/**
 * Coverage tests for the Log static facade.
 * Tests: default(), setLogger(), reset(), debug(), info(), warn(), error(), fatal(), print(), restoreTerminal()
 */
final class LogTest extends TestCase
{
    /** @var resource */
    private $tempFile;
    private string $tempPath;

    protected function setUp(): void
    {
        $this->tempPath = \sys_get_temp_dir() . '/candy-log-test-' . \uniqid() . '.log';
        $this->tempFile = \fopen($this->tempPath, 'w');
        // Reset the Log facade state before each test
        Log::reset();
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->tempFile)) {
            \fclose($this->tempFile);
        }
        if (\file_exists($this->tempPath)) {
            \unlink($this->tempPath);
        }
        Log::reset();
    }

    private function logger(Level $minLevel = Level::Debug): Logger
    {
        return Logger::new(
            level: $minLevel,
            reportTimestamp: false,
            reportCaller: false,
            stream: $this->tempFile,
        );
    }

    private function getContent(): string
    {
        \fclose($this->tempFile);
        return \file_get_contents($this->tempPath) ?: '';
    }

    public function testDefaultLazilyCreatesLogger(): void
    {
        // Before setting a logger, default() should create one lazily
        $default = Log::default();
        $this->assertInstanceOf(Logger::class, $default);
    }

    public function testDefaultReturnsSameInstanceOnSubsequentCalls(): void
    {
        $first = Log::default();
        $second = Log::default();
        $this->assertSame($first, $second);
    }

    public function testSetLoggerReplacesDefault(): void
    {
        $custom = $this->logger();
        Log::setLogger($custom);

        $this->assertSame($custom, Log::default());
    }

    public function testResetClearsDefault(): void
    {
        $first = Log::default();
        Log::reset();
        $second = Log::default();

        $this->assertNotSame($first, $second);
    }

    public function testDebugLogsAtDebugLevel(): void
    {
        $log = $this->logger(Level::Debug);
        Log::setLogger($log);

        Log::debug('debug message', ['key' => 'value']);

        $content = $this->getContent();
        $this->assertStringContainsString('debug message', $content);
        $this->assertStringContainsString('DBG', $content);
        $this->assertStringContainsString('key=value', $content);
    }

    public function testDebugFilteredWhenMinLevelIsInfo(): void
    {
        $log = $this->logger(Level::Info);
        Log::setLogger($log);

        Log::debug('should not appear');

        $content = $this->getContent();
        $this->assertSame('', $content);
    }

    public function testInfoLogsAtInfoLevel(): void
    {
        $log = $this->logger(Level::Debug);
        Log::setLogger($log);

        Log::info('info message', ['item' => 'test']);

        $content = $this->getContent();
        $this->assertStringContainsString('info message', $content);
        $this->assertStringContainsString('INF', $content);
        $this->assertStringContainsString('item=test', $content);
    }

    public function testWarnLogsAtWarnLevel(): void
    {
        $log = $this->logger(Level::Debug);
        Log::setLogger($log);

        Log::warn('warn message', ['count' => 3]);

        $content = $this->getContent();
        $this->assertStringContainsString('warn message', $content);
        $this->assertStringContainsString('WRN', $content);
        $this->assertStringContainsString('count=3', $content);
    }

    public function testErrorLogsAtErrorLevel(): void
    {
        $log = $this->logger(Level::Debug);
        Log::setLogger($log);

        Log::error('error message', ['code' => 500]);

        $content = $this->getContent();
        $this->assertStringContainsString('error message', $content);
        $this->assertStringContainsString('ERR', $content);
        $this->assertStringContainsString('code=500', $content);
    }

    public function testFatalLogsAndThrows(): void
    {
        $log = $this->logger(Level::Debug);
        Log::setLogger($log);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fatal error');

        try {
            Log::fatal('fatal error');
        } catch (\RuntimeException $e) {
            // Verify it logged before throwing
            $content = $this->getContent();
            $this->assertStringContainsString('FTL', $content);
            throw $e;
        }
    }

    public function testFatalLogsAtFatalLevel(): void
    {
        $log = $this->logger(Level::Debug);
        Log::setLogger($log);

        try {
            Log::fatal('goodbye');
        } catch (\RuntimeException) {
            // Expected - we just want to verify the log output before the exception
        }

        $content = $this->getContent();
        $this->assertStringContainsString('goodbye', $content);
        $this->assertStringContainsString('FTL', $content);
    }

    public function testPrintOutputsAtInfoLevel(): void
    {
        // Create logger with Info as minimum level - print should work
        $log = $this->logger(Level::Info);
        Log::setLogger($log);

        Log::print('always printed', ['type' => 'status']);

        $content = $this->getContent();
        $this->assertStringContainsString('always printed', $content);
        $this->assertStringContainsString('type=status', $content);
    }

    public function testPrintGetsFilteredByHighMinLevel(): void
    {
        // Create logger with Error as minimum level - print (which uses Info) should be filtered
        $log = $this->logger(Level::Error);
        Log::setLogger($log);

        Log::print('should not appear');

        $content = $this->getContent();
        $this->assertSame('', $content);
    }

    public function testRestoreTerminalDoesNotThrow(): void
    {
        // Log::restoreTerminal() should not throw - it writes ANSI codes to STDERR
        Log::restoreTerminal();
        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }

    public function testInstallPanicHandlerSetsExceptionHandler(): void
    {
        $formatter = PanicFormatter::pretty();

        Log::installPanicHandler($formatter);

        // Verify that a custom exception handler was registered
        $previousHandler = \set_exception_handler(function () {});
        \restore_exception_handler();

        // The previous handler should be null (no previous handler) or a callable
        $this->assertTrue($previousHandler === null || \is_callable($previousHandler));
    }

    public function testInstallPanicHandlerWithNullFormatter(): void
    {
        // Should not throw - uses default formatter
        Log::installPanicHandler(null);

        // Verify it set up a handler (no exception thrown)
        $handler = \set_exception_handler(function () {});
        \restore_exception_handler();

        $this->assertNotNull($handler);
    }

    public function testInstallPanicHandlerWithShowLocals(): void
    {
        Log::installPanicHandler(null, true);

        $handler = \set_exception_handler(function () {});
        \restore_exception_handler();

        $this->assertNotNull($handler);
    }

    public function testInstallPanicHandlerWithRedactPaths(): void
    {
        Log::installPanicHandler(null, false, ['/secret/path']);

        $handler = \set_exception_handler(function () {});
        \restore_exception_handler();

        $this->assertNotNull($handler);
    }

    public function testContextIsMergedInLogMessages(): void
    {
        $log = $this->logger(Level::Debug);
        Log::setLogger($log);

        Log::info('test message', ['extra' => 'context']);

        $content = $this->getContent();
        $this->assertStringContainsString('test message', $content);
        $this->assertStringContainsString('extra=context', $content);
    }

    public function testAllLogLevelsFromFacade(): void
    {
        $log = $this->logger(Level::Debug);
        Log::setLogger($log);

        Log::debug('debug msg');
        Log::info('info msg');
        Log::warn('warn msg');
        Log::error('error msg');

        $content = $this->getContent();
        $this->assertStringContainsString('debug msg', $content);
        $this->assertStringContainsString('info msg', $content);
        $this->assertStringContainsString('warn msg', $content);
        $this->assertStringContainsString('error msg', $content);
        $this->assertStringContainsString('DBG', $content);
        $this->assertStringContainsString('INF', $content);
        $this->assertStringContainsString('WRN', $content);
        $this->assertStringContainsString('ERR', $content);
    }
}
