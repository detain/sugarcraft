<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Log\FatalLoggedException;
use SugarCraft\Log\Level;
use SugarCraft\Log\Logger;
use SugarCraft\Log\StandardLogAdapter;

/**
 * Tests for StandardLogAdapter.
 * Covers: print, printLn, fatal, panic, logger, forceLevel, multi-arg
 * stringification, and the printf family (printf/errorf/fatalf/panicf).
 */
final class StandardLogAdapterTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        $this->tempPath = \sys_get_temp_dir() . '/sugar-log-sla-' . \uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (\file_exists($this->tempPath)) {
            \unlink($this->tempPath);
        }
    }

    public function testPrintJoinsArgsWithSpace(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $logger = Logger::new(stream: $stream, level: Level::Debug, reportTimestamp: false);
        $adapter = new StandardLogAdapter($logger);

        $adapter->print('hello', 'world');

        \fclose($stream);
        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('hello world', $content);
    }

    public function testPrintEmitsAtInfoLevel(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $logger = Logger::new(stream: $stream, level: Level::Info, reportTimestamp: false);
        $adapter = new StandardLogAdapter($logger);

        $adapter->print('info level test');

        \fclose($stream);
        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('info level test', $content);
        $this->assertStringContainsString('INF', $content);
    }

    // [m7 flip] Go's stdlib logger writes unconditionally; the adapter's
    // print() now rides Logger::print() (bypasses the level filter) unless a
    // level ABOVE Info was forced. The old pin asserted the filtered shape.
    public function testPrintBypassesMinLevelLikeGoStdlib(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $logger = Logger::new(stream: $stream, level: Level::Error, reportTimestamp: false);
        $adapter = new StandardLogAdapter($logger);

        $adapter->print('always written');

        \fclose($stream);
        $content = (string) \file_get_contents($this->tempPath);
        $this->assertStringContainsString('always written', $content);
        $this->assertStringContainsString('INF', $content);
    }

    public function testForcedInfoAlsoBypassesWhileForcedWarnIsFiltered(): void
    {
        $infoPath = $this->tempPath . '.info';
        $warnPath = $this->tempPath . '.warn';
        $info = \fopen($infoPath, 'w');
        $warn = \fopen($warnPath, 'w');

        (new StandardLogAdapter(Logger::new(stream: $info, level: Level::Error, reportTimestamp: false), Level::Info))
            ->print('forced-info rides the bypass');
        (new StandardLogAdapter(Logger::new(stream: $warn, level: Level::Error, reportTimestamp: false), Level::Warn))
            ->print('forced-warn is filtered');

        \fclose($info);
        \fclose($warn);
        $this->assertStringContainsString('forced-info', (string) \file_get_contents($infoPath));
        $this->assertSame('', (string) \file_get_contents($warnPath));
        \unlink($infoPath);
        \unlink($warnPath);
    }

    public function testPrintfFormatsThenRidesThePrintBypass(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $logger = Logger::new(stream: $stream, level: Level::Error, reportTimestamp: false);
        $adapter = new StandardLogAdapter($logger);

        $adapter->printf('open %s in %dms', 'db', 12);

        \fclose($stream);
        $content = (string) \file_get_contents($this->tempPath);
        // vsprintf first (one message, not space-joined parts), then print()'s
        // un-forced bypass: written despite the Error minimum, INF label.
        $this->assertStringContainsString('open db in 12ms', $content);
        $this->assertStringContainsString('INF', $content);
    }

    public function testPrintfFollowsTheForcedFilterLikePrint(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $logger = Logger::new(stream: $stream, level: Level::Error, reportTimestamp: false);
        $adapter = new StandardLogAdapter($logger, Level::Warn);

        $adapter->printf('disk %d%% full', 92);

        \fclose($stream);
        // Forced above Info rides the filter: the Error minimum drops the WRN.
        $this->assertSame('', (string) \file_get_contents($this->tempPath));
    }

    public function testErrorfWritesAFilteredErrorRecord(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $logger = Logger::new(stream: $stream, level: Level::Warn, reportTimestamp: false);
        $adapter = new StandardLogAdapter($logger);

        $adapter->errorf('bad %s', 'input');

        \fclose($stream);
        $content = (string) \file_get_contents($this->tempPath);
        $this->assertStringContainsString('ERR', $content);
        $this->assertStringContainsString('bad input', $content);
    }

    public function testFatalfWritesThenThrowsTypedException(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $logger = Logger::new(stream: $stream, level: Level::Debug, reportTimestamp: false);
        $adapter = new StandardLogAdapter($logger);

        try {
            $adapter->fatalf('listen %s failed', ':80');
            $this->fail('fatalf() must throw after writing the record');
        } catch (FatalLoggedException $e) {
            $this->assertStringContainsString('listen :80 failed', $e->getMessage());
        }

        \fclose($stream);
        $content = (string) \file_get_contents($this->tempPath);
        $this->assertStringContainsString('FTL', $content);
        $this->assertStringContainsString('listen :80 failed', $content);
    }

    public function testPanicfIsTheAliasArmOfFatalf(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $logger = Logger::new(stream: $stream, level: Level::Debug, reportTimestamp: false);
        $adapter = new StandardLogAdapter($logger);

        try {
            $adapter->panicf('boom %d', 7);
            $this->fail('panicf() must throw — the port collapses panic into fatal');
        } catch (FatalLoggedException $e) {
            $this->assertStringContainsString('boom 7', $e->getMessage());
        }

        \fclose($stream);
        $content = (string) \file_get_contents($this->tempPath);
        $this->assertStringContainsString('FTL', $content);
        $this->assertStringContainsString('boom 7', $content);
    }

    public function testPrintLnDelegatesToPrint(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $logger = Logger::new(stream: $stream, level: Level::Debug, reportTimestamp: false);
        $adapter = new StandardLogAdapter($logger);

        $adapter->printLn('hello', 'world');

        \fclose($stream);
        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('hello world', $content);
    }

    public function testForceLevelMakesPrintEmitAtForcedLevel(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $logger = Logger::new(stream: $stream, level: Level::Error, reportTimestamp: false);
        $adapter = new StandardLogAdapter($logger, Level::Error);

        // With forceLevel: Error, print should emit even though minLevel is Error
        // But actually StandardLogAdapter::print uses logger->log() at forceLevel
        // which goes through the level filter... so with minLevel=Error and forceLevel=Error,
        // it should appear
        $adapter->print('forced error level');

        \fclose($stream);
        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('forced error level', $content);
        $this->assertStringContainsString('ERR', $content);
    }

    public function testLoggerReturnsWrappedInstance(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $logger = Logger::new(stream: $stream, level: Level::Debug, reportTimestamp: false);
        $adapter = new StandardLogAdapter($logger);

        $this->assertSame($logger, $adapter->logger());
    }

    public function testFatalThrowsRuntimeException(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $logger = Logger::new(stream: $stream, level: Level::Debug, reportTimestamp: false);
        $adapter = new StandardLogAdapter($logger);

        $this->expectException(\RuntimeException::class);
        $adapter->fatal('fatal error');
    }

    public function testFatalLogsBeforeThrowing(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $logger = Logger::new(stream: $stream, level: Level::Debug, reportTimestamp: false);
        $adapter = new StandardLogAdapter($logger);

        try {
            $adapter->fatal('fatal error');
        } catch (\RuntimeException $e) {
            // Expected - verify it logged before throwing
            \fclose($stream);
            $content = \file_get_contents($this->tempPath);
            $this->assertStringContainsString('fatal error', $content);
            $this->assertStringContainsString('FTL', $content);
            return;
        }
        $this->fail('Expected RuntimeException was not thrown');
    }

    public function testPanicThrowsRuntimeException(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $logger = Logger::new(stream: $stream, level: Level::Debug, reportTimestamp: false);
        $adapter = new StandardLogAdapter($logger);

        $this->expectException(\RuntimeException::class);
        $adapter->panic('panic error');
    }

    public function testPanicLogsBeforeThrowing(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $logger = Logger::new(stream: $stream, level: Level::Debug, reportTimestamp: false);
        $adapter = new StandardLogAdapter($logger);

        try {
            $adapter->panic('panic error');
        } catch (\RuntimeException $e) {
            \fclose($stream);
            $content = \file_get_contents($this->tempPath);
            $this->assertStringContainsString('panic error', $content);
            $this->assertStringContainsString('FTL', $content);
            return;
        }
        $this->fail('Expected RuntimeException was not thrown');
    }

    public function testMultiArgStringification(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $logger = Logger::new(stream: $stream, level: Level::Debug, reportTimestamp: false);
        $adapter = new StandardLogAdapter($logger);

        // Pass an int and a string - should be stringified and joined with space
        $adapter->print('count:', 42, 'status:', 'ok');

        \fclose($stream);
        $content = \file_get_contents($this->tempPath);
        // All args joined with space
        $this->assertStringContainsString('count: 42 status: ok', $content);
    }

    public function testArrayArgsCoercionNotWarningNotLiteralArray(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $logger = Logger::new(stream: $stream, level: Level::Debug, reportTimestamp: false);
        $adapter = new StandardLogAdapter($logger);

        // Any PHP warning becomes a test failure: the old (string) cast on an
        // array arg emitted 'Array to string conversion' and logged the
        // literal 'Array'.
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            $adapter->print('ids:', [1, 2], 'flag:', true, 'none:', null);
        } finally {
            restore_error_handler();
        }

        \fclose($stream);
        $content = (string) \file_get_contents($this->tempPath);
        $this->assertStringContainsString('ids: [1 2] flag: true none: null', $content);
        $this->assertStringNotContainsString('Array', $content);
    }
}
