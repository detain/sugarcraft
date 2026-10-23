<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use SugarCraft\Log\Level;
use SugarCraft\Log\Logger;
use SugarCraft\Log\PsrBridge;
use SugarCraft\Log\Hook\HookRegistry;

final class PsrBridgeTest extends TestCase
{
    private string $tempPath;
    private $tempFile;

    protected function setUp(): void
    {
        $this->tempPath = \sys_get_temp_dir() . '/sugar-log-psr-test-' . \uniqid() . '.log';
        $this->tempFile = \fopen($this->tempPath, 'w');
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->tempFile)) {
            \fclose($this->tempFile);
        }
        if (\file_exists($this->tempPath)) {
            \unlink($this->tempPath);
        }
    }

    public function testInfoLogsToWrappedLogger(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->info('hello psr');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('hello psr', $content);
        $this->assertStringContainsString('INF', $content);
    }

    public function testEmergencyMapsToFatal(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $this->expectException(\RuntimeException::class);
        try {
            $bridge->emergency('panic');
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('exit(1)');
        }
    }

    public function testDebugMapsToDebugLevel(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->debug('debug message');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('debug message', $content);
        $this->assertStringContainsString('DBG', $content);
    }

    public function testWarningMapsToWarnLevel(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->warning('careful');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('careful', $content);
        $this->assertStringContainsString('WRN', $content);
    }

    public function testErrorMapsToErrorLevel(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->error('oops');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('oops', $content);
        $this->assertStringContainsString('ERR', $content);
    }

    public function testPsrLevelStringAcceptedByLogMethod(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->log(LogLevel::INFO, 'via log method');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('via log method', $content);
    }

    public function testAlertMapsToFatal(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        try {
            $bridge->alert('alert message');
        } catch (\RuntimeException $e) {
            \fclose($this->tempFile);
            $content = \file_get_contents($this->tempPath);
            $this->assertStringContainsString('alert message', $content);
            $this->assertStringContainsString('FTL', $content);
            return;
        }
        $this->fail('Expected RuntimeException was not thrown');
    }

    public function testCriticalMapsToFatal(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        try {
            $bridge->critical('critical message');
        } catch (\RuntimeException $e) {
            \fclose($this->tempFile);
            $content = \file_get_contents($this->tempPath);
            $this->assertStringContainsString('critical message', $content);
            $this->assertStringContainsString('FTL', $content);
            return;
        }
        $this->fail('Expected RuntimeException was not thrown');
    }

    public function testNoticeMapsToInfoLevel(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->notice('notice message');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('notice message', $content);
        $this->assertStringContainsString('INF', $content);
    }

    public function testUnknownPsrLevelFallsBackToInfo(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        // 'madeupstring' doesn't match any PSR level - falls back to Level::Info
        $bridge->log('madeupstring', 'fallback message');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('fallback message', $content);
        $this->assertStringContainsString('INF', $content);
    }

    public function testImplementsPsrLoggerInterface(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        // The README promise: pass it anywhere a PSR-3 logger is expected.
        // Pre-fix this class did NOT implement the interface, so the typed
        // sink below would TypeError at the call.
        $this->assertInstanceOf(\Psr\Log\LoggerInterface::class, $bridge);
        $this->writeViaInterface($bridge);

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('typed sink', $content);
    }

    private function writeViaInterface(\Psr\Log\LoggerInterface $logger): void
    {
        $logger->warning('typed sink');
    }

    public function testLogAcceptsNonStringLevelWithoutTypeError(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        // PSR-3's log() leaves $level untyped; a hostile/sloppy caller must
        // still land on the Info fallback rather than raising.
        $bridge->log(123, 'numeric level');
        $bridge->log(null, 'null level');
        $bridge->log(['array'], 'array level');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('numeric level', $content);
        $this->assertStringContainsString('null level', $content);
        $this->assertStringContainsString('array level', $content);
    }

    public function testStringableLevelIsMappedByItsStringForm(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return LogLevel::ERROR;
            }
        };
        $bridge->log($stringable, 'from stringable');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('ERR', $content);
        $this->assertStringContainsString('from stringable', $content);
    }
}
