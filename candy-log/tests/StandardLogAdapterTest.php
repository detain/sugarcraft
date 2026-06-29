<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Log\Level;
use SugarCraft\Log\Logger;
use SugarCraft\Log\StandardLogAdapter;

/**
 * Tests for StandardLogAdapter.
 * Covers: print, printLn, fatal, panic, logger, forceLevel, and multi-arg stringification.
 */
final class StandardLogAdapterTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        $this->tempPath = \sys_get_temp_dir() . '/candy-log-sla-' . \uniqid() . '.log';
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

    public function testPrintIsFilteredByMinLevel(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        // Logger minLevel is Error, print uses Info
        $logger = Logger::new(stream: $stream, level: Level::Error, reportTimestamp: false);
        $adapter = new StandardLogAdapter($logger);

        $adapter->print('should not appear');

        \fclose($stream);
        $content = \file_get_contents($this->tempPath);
        // StandardLogAdapter::print routes through logger->log(), not logger->print()
        // So it IS filtered by minLevel
        $this->assertSame('', $content);
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
}
